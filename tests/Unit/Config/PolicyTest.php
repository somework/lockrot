<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Config;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineEntry;
use Lockrot\Config\LockrotConfig;
use Lockrot\Config\Policy;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase
{
    /** @param list<string> $verdicts */
    private function report(array $verdicts, bool $networkFailures = false): Report
    {
        $findings = [];
        foreach ($verdicts as $i => $verdict) {
            $findings[] = new Finding('v/p'.$i, '1.0.0', $verdict, [], ['v/p'.$i], null, null);
        }

        return new Report($findings, [], new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), \count($findings), 0, $networkFailures);
    }

    private function config(string $failOn, bool $strict = false): LockrotConfig
    {
        return LockrotConfig::fromSources([], [], ['fail-on' => $failOn, 'strict-network' => $strict], '8.4.0', null);
    }

    public function testNoneNeverFails(): void
    {
        self::assertSame(0, Policy::exitCode($this->report([Verdict::ABANDONED]), $this->config('none')));
    }

    public function testThresholdInclusive(): void
    {
        self::assertSame(1, Policy::exitCode($this->report([Verdict::SILENT]), $this->config('silent')));
        self::assertSame(1, Policy::exitCode($this->report([Verdict::ABANDONED]), $this->config('silent')));
        self::assertSame(0, Policy::exitCode($this->report([Verdict::STALE, Verdict::PINNED]), $this->config('silent')));
        self::assertSame(1, Policy::exitCode($this->report([Verdict::STALE]), $this->config('stale')));
        self::assertSame(0, Policy::exitCode($this->report([Verdict::UNKNOWN, Verdict::FINISHED, Verdict::OK]), $this->config('stale')));
    }

    public function testStrictNetwork(): void
    {
        self::assertSame(0, Policy::exitCode($this->report([], true), $this->config('none')));
        self::assertSame(1, Policy::exitCode($this->report([], true), $this->config('none', true)));
    }

    /**
     * @param list<array{0: string, 1: string}> $baselined package, verdict
     * @param list<array{0: string, 1: string}> $found     package, verdict
     */
    private function baselinedReport(array $baselined, array $found, bool $networkFailures = false): Report
    {
        $at = new \DateTimeImmutable('2026-09-14T00:00:00+00:00');
        $findings = [];
        $names = [];
        foreach ($found as [$package, $verdict]) {
            $findings[] = new Finding($package, '1.0.0', $verdict, [], [$package], null, $at);
            $names[] = $package;
        }
        $entries = [];
        foreach ($baselined as [$package, $verdict]) {
            $entries[] = new BaselineEntry($package, '1.0.0', $verdict, '2026-01-15');
        }
        $report = new Report($findings, [], $at, \count($findings), 0, $networkFailures);

        return $report->withBaseline(BaselineComparison::compare(
            Baseline::of($entries, '2026-09-14T00:00:00+00:00'),
            $report,
            'lockrot-baseline.json',
            $names
        ));
    }

    public function testABaselinedFindingDoesNotTripFailOn(): void
    {
        $report = $this->baselinedReport([['a/known', Verdict::ABANDONED]], [['a/known', Verdict::ABANDONED]]);

        self::assertSame(0, Policy::exitCode($report, $this->config('silent')));
    }

    public function testANewFindingTripsFailOn(): void
    {
        $report = $this->baselinedReport([['a/known', Verdict::ABANDONED]], [
            ['a/known', Verdict::ABANDONED],
            ['a/fresh', Verdict::SILENT],
        ]);

        self::assertSame(1, Policy::exitCode($report, $this->config('silent')));
    }

    public function testAWorsenedFindingTripsFailOn(): void
    {
        $report = $this->baselinedReport([['a/known', Verdict::STALE]], [['a/known', Verdict::ABANDONED]]);

        self::assertSame(1, Policy::exitCode($report, $this->config('silent')));
    }

    /** A worsened finding below the threshold is still below it: the baseline narrows what is compared, it does not lower the bar. */
    public function testAWorsenedFindingBelowTheThresholdStillExitsZero(): void
    {
        $report = $this->baselinedReport([['a/known', Verdict::STALE]], [['a/known', Verdict::OLD_PROMISE]]);

        self::assertSame(0, Policy::exitCode($report, $this->config('silent')));
    }

    public function testStaleBaselineEntriesNeverTripFailOn(): void
    {
        $report = $this->baselinedReport([['a/gone', Verdict::ABANDONED]], []);

        self::assertSame(0, Policy::exitCode($report, $this->config('stale')));
    }

    public function testStrictNetworkStillWinsOverABaselineThatCoversEverything(): void
    {
        $report = $this->baselinedReport([['a/known', Verdict::ABANDONED]], [['a/known', Verdict::ABANDONED]], true);

        self::assertSame(1, Policy::exitCode($report, $this->config('none', true)));
    }
}
