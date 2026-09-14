<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Repository;

use Lockrot\Clock;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Lock\LockFile;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class RepositoryMetadataLoaderTest extends TestCase
{
    private const WALLABAG_LOCK = __DIR__.'/../../../fixtures/apps/wallabag_wallabag/composer.lock';
    private const MATOMO_LOCK = __DIR__.'/../../../fixtures/apps/matomo-org_matomo/composer.lock';
    private const FIXED = '2026-09-14T00:00:00+00:00';

    private static ?FixtureRepositoryServer $server = null;
    private static ?RepositoryMetadataLoader $loader = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK, self::MATOMO_LOCK]);
        self::$server->start();
        // One loader (and so one HttpDownloader/curl-multi handle) shared across every test in this
        // class: repeatedly building a fresh RepositoryManager against the same long-lived php -S
        // process left stale connections behind that eventually wedged the server, measured while
        // writing this test — reusing a single loader avoids that and matches how a real analyzer
        // run would use it (one instance queried many times).
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

    public function testLoxXhprofIsFoundViaDevAndIsAbandonedWithNoStableRelease(): void
    {
        $batch = $this->loader()->load(['lox/xhprof']);

        $meta = $batch->metadata()['lox/xhprof'] ?? null;
        self::assertNotNull($meta);
        self::assertTrue($meta->isAbandoned());
        self::assertFalse($meta->hasStableRelease());
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
        $batch = $this->loader()->load(['nonexistent/zzz']);

        self::assertSame(['nonexistent/zzz'], $batch->notFound());
        self::assertSame([], $batch->failed());
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
     * Regression test for a real hang: php -S writes one access-log line per request to stderr,
     * and Symfony\Process only drains that pipe while something calls back into the Process object
     * (which the startup poll loop does, but nothing does afterward). A single ~200-package load is
     * already ~1000 requests; three of them back-to-back through the one shared loader/server this
     * class builds in setUpBeforeClass() is ~3000 requests and previously wedged the server well
     * before completing. FixtureRepositoryServer::start() now redirects the child's stdout/stderr to
     * a file instead of a pipe, which removes the pipe to fill in the first place.
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
        // A repository whose only envelope for this name has an entry (so Composer marks the name
        // as "found") but an empty version list — e.g. every version filtered out, or the p2 entry
        // itself is empty. There is nothing to build a PackageMetadata from, so this must land in
        // failed(), not metadata() or notFound().
        $dir = sys_get_temp_dir().'/lockrot-empty-versions-'.uniqid('', true);
        $envelopeDir = $dir.'/envelopes';
        self::assertNotFalse(mkdir($envelopeDir, 0777, true));

        $lockPath = $dir.'/composer.lock';
        file_put_contents($lockPath, (string) json_encode([
            'packages' => [['name' => 'test/empty-versions', 'version' => '1.0.0']],
        ]));

        $url = 'https://repo.packagist.org/p2/test/empty-versions.json';
        file_put_contents(RecordedHttpClient::pathFor($envelopeDir, $url), (string) json_encode([
            'status' => 200,
            'fetched_at' => self::FIXED,
            'body' => json_encode(['packages' => ['test/empty-versions' => []]]),
            'error' => null,
        ]));

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
        unlink(RecordedHttpClient::pathFor($envelopeDir, $url));
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
                    self::assertSame(RepositoryMetadataLoader::OFFLINE_NOT_FOUND_REASON, $batch->failed()[$name] ?? null);
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
}
