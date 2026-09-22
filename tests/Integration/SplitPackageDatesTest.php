<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Report;
use Lockrot\Clock;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\MonorepoParents;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\TestCase;

/**
 * The regression the 0.8.0 shared-commit rule left behind, over the real recorded Packagist data:
 * `illuminate/contracts` v8.83.27 sits on a commit 31 stable tags share, so its 8.x branch carries
 * no date of its own and S8 cannot measure it. laravel/framework replaces it and dates the same
 * branch by its own release, and that is what the analyzer now reads.
 */
final class SplitPackageDatesTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/';
    private const LOCK = self::FIXTURES.'mini-split';
    private const NOW = '2026-09-14T00:00:00+00:00';

    private static ?FixtureRepositoryServer $server = null;

    public static function setUpBeforeClass(): void
    {
        // The parents are served alongside the lock's own package: no lock lists laravel/framework,
        // and the analyzer asks for it by name when a split package needs dating.
        self::$server = FixtureRepositoryServer::fromLockFiles(
            [self::LOCK.'/composer.lock'],
            __DIR__.'/../fixtures/http/p2',
            MonorepoParents::load()->candidates()
        );
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            self::$server->stop();
            self::$server = null;
        }
    }

    private function report(MonorepoParents $parents, Thresholds $thresholds): Report
    {
        $server = self::$server;
        self::assertNotNull($server);
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $analyzer = new Analyzer(
            new RepositoryMetadataLoader($server->repositories(), $clock),
            new ActivityClient(new RecordedHttpClient(self::FIXTURES.'http/github'), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, $thresholds, '8.4', PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            false,
            null,
            $parents
        );

        return $analyzer->analyze(LockFile::fromFile(self::LOCK.'/composer.lock'), ProjectConfig::fromFile(self::LOCK.'/composer.json'), false);
    }

    private static function contracts(Report $report): Finding
    {
        foreach ($report->findings() as $finding) {
            if ($finding->package() === 'illuminate/contracts') {
                return $finding;
            }
        }
        self::fail('illuminate/contracts is not in the report');
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

    /** Without a parent the branch is undated, as it has been since 0.8.0: nothing to measure. */
    public function testWithoutAParentTheSplitBranchIsNotMeasured(): void
    {
        $report = $this->report(MonorepoParents::none(), new Thresholds(1, 1));
        $finding = self::contracts($report);

        self::assertNull(self::signal($finding, Signal::S8));
        self::assertNull(self::signal($finding, Signal::S2), 'the package\'s own age is unknown too');
        self::assertSame(Verdict::OK, $finding->verdict());
    }

    public function testTheMonorepoDatesTheBranchAndTheFindingSaysWhose(): void
    {
        // 8.x last released 2024-11-20 (laravel/framework v8.83.29), 1.8 years before the fixed now:
        // above a one-year threshold, below the default three, which is what the CHANGELOG states.
        $report = $this->report(MonorepoParents::load(), new Thresholds(1, 1));
        $finding = self::contracts($report);
        $s8 = self::signal($finding, Signal::S8);

        self::assertNotNull($s8);
        self::assertSame(Verdict::LEFT_BEHIND, $finding->verdict());
        self::assertStringContainsString('branch 8.x last released 2024-11-20 (1.8 years ago, dated by laravel/framework)', $s8->summary());
        self::assertSame('laravel/framework', $s8->data()['dated_by']);
        self::assertSame([], $report->notes(), 'the parent loads from the same repositories: nothing to report');
        self::assertFalse($report->hadNetworkFailures());
        self::assertSame(1, $report->packagesChecked(), 'the parent is not a package of the lock');
    }

    public function testUnderTheDefaultThresholdsTheDatedBranchIsSimplyNotOldEnough(): void
    {
        $report = $this->report(MonorepoParents::load(), new Thresholds());
        $finding = self::contracts($report);

        self::assertNull(self::signal($finding, Signal::S8), '1.8 years is under release-warn-years 3');
        self::assertNull(self::signal($finding, Signal::S2));
        self::assertSame(Verdict::OK, $finding->verdict(), 'measured, and the answer is that the branch still gets releases');
    }

    /**
     * The lock dates v8.83.27 at 2022-01-13 — the commit its 31 tags share; laravel/framework
     * v8.83.27 released 2022-12-08. The package dates its own newest release (v13.32.0,
     * 2026-09-06, a commit two tags share) so nothing else in the finding needs the parent, and
     * without one the lock's date would be read as the release's: 4.65 libyears for 3.75.
     */
    public function testTheMonorepoDatesTheInstalledVersionForLibyears(): void
    {
        $withParent = self::contracts($this->report(MonorepoParents::load(), new Thresholds()));
        $withoutParent = self::contracts($this->report(MonorepoParents::none(), new Thresholds()));

        $expected = ((new \DateTimeImmutable('2026-09-06T21:12:06+00:00'))->getTimestamp() - (new \DateTimeImmutable('2022-12-08T15:28:55+00:00'))->getTimestamp()) / Clock::SECONDS_PER_YEAR;
        self::assertNotNull($withParent->libyears());
        self::assertEqualsWithDelta($expected, $withParent->libyears(), 0.0001);
        self::assertEqualsWithDelta(3.75, $withParent->libyears(), 0.005);
        self::assertNotNull($withoutParent->libyears());
        self::assertEqualsWithDelta(4.65, $withoutParent->libyears(), 0.005, 'without a parent the lock\'s date is all there is, and it is the shared commit\'s');
    }
}
