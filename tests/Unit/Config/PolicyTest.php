<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Config;

use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\Gate;
use Lockrot\Config\Policy;
use Lockrot\Tests\Support\BaselineEntries;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\Notes;
use Lockrot\Verdict\FailOn;
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
            $findings[] = (new FindingBuilder())->withPackage('v/p'.$i)->withVerdict($verdict)->withChain(['v/p'.$i])->build();
        }

        return new Report($findings, $networkFailures ? [Notes::text('a lookup failed', true)] : [], new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), \count($findings), 0);
    }

    /** The exit code of the gate that the run's documents write, as the command computes it. */
    private static function exitCode(Report $report, string $failOn, bool $strict = false, string $mode = Gate::MODE_CHECK): int
    {
        $gate = $report->withRun(new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::parse($failOn), RunSettings::SOURCE_OPTION, null, null, $strict, $mode))->gate();
        self::assertNotNull($gate);

        return Policy::exitCode($gate);
    }

    public function testFailOnNoneExitsZeroOnAnAbandonedFinding(): void
    {
        self::assertSame(0, self::exitCode($this->report([Verdict::ABANDONED]), 'none'));
    }

    public function testAVerdictThresholdFailsOnItsOwnVerdictAndAbove(): void
    {
        self::assertSame(1, self::exitCode($this->report([Verdict::SILENT]), 'silent'));
        self::assertSame(1, self::exitCode($this->report([Verdict::ABANDONED]), 'silent'));
        self::assertSame(0, self::exitCode($this->report([Verdict::STALE, Verdict::PINNED]), 'silent'));
        self::assertSame(1, self::exitCode($this->report([Verdict::STALE]), 'stale'));
        self::assertSame(0, self::exitCode($this->report([Verdict::UNKNOWN, Verdict::FINISHED, Verdict::OK]), 'stale'));
    }

    /**
     * A priority threshold reads where the package sits in the project, not only what was observed:
     * the same `abandoned` verdict is `critical` on a direct production requirement and `medium` on
     * a transitive development one.
     */
    public function testAPriorityThresholdReadsThePriorityNotTheVerdict(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T00:00:00+00:00');
        $directProd = (new FindingBuilder())->withPackage('a/direct')->withVerdict(Verdict::ABANDONED)->withChain(['a/direct'])->withDataDate($at)->build();
        $transitiveDev = (new FindingBuilder())->withPackage('a/deep')->withVerdict(Verdict::ABANDONED)->withChain(['a/root', 'a/deep'])->withDataDate($at)->withDev(true)->build();
        $report = static fn (Finding ...$findings): Report => new Report(array_values($findings), [], $at, \count($findings), 0);

        self::assertSame(1, self::exitCode($report($directProd), 'critical'));
        self::assertSame(0, self::exitCode($report($transitiveDev), 'critical'));
        self::assertSame(0, self::exitCode($report($transitiveDev), 'high'));
        self::assertSame(1, self::exitCode($report($transitiveDev), 'medium'), 'inclusive, like the verdict threshold');
        self::assertSame(1, self::exitCode($report($transitiveDev), 'low'));
        self::assertSame(1, self::exitCode($report($transitiveDev, $directProd), 'critical'), 'any one finding at the threshold fails the run');
        self::assertSame(0, self::exitCode($this->report([Verdict::UNKNOWN, Verdict::OK]), 'low'), 'an unflagged finding has no priority to reach');
    }

    public function testAnAcceptedFindingNeverFailsAPriorityThresholdEither(): void
    {
        $report = $this->baselinedReport([['a/known', Verdict::ABANDONED]], [['a/known', Verdict::ABANDONED], ['a/fresh', Verdict::STALE]]);

        self::assertSame(0, self::exitCode($report, 'critical'), 'a/known is critical but accepted; a/fresh is medium');
        self::assertSame(1, self::exitCode($report, 'medium'), 'a/fresh is new and medium');
    }

    public function testStrictNetworkFailsARunWithAFailedLookup(): void
    {
        self::assertSame(0, self::exitCode($this->report([], true), 'none'));
        self::assertSame(1, self::exitCode($this->report([], true), 'none', true));
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
            $findings[] = (new FindingBuilder())->withPackage($package)->withVerdict($verdict)->withChain([$package])->withDataDate($at)->build();
            $names[] = $package;
        }
        $entries = [];
        foreach ($baselined as [$package, $verdict]) {
            $entries[] = BaselineEntries::of($package, '1.0.0', $verdict, '2026-01-15');
        }
        $report = new Report($findings, $networkFailures ? [Notes::text('a lookup failed', true)] : [], $at, \count($findings), 0);

        return $report->withBaseline(BaselineComparison::compare(
            BaselineEntries::baseline($entries, '2026-09-14T00:00:00+00:00'),
            $report,
            'lockrot-baseline.json',
            $names
        ));
    }

    public function testABaselinedFindingDoesNotTripFailOn(): void
    {
        $report = $this->baselinedReport([['a/known', Verdict::ABANDONED]], [['a/known', Verdict::ABANDONED]]);

        self::assertSame(0, self::exitCode($report, 'silent'));
    }

    public function testANewFindingTripsFailOn(): void
    {
        $report = $this->baselinedReport([['a/known', Verdict::ABANDONED]], [
            ['a/known', Verdict::ABANDONED],
            ['a/fresh', Verdict::SILENT],
        ]);

        self::assertSame(1, self::exitCode($report, 'silent'));
    }

    public function testAWorsenedFindingTripsFailOn(): void
    {
        $report = $this->baselinedReport([['a/known', Verdict::STALE]], [['a/known', Verdict::ABANDONED]]);

        self::assertSame(1, self::exitCode($report, 'silent'));
    }

    /** A worsened finding below the threshold is still below it: the baseline narrows what is compared, it does not lower the bar. */
    public function testAWorsenedFindingBelowTheThresholdStillExitsZero(): void
    {
        $report = $this->baselinedReport([['a/known', Verdict::STALE]], [['a/known', Verdict::OLD_PROMISE]]);

        self::assertSame(0, self::exitCode($report, 'silent'));
    }

    public function testStaleBaselineEntriesNeverTripFailOn(): void
    {
        $report = $this->baselinedReport([['a/gone', Verdict::ABANDONED]], []);

        self::assertSame(0, self::exitCode($report, 'stale'));
    }

    public function testStrictNetworkStillWinsOverABaselineThatCoversEverything(): void
    {
        $report = $this->baselinedReport([['a/known', Verdict::ABANDONED]], [['a/known', Verdict::ABANDONED]], true);

        self::assertSame(1, self::exitCode($report, 'none', true));
    }

    /** A generate run exits 1 only on a network failure under --strict-network, whatever --fail-on says. */
    public function testAGenerateRunExitsOneOnlyOnAStrictNetworkFailure(): void
    {
        self::assertSame(0, self::exitCode($this->report([Verdict::ABANDONED]), 'abandoned', false, Gate::MODE_GENERATE_BASELINE));
        self::assertSame(0, self::exitCode($this->report([Verdict::ABANDONED], true), 'abandoned', false, Gate::MODE_GENERATE_BASELINE));
        self::assertSame(1, self::exitCode($this->report([], true), 'none', true, Gate::MODE_GENERATE_BASELINE));
    }
}
