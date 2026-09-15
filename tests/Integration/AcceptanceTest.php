<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Report;
use Lockrot\Clock;
use Lockrot\Data\GitHub\GitHubClient;
use Lockrot\Data\GitHub\GitHubFetchPlanner;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
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
    private static ?RepositoryMetadataLoader $loader = null;

    public static function setUpBeforeClass(): void
    {
        $lockFiles = array_map(static fn (string $dir): string => self::FIXTURES.$dir.'/composer.lock', self::DIRS);
        self::$server = FixtureRepositoryServer::fromLockFiles($lockFiles);
        self::$server->start();
        // One loader shared across every test in this class, matching how a real analyzer run uses
        // it: one instance queried repeatedly rather than rebuilt per call.
        self::$loader = new RepositoryMetadataLoader(self::$server->repositories(), Clock::fixed(self::NOW));
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

    /**
     * $loader defaults to the shared class-level loader built in setUpBeforeClass(); testWallabag()
     * passes its own private one instead, since it runs in a separate process where that static
     * state was never built (see the comment on that test).
     */
    private function analyze(string $dir, ?RepositoryMetadataLoader $loader = null): Report
    {
        $clock = Clock::fixed(self::NOW);
        $analyzer = new Analyzer(
            $loader ?? $this->loader(),
            new GitHubClient(new RecordedHttpClient(self::FIXTURES.'http/github'), 'recorded'),
            new GitHubFetchPlanner(true),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock
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

    // The peak-memory assertion below reads memory_get_peak_usage(true), a whole-process
    // high-water mark that PHPUnit never resets between tests. With every other suite test
    // (Unit runs before Integration) contributing to that same peak, this assertion is really
    // testing "how much did the entire run allocate before this point", not this analysis alone
    // — and it now sits close enough to the 64 MB budget that unrelated suite growth trips it.
    // Isolating it in its own process makes the peak reflect only this test again, matching the
    // comment's actual intent (a PHAR-OOM regression guard for this one analysis). Process
    // isolation runs this method in a brand-new PHP process that never calls setUpBeforeClass()
    // (PHPUnit's method-isolation template instantiates the test case and calls run() directly),
    // so the class-level $server/$loader built there do not exist here — this test builds its own
    // private single-use server instead.
    /** @runInSeparateProcess */
    #[RunInSeparateProcess]
    public function testWallabag(): void
    {
        $server = FixtureRepositoryServer::fromLockFiles([self::FIXTURES.'apps/wallabag_wallabag/composer.lock']);
        $server->start();
        $loader = new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::NOW));

        try {
            $report = $this->analyze('apps/wallabag_wallabag', $loader);
            $f = $this->byName($report);
            self::assertSame(200, $report->packagesChecked());
            // Recorded 2026-09-14: Packagist data moves over time, so the abandoned count is pinned to
            // this recording rather than the 2026-09-14 research snapshot behind the spec. If this
            // assertion needs updating again, print byVerdict() and diff the package names before
            // touching the number.
            self::assertSame(19, $report->byVerdict()[Verdict::ABANDONED], 'flagged abandoned 19 of 200 prod');
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
            // The report leads with its critical rows: nothing may sort above a critical priority.
            self::assertSame(Priority::CRITICAL, $report->findings()[0]->priority());
            self::assertFalse($report->hadNetworkFailures(), implode("\n", $report->notes()));
            // Regression guard for the PHAR OOM at PHP's default 128M memory_limit: analysing a
            // 200-package lock must not retain the expanded Packagist release history.
            self::assertLessThan(64 * 1024 * 1024, memory_get_peak_usage(true), 'peak memory');
        } finally {
            $server->stop();
        }
    }

    public function testNextcloud3rdparty(): void
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
        // Recorded 2026-09-14: lox/xhprof is now marked abandoned on Packagist (S1) and its GitHub
        // repo is archived (S3), which outrank the S6 "pinned to dev-master" signal in VerdictEngine
        // precedence -> ABANDONED rather than PINNED. It was PINNED at the time the spec's research
        // was written; Packagist/GitHub state has moved since, so this documents the delta rather than
        // hand-editing the recorded fixture. The S6 signal (dev-master pin) still fires underneath.
        self::assertSame(Verdict::ABANDONED, $f['lox/xhprof']->verdict());
        self::assertStringContainsString('dev-master', $f['lox/xhprof']->evidence());
        $ids = array_map(static fn ($s) => $s->id(), $f['lox/xhprof']->signals());
        self::assertContains('S6', $ids, 'pinned-to-branch signal should still fire underneath the abandoned verdict');
    }

    public function testOldPromiseExamplesOn84(): void
    {
        $f = $this->byName($this->analyze('apps/wallabag_wallabag'));
        // phpzip/phpzip 2.0.8: released 2015, require php >=5.3.0 -> S5 fires but silent has precedence
        $ids = array_map(static fn ($s) => $s->id(), $f['phpzip/phpzip']->signals());
        self::assertContains('S5', $ids);
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
