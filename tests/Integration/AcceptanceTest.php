<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Libyears;
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
        // One loader shared across every test in this class, matching how a real analyzer run uses
        // it: one instance queried repeatedly rather than rebuilt per call. It remembers what it
        // has already been asked for: three of the tests here analyse the same wallabag lock, and
        // without the memory each of them fetched all two hundred of its packages again.
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

    /**
     * $loader defaults to the shared class-level loader built in setUpBeforeClass(); testWallabag()
     * passes its own private one instead, since it runs in a separate process where that static
     * state was never built (see the comment on that test).
     */
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

    // The peak-memory assertion below reads memory_get_peak_usage(true), a whole-process high-water
    // mark that PHPUnit never resets between tests. With every other suite test contributing to that
    // same peak, the assertion would measure how much the entire run allocated before this point
    // rather than this analysis alone, and it sits close enough to the 64 MB budget that unrelated
    // suite growth trips it. Isolating it in its own process makes the peak reflect only this test.
    // That process never calls setUpBeforeClass(), so the class-level $server/$loader do not exist
    // here and this test builds its own private single-use server instead.
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
            // Packagist data moves over time, so the abandoned count is pinned to the recorded
            // fixtures. If this assertion needs updating after a re-recording, print byVerdict() and
            // diff the package names before touching the number.
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
            // Transitive exposure (S7). hoa/ruler is reached from two root requires, so the second
            // one is what directDependents() adds over the chain; both roots carry S7 naming it, and
            // wallabag/rulerz keeps the pinned verdict and high priority it had before the pass.
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
            // Pinned from the run before the report stated its exposure rule and listed what the
            // rule gives to nobody: stating it moved neither the list nor the line.
            self::assertCount(25, $exposure);
            self::assertSame(
                'pulled in by: wallabag/rulerz-bundle 15 · wallabag/rulerz 14 · wallabag/phpepub 5 · scheb/2fa-google-authenticator 3 · friendsofsymfony/oauth-server-bundle 2 · … and 20 more',
                $report->exposureSummaryLine()
            );
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
        // lox/xhprof is marked abandoned on Packagist (S1) and its GitHub repo is archived (S3),
        // which outrank the S6 "pinned to dev-master" signal in VerdictEngine precedence ->
        // ABANDONED rather than PINNED. The S6 signal (dev-master pin) still fires underneath.
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

    /**
     * The libyears of the recorded fixtures, pinned like the verdict counts are.
     *
     * The research table these numbers were first written against (wallabag 171.6, nextcloud 47.1,
     * matomo 11.8) was computed from ecosyste.ms's `latest_release_published_at`, which dates a
     * package with no release at all by its branch's last push. That is where the gap is:
     * lox/xhprof on `dev-master` (10.5 of matomo's 11.8) and the three wallabag/rulerz-* on
     * `dev-master` (8.1 of wallabag's). lockrot leaves them unmeasured and says so in the block.
     * The three scheb/2fa-* splits, whose newest tags share one commit and carry no date lockrot
     * trusts, are measured to the newest dated release above the installed one — a lower bound,
     * 4.06 each — which is what the table had for them too. pagerfanta/twig is the other way
     * round: its own v4.8.0 is one of the tags on a shared commit, so the lock dates it by that
     * commit and there is no installed end to measure from at all. The packages furthest behind
     * are the same on every fixture.
     */
    public function testLibyearsOnTheRecordedFixtures(): void
    {
        $wallabag = $this->analyze('apps/wallabag_wallabag');
        $block = $wallabag->libyears();
        self::assertEqualsWithDelta(163.69, $block->total(), 0.005);
        self::assertEqualsWithDelta(106.7, $block->direct(), 0.005);
        self::assertSame(194, $block->measured());
        self::assertSame([
            Libyears::BRANCH_SNAPSHOT => 4,
            Libyears::NO_STABLE_RELEASE_DATE => 2,
            Libyears::NOT_FROM_COMPOSER_REPOSITORY => 0,
            Libyears::METADATA_UNAVAILABLE => 0,
        ], $block->unmeasured());
        $worst = $block->worst();
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
        // The block is the arithmetic over the findings the document prints, to within the
        // rounding of each printed value.
        $sum = 0.0;
        $measured = 0;
        foreach (JsonPath::arrayAt($wallabag->toArray(), ['findings']) as $finding) {
            self::assertIsArray($finding);
            if ($finding['libyears'] !== null) {
                self::assertIsFloat($finding['libyears']);
                ++$measured;
                $sum += $finding['libyears'];
            }
        }
        self::assertSame($block->measured(), $measured);
        self::assertEqualsWithDelta($block->toArray()['total'], $sum, 0.005 * $measured);

        // The other fixtures, each block pinned and each checked against its own findings.
        $expected = [
            'apps/nextcloud_3rdparty' => ['total' => 47.47, 'direct' => 13.3, 'measured' => 94, 'unmeasured' => [0, 0, 0, 0], 'worst' => ['punic/punic', 3.45]],
            // lox/xhprof and the two matomo/* lists on dev-master; two symfony/polyfill-* splits undated
            'apps/matomo-org_matomo' => ['total' => 0.87, 'direct' => 0.07, 'measured' => 47, 'unmeasured' => [3, 2, 0, 0], 'worst' => ['maxmind-db/reader', 0.8]],
            'skeletons/laravel' => ['total' => 0.46, 'direct' => 0.02, 'measured' => 73, 'unmeasured' => [0, 3, 0, 0], 'worst' => ['brick/math', 0.25]],
        ];
        foreach ($expected as $dir => $want) {
            $report = $this->analyze($dir);
            $block = $report->libyears();
            self::assertEqualsWithDelta($want['total'], $block->total(), 0.005, $dir);
            self::assertEqualsWithDelta($want['direct'], $block->direct(), 0.005, $dir);
            self::assertSame($want['measured'], $block->measured(), $dir);
            self::assertSame(array_combine(Libyears::REASONS, $want['unmeasured']), $block->unmeasured(), $dir);
            $worst = $block->worst();
            self::assertNotNull($worst, $dir);
            self::assertSame($want['worst'][0], $worst->package(), $dir);
            self::assertEqualsWithDelta($want['worst'][1], (float) $worst->libyears(), 0.005, $dir);
            $sum = 0.0;
            foreach (JsonPath::arrayAt($report->toArray(), ['findings']) as $finding) {
                self::assertIsArray($finding);
                if ($finding['libyears'] !== null) {
                    self::assertIsFloat($finding['libyears']);
                    $sum += $finding['libyears'];
                }
            }
            self::assertEqualsWithDelta($block->toArray()['total'], $sum, 0.005 * $block->measured(), $dir.': the block is the arithmetic over the findings');
        }
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
