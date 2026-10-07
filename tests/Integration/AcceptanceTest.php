<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\TransitiveExposure;
use Lockrot\Clock;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\Golden;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\MemoisingMetadataLoader;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Priority;
use Lockrot\Verdict\Verdict;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class AcceptanceTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/';
    private const NOW = '2026-09-14T00:00:00+00:00';
    private const DIRS = ['apps/wallabag_wallabag', 'apps/nextcloud_3rdparty', 'skeletons/laravel', 'apps/matomo-org_matomo'];

    private static ?FixtureRepositoryServer $server = null;
    private static ?MemoisingMetadataLoader $loader = null;

    public static function setUpBeforeClass(): void
    {
        $lockFiles = array_map(static fn (string $dir): string => self::FIXTURES.$dir.'/composer.lock', self::DIRS);
        self::$server = FixtureRepositoryServer::fromLockFiles($lockFiles);
        self::$server->start();
        // One loader for the class: it remembers what it was asked, so the tests that analyse the
        // same wallabag lock do not fetch its packages again.
        self::$loader = new MemoisingMetadataLoader(
            new RepositoryMetadataLoader(self::$server->repositories(), Clock::fixed(self::NOW))
        );
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            self::$server->stop();
            self::$server = null;
        }
        self::$loader = null;
    }

    private function loader(): MemoisingMetadataLoader
    {
        $loader = self::$loader;
        self::assertNotNull($loader);

        return $loader;
    }

    private static function signal(Finding $finding, string $id): ?Signal
    {
        foreach ($finding->signals() as $signal) {
            if ($signal->id() === $id) {
                return $signal;
            }
        }

        return null;
    }

    private function analyze(string $dir, ?RepositoryMetadataLoader $loader = null): Report
    {
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $analyzer = new Analyzer(
            $loader ?? $this->loader(),
            new ActivityClient(new RecordedHttpClient(self::FIXTURES.'http/github'), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            false
        );

        return $analyzer->analyze(LockFile::fromFile(self::FIXTURES.$dir.'/composer.lock'), ProjectConfig::fromFile(self::FIXTURES.$dir.'/composer.json'), false);
    }

    /** @return array<string, Finding> */
    private function byName(Report $report): array
    {
        $out = [];
        foreach ($report->findings() as $finding) {
            $out[$finding->package()] = $finding;
        }

        return $out;
    }

    // The peak-memory assertion reads a whole-process high-water mark that PHPUnit never resets, so
    // this test runs in its own process. That process never calls setUpBeforeClass(): the test
    // builds its own server and loader.
    /** @runInSeparateProcess */
    #[RunInSeparateProcess]
    public function testWallabagKeepsItsVerdictsPrioritiesAndExposure(): void
    {
        $server = FixtureRepositoryServer::fromLockFiles([self::FIXTURES.'apps/wallabag_wallabag/composer.lock']);
        $server->start();
        $loader = new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::NOW));

        try {
            $report = $this->analyze('apps/wallabag_wallabag', $loader);
            $f = $this->byName($report);
            self::assertSame(\count(LockFile::fromFile(self::FIXTURES.'apps/wallabag_wallabag/composer.lock')->packages(false)), $report->packagesChecked());
            $verdicts = array_map(static fn (Finding $finding): string => $finding->verdict(), $f);
            self::assertSame(\count(array_keys($verdicts, Verdict::ABANDONED, true)), $report->byVerdict()[Verdict::ABANDONED]);
            self::assertSame(Verdict::SILENT, $f['phpzip/phpzip']->verdict());
            self::assertStringContainsString('last release 2015-11-16', $f['phpzip/phpzip']->evidence());
            self::assertStringContainsString('last push 2015-11-16', $f['phpzip/phpzip']->evidence());
            self::assertNotSame(Verdict::SILENT, $f['psr/cache']->verdict());
            self::assertSame(Verdict::FINISHED, $f['ralouphie/getallheaders']->verdict());
            self::assertSame(Verdict::PINNED, $f['wallabag/rulerz']->verdict());
            self::assertSame(Verdict::ABANDONED, $f['hoa/ruler']->verdict());
            // Priority, read off this fixture: wallabag/rulerz is a root require (chain length 1) and
            // pinned -> high; hoa/ruler is reached through wallabag/rulerz and abandoned -> critical
            // lowered one step for being transitive -> high; phpzip/phpzip is reached through
            // wallabag/phpepub and silent -> the same one-step drop to high. The lock is analysed with
            // includeDev = false, so every row here is a prod row.
            self::assertSame(Priority::HIGH, $f['wallabag/rulerz']->priority());
            self::assertTrue($f['wallabag/rulerz']->isDirect());
            self::assertSame(Priority::HIGH, $f['hoa/ruler']->priority());
            self::assertSame(['wallabag/rulerz', 'hoa/ruler'], $f['hoa/ruler']->chain());
            self::assertSame(Priority::HIGH, $f['phpzip/phpzip']->priority());
            self::assertSame(['wallabag/phpepub', 'phpzip/phpzip'], $f['phpzip/phpzip']->chain());
            self::assertFalse($f['phpzip/phpzip']->isDev());
            // Transitive exposure (S7). hoa/ruler is reached from two root requires, so the second
            // one is what directDependents() adds over the chain; both roots carry S7 naming it, and
            // wallabag/rulerz keeps the pinned verdict and high priority.
            self::assertSame(['wallabag/rulerz', 'wallabag/rulerz-bundle'], $f['hoa/ruler']->directDependents());
            self::assertSame(['wallabag/rulerz-bundle'], $f['hoa/ruler']->otherDirectDependents());
            $rulerzS7 = self::signal($f['wallabag/rulerz'], Signal::S7);
            self::assertNotNull($rulerzS7);
            self::assertSame(Signal::LEVEL_INFO, $rulerzS7->level());
            $pulledIn = $rulerzS7->data()['packages'];
            self::assertIsArray($pulledIn);
            self::assertContains('hoa/ruler', array_column($pulledIn, 'package'));
            self::assertSame(Verdict::PINNED, $f['wallabag/rulerz']->verdict());
            self::assertNull(self::signal($f['hoa/ruler'], Signal::S7), 'a transitive package is not a parent');
            $exposure = $report->exposure();
            self::assertArrayHasKey('wallabag/rulerz', $exposure);
            self::assertSame($rulerzS7->data()['flagged'], $exposure['wallabag/rulerz']);
            $counts = array_values($exposure);
            self::assertSame($counts, array_reverse(array_reverse($counts, false)), 'counts are a list');
            for ($i = 1; $i < \count($counts); ++$i) {
                self::assertGreaterThanOrEqual($counts[$i], $counts[$i - 1], 'exposure is ordered most first');
            }
            Golden::assertMatches('wallabag.json', ['verdicts' => $verdicts, 'exposure' => $exposure, 'exposure_summary_line' => $report->exposureSummaryLine()], 'testWallabagKeepsItsVerdictsPrioritiesAndExposure');
            // Every flagged transitive package is attributed, shared above the cap, or reached by no
            // direct requirement — exactly one of the three. wallabag's widest fan-in is 8, so the
            // document lists nothing above the cap.
            foreach ($report->findings() as $finding) {
                if (!Verdict::flagged($finding->verdict()) || $finding->isDirect()) {
                    continue;
                }
                $arms = (int) TransitiveExposure::attributable($finding) + (int) TransitiveExposure::sharedAboveCap($finding) + (int) ($finding->directDependents() === []);
                self::assertSame(1, $arms, $finding->package());
            }
            $document = $report->toArray();
            self::assertSame([], $document['unattributed']);
            self::assertSame(['max_fan_in' => 8], $document['exposure_rule']);
            // The report leads with its critical rows. sensio/framework-extra-bundle is the fixture's
            // only root require whose GitHub repository is recorded as archived
            // (sensiolabs/SensioFrameworkExtraBundle in tests/fixtures/http/github), so it is the one
            // package here that is abandoned *and* direct *and* prod — the only way to reach
            // critical. Named rather than asserted positionally so a shift in the recorded data says
            // which package moved.
            self::assertSame(Priority::CRITICAL, $f['sensio/framework-extra-bundle']->priority());
            self::assertTrue($f['sensio/framework-extra-bundle']->isDirect());
            self::assertSame('sensio/framework-extra-bundle', $report->findings()[0]->package());
            self::assertSame(Priority::CRITICAL, $report->findings()[0]->priority());
            self::assertFalse($report->hadNetworkFailures(), implode("\n", $report->notes()));
            // The PHAR runs under PHP's default 128M memory_limit: analysing wallabag's lock must not
            // retain the expanded Packagist release history.
            self::assertLessThan(64 * 1024 * 1024, memory_get_peak_usage(true), 'peak memory');
        } finally {
            $server->stop();
        }
    }

    /**
     * wallabag's four branch snapshots, read off the recorded repository data: the three
     * wallabag/rulerz-* list dev branches and not one tag, friendsofsymfony/oauth-server-bundle has
     * tags and its newest dated one is 1.6.2 (2.0.0-alpha.0 is higher, and older). S6 checks the
     * snapshot first, so all four are `branch_snapshot`. has_stable_release tells the
     * never-released three apart.
     */
    public function testWallabagSnapshotsSayWhetherThePackageEverReleased(): void
    {
        $f = $this->byName($this->analyze('apps/wallabag_wallabag'));
        $expected = [
            'wallabag/rulerz' => [false, null, null, '2023-12-24T00:53:44+00:00', Priority::HIGH],
            'wallabag/rulerz-bundle' => [false, null, null, '2023-12-24T22:23:50+00:00', Priority::HIGH],
            'wallabag/rulerz-bridge' => [false, null, null, '2023-12-24T01:18:26+00:00', Priority::MEDIUM],
            'friendsofsymfony/oauth-server-bundle' => [true, '2019-01-23T15:23:04+00:00', '1.6.2', '2022-03-24T10:22:23+00:00', Priority::HIGH],
        ];
        foreach ($expected as $package => [$released, $lastRelease, $lastVersion, $snapshotTime, $priority]) {
            $s6 = self::signal($f[$package], Signal::S6);
            self::assertNotNull($s6, $package);
            self::assertSame([
                'version' => 'dev-master',
                'reason' => 'branch_snapshot',
                'has_stable_release' => $released,
                'last_stable_release' => $lastRelease,
                'last_stable_version' => $lastVersion,
                'last_stable_dated_by' => null,
                'snapshot_time' => $snapshotTime,
            ], $s6->data(), $package);
            self::assertSame('pinned to branch snapshot dev-master', $s6->summary(), $package);
            self::assertSame(Verdict::PINNED, $f[$package]->verdict(), $package);
            self::assertSame($priority, $f[$package]->priority(), $package);
        }
    }

    public function testNextcloudBantuIniGetWrapperIsSilent(): void
    {
        $f = $this->byName($this->analyze('apps/nextcloud_3rdparty'));
        self::assertSame(Verdict::SILENT, $f['bantu/ini-get-wrapper']->verdict());
        self::assertStringContainsString('last release 2014-09-15', $f['bantu/ini-get-wrapper']->evidence());
        self::assertStringContainsString('last push 2015-03-05', $f['bantu/ini-get-wrapper']->evidence());
    }

    public function testLaravelSkeletonIsClean(): void
    {
        $report = $this->analyze('skeletons/laravel');
        self::assertSame(0, $report->byVerdict()[Verdict::ABANDONED]);
        self::assertSame(0, $report->byVerdict()[Verdict::SILENT]);
    }

    public function testMatomoXhprofIsPinned(): void
    {
        $f = $this->byName($this->analyze('apps/matomo-org_matomo'));
        // lox/xhprof is marked abandoned on Packagist (S1) and its GitHub repo is archived (S3),
        // which outrank the S6 "pinned to dev-master" signal in VerdictEngine precedence ->
        // ABANDONED rather than PINNED. The S6 signal (dev-master pin) still fires underneath.
        self::assertSame(Verdict::ABANDONED, $f['lox/xhprof']->verdict());
        self::assertStringContainsString('dev-master', $f['lox/xhprof']->evidence());
        $ids = array_map(static fn ($s) => $s->id(), $f['lox/xhprof']->signals());
        self::assertContains('S6', $ids, 'pinned-to-branch signal should still fire underneath the abandoned verdict');
        $s6 = self::signal($f['lox/xhprof'], Signal::S6);
        self::assertNotNull($s6);
        self::assertSame('branch_snapshot', $s6->data()['reason']);
    }

    public function testPhpzipCarriesS5UnderItsSilentVerdict(): void
    {
        $f = $this->byName($this->analyze('apps/wallabag_wallabag'));
        // phpzip/phpzip's installed release requires php >=5.3.0, so S5 fires, but silent has precedence.
        $ids = array_map(static fn ($s) => $s->id(), $f['phpzip/phpzip']->signals());
        self::assertContains('S5', $ids);
    }

    /**
     * The libyears of each recorded fixture, per package, and the block as the arithmetic over them.
     *
     * lockrot leaves a package on `dev-master` unmeasured: lox/xhprof and the wallabag/rulerz-*
     * packages. The scheb/2fa-* splits share one commit among their newest tags, which carry no
     * date lockrot trusts, so each is measured to the newest dated release above the installed one,
     * a lower bound. pagerfanta/twig has no installed end to measure from: its own v4.8.0 is one of
     * the tags on a shared commit, so the lock dates it by that commit.
     */
    public function testLibyearsOnTheRecordedFixtures(): void
    {
        $wallabag = $this->analyze('apps/wallabag_wallabag');
        $worst = $wallabag->libyears()->worst();
        self::assertNotNull($worst);
        self::assertSame('smalot/pdfparser', $worst->package());
        self::assertEqualsWithDelta(4.70, (float) $worst->libyears(), 0.005);
        $f = $this->byName($wallabag);
        self::assertNull($f['wallabag/rulerz']->libyears(), 'a dev-master pin is not measured');
        self::assertEqualsWithDelta(4.06, (float) $f['scheb/2fa-backup-code']->libyears(), 0.005, 'a split whose newest tags share a commit: measured to the newest dated release above, a lower bound');
        self::assertEqualsWithDelta(4.23, (float) $f['scheb/2fa-bundle']->libyears(), 0.005, 'while the monorepo itself is dated exactly');
        self::assertNull($f['symfony/polyfill-ctype']->libyears(), 'the newest tag is undated and nothing dated sits above the installed version');
        self::assertNull($f['pagerfanta/twig']->libyears(), 'the installed tag itself shares a commit: the lock dates it by that, not by its release');
        self::assertSame(0.0, $f['sensio/framework-extra-bundle']->libyears(), 'abandoned, and zero libyears behind: the installed release is the last one');
        self::assertEqualsWithDelta(3.36, (float) $f['psr/log']->libyears(), 0.005, 'finished, and three years behind a 3.x it will never need — the docs quote this');

        $golden = [];
        foreach (self::DIRS as $dir) {
            $report = $dir === 'apps/wallabag_wallabag' ? $wallabag : $this->analyze($dir);
            $block = $report->libyears();
            $packages = [];
            $sum = 0.0;
            foreach (JsonPath::arrayAt($report->toArray(), ['findings']) as $finding) {
                self::assertIsArray($finding);
                self::assertIsString($finding['package']);
                $packages[$finding['package']] = $finding['libyears'];
                if ($finding['libyears'] !== null) {
                    self::assertIsFloat($finding['libyears']);
                    $sum += $finding['libyears'];
                }
            }
            $measured = \count(array_filter($packages, static fn ($libyears): bool => $libyears !== null));
            self::assertSame($block->measured(), $measured, $dir);
            self::assertSame($report->packagesChecked(), $measured + array_sum($block->unmeasured()), $dir.': every package is measured or has a reason');
            // The block is the arithmetic over the printed findings, to within the rounding of each value.
            self::assertEqualsWithDelta($block->toArray()['total'], $sum, 0.005 * $measured, $dir);
            $golden[$dir] = ['block' => $block->toArray(), 'packages' => $packages];
        }
        Golden::assertMatches('libyears.json', $golden, 'testLibyearsOnTheRecordedFixtures');
    }

    public function testOutputWordingIsNeutral(): void
    {
        $report = $this->analyze('apps/wallabag_wallabag');
        $json = json_encode($report->toArray());
        $text = strtolower($json === false ? '' : $json);
        foreach (['vulnerab', 'broken', 'insecure', 'dead'] as $banned) {
            self::assertStringNotContainsString($banned, $text, 'banned wording: '.$banned);
        }
    }
}
