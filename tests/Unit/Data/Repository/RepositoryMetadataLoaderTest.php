<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Repository;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\BasePackage;
use Composer\Repository\ArrayRepository;
use Composer\Repository\ComposerRepository;
use Lockrot\Clock;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class RepositoryMetadataLoaderTest extends TestCase
{
    public const CHUNK_FAILURE = 'the file for this package could not be read';

    private const WALLABAG_LOCK = __DIR__.'/../../../fixtures/apps/wallabag_wallabag/composer.lock';
    private const MATOMO_LOCK = __DIR__.'/../../../fixtures/apps/matomo-org_matomo/composer.lock';
    private const FIXED = '2026-09-14T00:00:00+00:00';

    private static ?FixtureRepositoryServer $server = null;
    private static ?RepositoryMetadataLoader $loader = null;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::removeTree($dir);
        }
        $this->tempDirs = [];
    }

    public static function setUpBeforeClass(): void
    {
        self::$server = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK, self::MATOMO_LOCK]);
        self::$server->start();
        // One loader (and so one HttpDownloader/curl-multi handle) shared across every test in this
        // class: repeatedly building a fresh RepositoryManager against the same long-lived php -S
        // process leaves stale connections behind that eventually wedge the server. Reusing a single
        // loader avoids that and matches how a real analyzer run uses it (one instance queried many
        // times).
        self::$loader = new RepositoryMetadataLoader(self::$server->repositories(), Clock::fixed(self::FIXED));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            self::$server->stop();
            self::$server = null;
        }
        self::$loader = null;
    }

    private function loader(): RepositoryMetadataLoader
    {
        $loader = self::$loader;
        self::assertNotNull($loader);

        return $loader;
    }

    /** @return list<string> */
    private function wallabagNames(): array
    {
        $lock = LockFile::fromFile(self::WALLABAG_LOCK);
        $names = [];
        foreach ($lock->packages(false) as $package) {
            $names[] = $package->name();
        }

        return $names;
    }

    public function testPhpzipIsFoundWithStableRelease(): void
    {
        $batch = $this->loader()->load(['phpzip/phpzip']);

        $meta = $batch->metadata()['phpzip/phpzip'] ?? null;
        self::assertNotNull($meta);
        self::assertTrue($meta->hasStableRelease());
        self::assertSame('2.0.8', $meta->lastStableVersion());
        $at = $meta->lastStableReleaseAt();
        self::assertNotNull($at);
        self::assertSame('2015-11-16', $at->format('Y-m-d'));
        self::assertFalse($meta->isAbandoned());
    }

    public function testTaglessPackageIsLoadedFromTheDevFileOnly(): void
    {
        // lox/xhprof has no tagged release at all, so the loader's pass 1 (stable stabilities)
        // finds nothing for it and it is resolved entirely from {name}~dev.json in pass 2.
        $batch = $this->loader()->load(['lox/xhprof']);

        $meta = $batch->metadata()['lox/xhprof'] ?? null;
        self::assertNotNull($meta);
        self::assertTrue($meta->isAbandoned());
        self::assertFalse($meta->hasStableRelease());
    }

    /**
     * A single pass over ALL_STABILITIES makes Composer fetch both {name}.json and {name}~dev.json
     * for every name: 403 requests for these 201 names. The two-pass loader instead fetches the
     * stable file for every name once, and the dev file only for names with no tagged release — 206
     * requests for the same 201 names (1 packages.json + 201 stable files + 4 dev-only files).
     */
    public function testFullWallabagLoadRequestsOneFilePerNamePlusDevForTaglessNames(): void
    {
        // Dedicated server, never the shared class-level self::$server: counting requests against
        // a server other tests have already been hitting would not reflect this load alone.
        $server = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK, self::MATOMO_LOCK]);
        $server->start();
        try {
            $names = array_merge($this->wallabagNames(), ['lox/xhprof']);
            $loader = new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::FIXED));
            $server->resetRequestCount();

            $batch = $loader->load($names);

            foreach ($names as $name) {
                self::assertArrayHasKey($name, $batch->metadata(), $name.' should have resolved');
            }
            self::assertSame([], $batch->failed());

            $requestCount = $server->requestCount();
            self::assertGreaterThanOrEqual(\count($names), $requestCount);
            // +1 packages.json, +4 ~dev files for the tagless names (wallabag/rulerz,
            // wallabag/rulerz-bridge, wallabag/rulerz-bundle, lox/xhprof), +2 slack.
            self::assertLessThanOrEqual(\count($names) + 1 + 4 + 2, $requestCount);
        } finally {
            $server->stop();
        }
    }

    public function testWallabagRulerzHasNoStableRelease(): void
    {
        $batch = $this->loader()->load(['wallabag/rulerz']);

        $meta = $batch->metadata()['wallabag/rulerz'] ?? null;
        self::assertNotNull($meta);
        self::assertFalse($meta->hasStableRelease());
    }

    public function testNonexistentPackageIsNotFoundAndNotFailed(): void
    {
        // The absent name is listed twice and sits ahead of a name that does resolve: duplicates
        // are collapsed before anything is queried, and one unresolved name in a chunk must not
        // stop the rest of that chunk.
        $batch = $this->loader()->load(['nonexistent/zzz', 'nonexistent/zzz', 'phpzip/phpzip']);

        self::assertSame(['nonexistent/zzz'], $batch->notFound());
        self::assertSame([], $batch->failed());
        self::assertArrayHasKey('phpzip/phpzip', $batch->metadata());
    }

    /**
     * Only a ComposerRepository can be queried by name, so a path or vcs repository in the list has
     * to be stepped over rather than end the walk. The second repository here was never started,
     * so every name comes back failed — which can only happen if the walk reached it at all.
     */
    public function testARepositoryThatIsNotAComposerRepositoryIsSteppedOver(): void
    {
        $unreachable = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $loader = new RepositoryMetadataLoader(
            array_merge([new ArrayRepository()], $unreachable->repositories()),
            Clock::fixed(self::FIXED)
        );

        $batch = $loader->load(['phpzip/phpzip']);

        self::assertSame([], $batch->notFound());
        self::assertSame([], $batch->metadata());
        self::assertArrayHasKey('phpzip/phpzip', $batch->failed());

        $unreachable->stop();
    }

    /** Every repository adds to what the earlier ones resolved; none of them replaces it. */
    public function testEachRepositoryAddsToWhatTheEarlierOnesResolved(): void
    {
        $first = $this->syntheticServer(['one/pkg' => [$this->p2Version('one/pkg', '1.0.0')]]);
        $second = $this->syntheticServer(['two/pkg' => [$this->p2Version('two/pkg', '2.0.0')]]);
        $first->start();
        $second->start();
        try {
            $loader = new RepositoryMetadataLoader(
                array_merge($first->repositories(), $second->repositories()),
                Clock::fixed(self::FIXED)
            );

            $batch = $loader->load(['one/pkg', 'two/pkg']);

            self::assertSame([], $batch->failed());
            self::assertSame([], $batch->notFound());
            self::assertArrayHasKey('one/pkg', $batch->metadata(), 'resolved by the first repository');
            self::assertArrayHasKey('two/pkg', $batch->metadata(), 'resolved by the second');
        } finally {
            $first->stop();
            $second->stop();
        }
    }

    public function testTwentyFiveNamesAllResolveAcrossChunkedCalls(): void
    {
        $names = \array_slice($this->wallabagNames(), 0, 25);

        $batch = $this->loader()->load($names);

        foreach ($names as $name) {
            self::assertArrayHasKey($name, $batch->metadata(), $name.' should have resolved');
        }
        self::assertSame([], $batch->failed());
    }

    /**
     * Regression test for a real hang: php -S writes one access-log line per request to stderr, and
     * Symfony\Process only drains that pipe while something calls back into the Process object
     * (which the startup poll loop does, but nothing does afterward). A single ~200-package load is
     * already ~1000 requests; three of them back-to-back through the one shared loader/server this
     * class builds in setUpBeforeClass() is ~3000 requests, which filled the pipe and wedged the
     * server well before completing. FixtureRepositoryServer::start() redirects the child's
     * stdout/stderr to a file instead of a pipe, which removes the pipe to fill in the first place.
     */
    public function testThreeConsecutiveFullWallabagLoadsThroughSharedServerAllResolve(): void
    {
        $names = $this->wallabagNames();

        for ($i = 1; $i <= 3; ++$i) {
            $batch = $this->loader()->load($names);

            self::assertCount(\count($names), $batch->metadata(), 'load #'.$i.' should resolve every name');
            self::assertSame([], $batch->failed(), 'load #'.$i.' should have no failures');
        }
    }

    // memory_get_peak_usage(true) is a whole-process high-water mark that PHPUnit never resets
    // between tests, so this assertion is order-dependent on whatever else already ran earlier in
    // the same process (same root cause as AcceptanceTest::testWallabag's identical isolation).
    // Running this one test in its own process makes the peak reflect only this load again; that
    // process never runs setUpBeforeClass(), so this test builds its own single-use server instead
    // of reaching for the class-level self::$server/self::$loader.
    /** @runInSeparateProcess */
    #[RunInSeparateProcess]
    public function testFullWallabagListResolvesUnderMemoryBudget(): void
    {
        $names = $this->wallabagNames();
        $server = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $server->start();

        try {
            $loader = new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::FIXED));
            $batch = $loader->load($names);

            self::assertCount(\count($names), $batch->metadata());
            self::assertLessThan(64 * 1024 * 1024, memory_get_peak_usage(true));
        } finally {
            $server->stop();
        }
    }

    public function testUnreachableServerWithEmptyCacheMarksEveryNameFailed(): void
    {
        // A freshly constructed, never-started server: its port is not bound and its cache dir is
        // empty, so every request genuinely fails instead of being served from a warm cache.
        $server = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $loader = new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::FIXED));

        $batch = $loader->load(['phpzip/phpzip', 'wallabag/rulerz']);

        self::assertSame([], $batch->notFound());
        self::assertSame([], $batch->metadata());
        foreach (['phpzip/phpzip', 'wallabag/rulerz'] as $name) {
            self::assertArrayHasKey($name, $batch->failed());
            self::assertNotSame('', $batch->failed()[$name]);
        }

        $server->stop();
    }

    public function testNameFoundWithNoVersionsIsTreatedAsFailed(): void
    {
        // A repository whose only envelopes for this name each have an entry (so Composer marks
        // the name as "found") but an empty version list — e.g. every version filtered out, or the
        // p2 entry itself is empty. There is nothing to build a PackageMetadata from, so this must
        // land in failed(), not metadata() or notFound(). Both the stable and the ~dev file are
        // empty here (a package registered with no releases and no branches at all), so the loader
        // reaches this outcome from pass 2 after pass 1's stable file routes it to $needDev.
        $dir = sys_get_temp_dir().'/lockrot-empty-versions-'.uniqid('', true);
        $envelopeDir = $dir.'/envelopes';
        self::assertNotFalse(mkdir($envelopeDir, 0777, true));

        $lockPath = $dir.'/composer.lock';
        file_put_contents($lockPath, (string) json_encode([
            'packages' => [['name' => 'test/empty-versions', 'version' => '1.0.0']],
        ]));

        $urls = [
            'https://repo.packagist.org/p2/test/empty-versions.json',
            'https://repo.packagist.org/p2/test/empty-versions~dev.json',
        ];
        foreach ($urls as $url) {
            file_put_contents(RecordedHttpClient::pathFor($envelopeDir, $url), (string) json_encode([
                'status' => 200,
                'fetched_at' => self::FIXED,
                'body' => json_encode(['packages' => ['test/empty-versions' => []]]),
                'error' => null,
            ]));
        }

        $server = FixtureRepositoryServer::fromLockFiles([$lockPath], $envelopeDir);
        $server->start();
        $loader = new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::FIXED));

        $batch = $loader->load(['test/empty-versions']);

        self::assertSame([], $batch->notFound());
        self::assertSame([], $batch->metadata());
        self::assertSame(
            ['test/empty-versions' => 'repository listed the package but returned no versions'],
            $batch->failed()
        );

        $server->stop();
        foreach ($urls as $url) {
            unlink(RecordedHttpClient::pathFor($envelopeDir, $url));
        }
        rmdir($envelopeDir);
        unlink($lockPath);
        rmdir($dir);
    }

    public function testOfflineWithColdCacheFailsEveryNameWithNetworkDisabledReason(): void
    {
        // Warm just the repository's root metadata online first (any successful load does that),
        // but never touch the two names queried below — those stay cold at the per-package level.
        // Composer\Repository\ComposerRepository::asyncFetchFile() (used for the per-package p2
        // file, unlike the root file's own synchronous fetch) turns the "network disabled" transport
        // error into a synthetic 404 when it has no last-modified date for that file to revalidate
        // against, so these come back as plain `notFound` from Composer's own API — not trustworthy
        // while offline, hence the loader's own reclassification into failed().
        $server = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $server->start();
        try {
            $onlineLoader = new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::FIXED));
            $onlineLoader->load(['ralouphie/getallheaders']);

            putenv('COMPOSER_DISABLE_NETWORK=1');
            try {
                $loader = new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::FIXED), true);

                $batch = $loader->load(['phpzip/phpzip', 'lox/xhprof']);

                self::assertSame([], $batch->metadata());
                self::assertSame([], $batch->notFound());
                foreach (['phpzip/phpzip', 'lox/xhprof'] as $name) {
                    self::assertSame(MetadataLoaderInterface::OFFLINE_NOT_FOUND_REASON, $batch->failed()[$name] ?? null);
                }
            } finally {
                putenv('COMPOSER_DISABLE_NETWORK');
            }
        } finally {
            $server->stop();
        }
    }

    public function testOfflineWithWarmCacheResolvesFromCache(): void
    {
        // Warm Composer's on-disk cache with one real (online) load against the running fixture
        // server first, then repeat the same load offline against a fresh loader built from the same
        // cache-dir config: ComposerRepository::asyncFetchFile() sees a last-modified date from the
        // cached copy and fakes a 304, so this must resolve from cache rather than fail or notFound.
        $server = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $server->start();
        try {
            $onlineLoader = new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::FIXED));
            $warm = $onlineLoader->load(['phpzip/phpzip']);
            self::assertArrayHasKey('phpzip/phpzip', $warm->metadata());

            putenv('COMPOSER_DISABLE_NETWORK=1');
            try {
                $offlineLoader = new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::FIXED), true);
                $batch = $offlineLoader->load(['phpzip/phpzip']);

                self::assertSame([], $batch->failed());
                self::assertSame([], $batch->notFound());
                self::assertArrayHasKey('phpzip/phpzip', $batch->metadata());
            } finally {
                putenv('COMPOSER_DISABLE_NETWORK');
            }
        } finally {
            $server->stop();
        }
    }

    public function testNoRepositoriesMeansEveryNameIsNotFound(): void
    {
        $loader = new RepositoryMetadataLoader([], Clock::fixed(self::FIXED));

        $batch = $loader->load(['a/b', 'c/d']);

        self::assertSame(['a/b', 'c/d'], $batch->notFound());
        self::assertSame([], $batch->failed());
        self::assertSame([], $batch->metadata());
    }

    public function testDataDateEqualsTheFixedClock(): void
    {
        $batch = $this->loader()->load(['phpzip/phpzip']);

        $meta = $batch->metadata()['phpzip/phpzip'] ?? null;
        self::assertNotNull($meta);
        self::assertSame(self::FIXED, $meta->dataDate()->format(\DATE_ATOM));
    }

    public function testNamesThatFailedAgainstTheFirstRepositoryAreStillOfferedToTheSecond(): void
    {
        // A never-started server (unbound port, empty cache) ahead of a working one: every name
        // fails against the first repository. A repository that could not answer has not
        // established that the package is absent from the next one, so the names must fall through.
        $unreachable = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $working = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $working->start();
        try {
            $loader = new RepositoryMetadataLoader(
                array_merge($unreachable->repositories(), $working->repositories()),
                Clock::fixed(self::FIXED)
            );

            $batch = $loader->load(['phpzip/phpzip', 'wallabag/rulerz']);

            self::assertSame([], $batch->failed());
            self::assertSame([], $batch->notFound());
            self::assertArrayHasKey('phpzip/phpzip', $batch->metadata());
            self::assertArrayHasKey('wallabag/rulerz', $batch->metadata());
        } finally {
            $working->stop();
            $unreachable->stop();
        }
    }

    public function testAnUnreachableSecondRepositoryIsNeverConsultedOnceTheFirstResolved(): void
    {
        $working = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $working->start();
        $unreachable = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        try {
            $loader = new RepositoryMetadataLoader(
                array_merge($working->repositories(), $unreachable->repositories()),
                Clock::fixed(self::FIXED)
            );

            $batch = $loader->load(['phpzip/phpzip']);

            self::assertSame([], $batch->failed());
            self::assertSame([], $batch->notFound());
            self::assertArrayHasKey('phpzip/phpzip', $batch->metadata());
        } finally {
            $working->stop();
            $unreachable->stop();
        }
    }

    public function testTheFirstRepositoryServingANameWins(): void
    {
        $first = $this->syntheticServer(['dup/pkg' => [$this->p2Version('dup/pkg', '1.0.0')]]);
        $second = $this->syntheticServer(['dup/pkg' => [
            $this->p2Version('dup/pkg', '1.0.0'),
            $this->p2Version('dup/pkg', '2.0.0'),
        ]]);
        $first->start();
        $second->start();
        try {
            $loader = new RepositoryMetadataLoader(
                array_merge($first->repositories(), $second->repositories()),
                Clock::fixed(self::FIXED)
            );

            $meta = $loader->load(['dup/pkg'])->metadata()['dup/pkg'] ?? null;

            self::assertNotNull($meta);
            self::assertSame('1.0.0', $meta->lastStableVersion());
            self::assertSame(1, $meta->releaseCount());
        } finally {
            $first->stop();
            $second->stop();
        }
    }

    public function testOneFailingChunkLeavesTheOtherChunksResolvable(): void
    {
        // Names are queried in chunks of CHUNK_SIZE; a chunk that throws must fail only its own
        // names, not the whole load.
        $server = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $server->start();
        try {
            $names = \array_slice($this->wallabagNames(), 0, RepositoryMetadataLoader::CHUNK_SIZE + 5);
            $firstChunk = \array_slice($names, 0, RepositoryMetadataLoader::CHUNK_SIZE);
            $laterChunk = \array_slice($names, RepositoryMetadataLoader::CHUNK_SIZE);
            $loader = new RepositoryMetadataLoader([$this->repositoryFailingFor($server, $names[0])], Clock::fixed(self::FIXED));

            $batch = $loader->load($names);

            foreach ($firstChunk as $name) {
                self::assertSame(self::CHUNK_FAILURE, $batch->failed()[$name] ?? null, $name.' shares the failing chunk');
            }
            foreach ($laterChunk as $name) {
                self::assertArrayHasKey($name, $batch->metadata(), $name.' is in a later chunk and should resolve');
            }
            self::assertSame([], $batch->notFound());
        } finally {
            $server->stop();
        }
    }

    public function testDevOnlyBranchAliasIsUnwrappedAndCountedOnce(): void
    {
        // ComposerRepository::loadPackages() returns an AliasPackage built from extra.branch-alias
        // *and*, as a separate entry, the package it aliases, so unwrapping aliases without
        // deduplicating counts that release twice. This package has no stable file at all
        // (lox/xhprof-style: registered, but tagless), so it resolves entirely through the loader's
        // dev-only pass, where the same dedup must still apply. The recorded p2 fixtures have
        // `extra` stripped, hence the hand-written envelope here.
        $server = $this->syntheticServer([
            'alias/pkg~dev' => [$this->p2Version('alias/pkg', 'dev-main', [
                'extra' => ['branch-alias' => ['dev-main' => '2.0.x-dev']],
                'abandoned' => 'other/pkg',
            ])],
        ]);
        $server->start();
        try {
            $loader = new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::FIXED));

            $meta = $loader->load(['alias/pkg'])->metadata()['alias/pkg'] ?? null;

            self::assertNotNull($meta);
            self::assertSame(1, $meta->releaseCount());
            self::assertFalse($meta->hasStableRelease());
            self::assertTrue($meta->isAbandoned());
            self::assertSame('other/pkg', $meta->replacement());
        } finally {
            $server->stop();
        }
    }

    /**
     * Real-fixture counterpart to testDevOnlyBranchAliasIsUnwrappedAndCountedOnce(): wallabag/rulerz
     * has no tagged release at all (its `wallabag/rulerz.json` stable file lists zero versions for
     * the name), so it resolves entirely through the loader's dev-only pass against the recorded
     * `wallabag/rulerz~dev.json`. That file's `packages["wallabag/rulerz"]` has 2 entries
     * (dev-master, dev-support-symfony-7), and dev-master carries `extra.branch-alias`
     * (dev-master => 1.0.x-dev). ComposerRepository::loadPackages() unwraps that into a separate
     * AliasPackage entry alongside the package it aliases, so without the loader's own dedup this
     * would count 3 releases instead of 2.
     */
    public function testRealDevOnlyPackageWithBranchAliasIsCountedOnce(): void
    {
        $meta = $this->loader()->load(['wallabag/rulerz'])->metadata()['wallabag/rulerz'] ?? null;

        self::assertNotNull($meta);
        self::assertFalse($meta->hasStableRelease());
        self::assertSame(2, $meta->releaseCount());
    }

    public function testDeadlineAlreadyPastMarksEveryNameFailedWithTheBudgetReason(): void
    {
        // $fake returns 100.0 on the first call (Deadline::inSeconds()'s own construction call,
        // which computes expiresAt = 105.0) and 200.0 on every call after that, so the very first
        // isPast() check inside loadFromRepository() already reports past.
        $calls = 0;
        $fake = static function () use (&$calls): float {
            ++$calls;

            return $calls === 1 ? 100.0 : 200.0;
        };
        $deadline = Deadline::inSeconds(5.0, $fake);

        $server = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $server->start();
        try {
            $loader = new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::FIXED), false, $deadline);
            $server->resetRequestCount();

            $batch = $loader->load(['phpzip/phpzip', 'lox/xhprof']);

            self::assertSame([], $batch->metadata());
            self::assertSame([], $batch->notFound());
            foreach (['phpzip/phpzip', 'lox/xhprof'] as $name) {
                self::assertSame(MetadataLoaderInterface::BUDGET_REASON, $batch->failed()[$name] ?? null);
            }
            self::assertSame(0, $server->requestCount(), 'no chunk should have been started once the deadline was already past');
        } finally {
            $server->stop();
        }
    }

    public function testDeadlinePassingMidLoadFailsOnlyTheUnstartedChunks(): void
    {
        // $fake returns 100.0 for its first 3 calls (construction, then the isPast() check before
        // each of the first two chunks of CHUNK_SIZE=10) and 200.0 from the 4th call onward (the
        // isPast() check before the third chunk), so the first two chunks (20 names) resolve
        // normally and the third chunk (5 names) never starts.
        $calls = 0;
        $fake = static function () use (&$calls): float {
            ++$calls;

            return $calls <= 3 ? 100.0 : 200.0;
        };
        $deadline = Deadline::inSeconds(5.0, $fake);

        $server = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $server->start();
        try {
            $names = \array_slice($this->wallabagNames(), 0, 25);
            $loader = new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::FIXED), false, $deadline);

            $batch = $loader->load($names);

            self::assertCount(20, $batch->metadata());
            $failed = $batch->failed();
            self::assertCount(5, $failed);
            foreach (\array_slice($names, 20) as $name) {
                self::assertSame(MetadataLoaderInterface::BUDGET_REASON, $failed[$name] ?? null, $name.' should be an unstarted chunk');
            }
            foreach (\array_slice($names, 0, 20) as $name) {
                self::assertArrayHasKey($name, $batch->metadata(), $name.' is in an already-started chunk');
            }
        } finally {
            $server->stop();
        }
    }

    /**
     * Regression test: a tagless name (wallabag/rulerz) that lands in pass 1's $needDev *before*
     * the budget expires must not be silently dropped. $fake returns 100.0 for its first 2 calls
     * (construction, then the isPast() check before chunk 1) and 200.0 from the 3rd call onward
     * (the isPast() check before chunk 2), so chunk 1 (10 names, including wallabag/rulerz as its
     * last name) is processed normally and chunk 2 (5 names) never starts. wallabag/rulerz has no
     * tagged release, so pass 1's stable-file query finds it with zero versions and would normally
     * hand it to pass 2 — but pass 2 never runs here, so it must be reported failed with
     * BUDGET_REASON (never asked for its dev file) rather than vanishing into notFound().
     */
    public function testDeadlinePassingMidPassOneLeavesATaglessNameFailedNotDropped(): void
    {
        $calls = 0;
        $fake = static function () use (&$calls): float {
            ++$calls;

            return $calls <= 2 ? 100.0 : 200.0;
        };
        $deadline = Deadline::inSeconds(5.0, $fake);

        $server = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $server->start();
        try {
            $all = $this->wallabagNames();
            $firstChunk = array_merge(\array_slice($all, 0, 9), ['wallabag/rulerz']);
            $secondChunk = \array_slice($all, 9, 5);
            $names = array_merge($firstChunk, $secondChunk);
            $loader = new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::FIXED), false, $deadline);

            $batch = $loader->load($names);

            self::assertSame([], $batch->notFound());
            foreach (\array_slice($firstChunk, 0, 9) as $name) {
                self::assertArrayHasKey($name, $batch->metadata(), $name.' is in the started chunk and should resolve');
            }
            $failed = $batch->failed();
            self::assertSame(
                MetadataLoaderInterface::BUDGET_REASON,
                $failed['wallabag/rulerz'] ?? null,
                'a tagless name found mid-chunk before the deadline expired must not be dropped'
            );
            foreach ($secondChunk as $name) {
                self::assertSame(MetadataLoaderInterface::BUDGET_REASON, $failed[$name] ?? null, $name.' should be an unstarted chunk');
            }
            self::assertCount(6, $failed);
        } finally {
            $server->stop();
        }
    }

    /**
     * A real ComposerRepository against the given fixture server that throws for every chunk
     * containing $name, standing in for a repository whose root metadata is readable but whose
     * file for one package is not.
     */
    private function repositoryFailingFor(FixtureRepositoryServer $server, string $name): ComposerRepository
    {
        $io = new NullIO();
        $config = $server->config();
        $repository = new class (['type' => 'composer', 'url' => $server->url()], $io, $config, Factory::createHttpDownloader($io, $config)) extends ComposerRepository {
            public string $failFor = '';

            /**
             * @param array<string, \Composer\Semver\Constraint\ConstraintInterface|null> $packageNameMap
             * @param array<'alpha'|'beta'|'dev'|'RC'|'stable', 0|5|10|15|20>             $acceptableStabilities
             * @param array<string, 0|5|10|15|20>                                         $stabilityFlags
             * @param array<string, array<string, \Composer\Package\PackageInterface>>    $alreadyLoaded
             *
             * @return array{namesFound: array<string>, packages: array<BasePackage>}
             */
            public function loadPackages(array $packageNameMap, array $acceptableStabilities, array $stabilityFlags, array $alreadyLoaded = [])
            {
                // The map is name => constraint-or-null, so isset() would miss every entry.
                if (\array_key_exists($this->failFor, $packageNameMap)) {
                    throw new \RuntimeException(RepositoryMetadataLoaderTest::CHUNK_FAILURE);
                }

                return parent::loadPackages($packageNameMap, $acceptableStabilities, $stabilityFlags, $alreadyLoaded);
            }
        };
        $repository->failFor = $name;

        return $repository;
    }

    /**
     * A fixture repository server serving hand-written p2 envelopes, for the cases the recorded
     * fixtures cannot express. Keys are package names, optionally suffixed `~dev` to land in the
     * dev half of the p2 pair.
     *
     * @param array<string, list<array<string, mixed>>> $versionsByFile
     */
    private function syntheticServer(array $versionsByFile): FixtureRepositoryServer
    {
        $dir = $this->tempDir('lockrot-synthetic-repo-');
        $envelopeDir = $dir.'/envelopes';
        if (!mkdir($envelopeDir, 0777, true) && !is_dir($envelopeDir)) {
            throw new \RuntimeException('cannot create temp dir: '.$envelopeDir);
        }

        $lockPackages = [];
        foreach ($versionsByFile as $file => $versions) {
            $name = (string) preg_replace('{~dev$}', '', $file);
            $lockPackages[$name] = ['name' => $name, 'version' => '1.0.0'];
            $url = 'https://repo.packagist.org/p2/'.$file.'.json';
            file_put_contents(RecordedHttpClient::pathFor($envelopeDir, $url), (string) json_encode([
                'status' => 200,
                'fetched_at' => self::FIXED,
                'body' => json_encode(['packages' => [$name => $versions]]),
                'error' => null,
            ]));
        }
        $lockPath = $dir.'/composer.lock';
        file_put_contents($lockPath, (string) json_encode(['packages' => array_values($lockPackages)]));

        return FixtureRepositoryServer::fromLockFiles([$lockPath], $envelopeDir);
    }

    /**
     * One p2 version entry. `version_normalized` is deliberately left out so Composer's own
     * VersionParser derives it, which is what keeps `dev-*` entries valid.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function p2Version(string $name, string $version, array $overrides = []): array
    {
        return array_merge([
            'name' => $name,
            'version' => $version,
            'type' => 'library',
            'time' => '2020-01-01T00:00:00+00:00',
            'source' => ['type' => 'git', 'url' => 'https://github.com/'.$name.'.git', 'reference' => str_repeat('a', 40)],
        ], $overrides);
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.uniqid('', true);
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
