<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Config;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineEntry;
use Lockrot\Config\Gate;
use Lockrot\Config\LockrotConfig;
use Lockrot\Config\Policy;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\Notes;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

/**
 * {@see Gate::decide()} decides a run's pass or fail and each finding's standing against fail-on.
 * Over every fail-on value, every baseline standing, both --strict-network settings, both network
 * outcomes and both modes, the gate gives the exit code of `oracleExitCode()` and
 * `oracleGenerateExitCode()`, and its fields hold to one another.
 */
final class GateTest extends TestCase
{
    private const AT = '2026-09-14T00:00:00+00:00';
    private const BASELINES = ['none', 'known', 'new', 'worsened'];

    public function testTheVocabulariesAreTheOnesTheSchemaLists(): void
    {
        self::assertSame(['check', 'generate_baseline'], Gate::MODES);
        self::assertSame(['strict_network', 'fail_on'], Gate::TRIPS);
        self::assertSame(['baseline'], Gate::EXEMPTIONS);
    }

    public function testTheGateGivesTheExitCodesItReplacedOverTheWholeMatrix(): void
    {
        $cases = 0;
        $seen = [];
        foreach (FailOn::allowed() as $failOnValue) {
            foreach (self::BASELINES as $baseline) {
                foreach ([false, true] as $strict) {
                    foreach ([false, true] as $networkFailures) {
                        $report = self::report($baseline, $networkFailures);
                        $config = LockrotConfig::fromSources([], [], ['fail-on' => $failOnValue, 'strict-network' => $strict], '8.4.0', null);
                        $failOn = FailOn::fromString($failOnValue);
                        $what = \sprintf('fail-on=%s baseline=%s strict=%s network_failures=%s', $failOnValue, $baseline, var_export($strict, true), var_export($networkFailures, true));

                        $check = Gate::decide($report, $failOn, $strict, Gate::MODE_CHECK);
                        self::assertSame(self::oracleExitCode($report, $config), $check->fails() ? 1 : 0, $what.': check');
                        self::assertSame(self::oracleExitCode($report, $config), Policy::exitCode($report, $config), $what.': Policy');
                        $this->holds($check, $report, $failOn, $strict, $what.' check');

                        $generate = Gate::decide($report, $failOn, $strict, Gate::MODE_GENERATE_BASELINE);
                        self::assertSame(self::oracleGenerateExitCode($report, $config), $generate->fails() ? 1 : 0, $what.': generate');
                        $this->holds($generate, $report, $failOn, $strict, $what.' generate');

                        foreach ([$check, $generate] as $gate) {
                            foreach ($gate->standings() as $standing) {
                                $seen[json_encode($standing->toArray())] = true;
                            }
                            $seen[json_encode($gate->trippedBy())] = true;
                        }
                        ++$cases;
                    }
                }
            }
        }

        self::assertSame(\count(FailOn::allowed()) * 4 * 2 * 2, $cases);
        foreach ([
            '{"reaches_fail_on":true,"fails":true,"exempt_by":null}',
            '{"reaches_fail_on":true,"fails":false,"exempt_by":"baseline"}',
            '{"reaches_fail_on":true,"fails":false,"exempt_by":null}',
            '{"reaches_fail_on":false,"fails":false,"exempt_by":null}',
            '[]',
            '["strict_network"]',
            '["fail_on"]',
            '["strict_network","fail_on"]',
        ] as $shape) {
            self::assertArrayHasKey($shape, $seen, 'the matrix gives '.$shape.', or the equivalence proves little');
        }
    }

    /** A worsened finding is measured like a new one: the baseline narrows what is compared, it does not exempt what got worse. */
    public function testAWorsenedFindingIsNeverExempt(): void
    {
        $report = self::report('worsened', false);
        $gate = Gate::decide($report, FailOn::fromString(Verdict::SILENT), false, Gate::MODE_CHECK);

        foreach ($report->findings() as $i => $finding) {
            $standing = $gate->standings()[$i];
            $status = $report->baseline() === null ? null : $report->baseline()->statusOf($finding->package());
            if ($status === 'worsened') {
                self::assertNull($standing->exemptBy(), $finding->package());
                self::assertSame($standing->reachesFailOn(), $standing->fails(), $finding->package());
            }
        }
        self::assertSame(['fail_on'], $gate->trippedBy(), 'abandoned was accepted at stale and reaches silent');
    }

    /** Both causes are recorded when both hold: the gate does not stop at the first. */
    public function testBothCausesAreRecordedInTheirOrder(): void
    {
        $gate = Gate::decide(self::report('none', true), FailOn::fromString(Verdict::STALE), true, Gate::MODE_CHECK);

        self::assertTrue($gate->fails());
        self::assertSame(['strict_network', 'fail_on'], $gate->trippedBy());
        self::assertSame(['fails' => true, 'tripped_by' => ['strict_network', 'fail_on'], 'fail_on_applied' => true], $gate->toArray());
    }

    /** `--generate-baseline` judges nothing: a finding still reaches fail-on, and fails nothing. */
    public function testAGenerateRunReachesButDoesNotFail(): void
    {
        $gate = Gate::decide(self::report('none', false), FailOn::fromString(Verdict::STALE), false, Gate::MODE_GENERATE_BASELINE);

        self::assertSame(['fails' => false, 'tripped_by' => [], 'fail_on_applied' => false], $gate->toArray());
        self::assertSame(['reaches_fail_on' => true, 'fails' => false, 'exempt_by' => null], $gate->standings()[0]->toArray());
    }

    /** Under `unchecked` an ok finding carrying S10 fails: no baseline can accept it, since the baseline holds flagged findings only. */
    public function testAnOkFindingThatWasNotCheckedFailsUnderUnchecked(): void
    {
        $report = self::report('known', false);
        $gate = Gate::decide($report, FailOn::fromString(FailOn::UNCHECKED), false, Gate::MODE_CHECK);
        $failing = [];
        foreach ($report->findings() as $i => $finding) {
            if ($gate->standings()[$i]->fails()) {
                $failing[] = $finding->package();
            }
        }

        self::assertSame(['v/ok-unchecked'], $failing);
    }

    public function testAModeTheGateDoesNotKnowIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Gate::decide(self::report('none', false), FailOn::none(), false, 'pull_request');
    }

    private function holds(Gate $gate, Report $report, FailOn $failOn, bool $strict, string $what): void
    {
        $standings = $gate->standings();
        self::assertCount(\count($report->findings()), $standings, $what);
        $anyFails = false;
        foreach ($report->findings() as $i => $finding) {
            $standing = $standings[$i];
            $where = $what.' '.$finding->package();
            self::assertSame($failOn->reaches($finding), $standing->reachesFailOn(), $where);
            self::assertSame($standing->reachesFailOn() && $standing->exemptBy() === null && $gate->failOnApplied(), $standing->fails(), $where.': fails');
            if ($standing->exemptBy() !== null) {
                self::assertTrue($standing->reachesFailOn(), $where.': only what reaches is exempt');
            }
            $known = $report->baseline() !== null && $report->baseline()->statusOf($finding->package()) === 'known';
            self::assertSame($known && $standing->reachesFailOn(), $standing->exemptBy() === Gate::EXEMPT_BASELINE, $where.': exempt by the baseline');
            $anyFails = $anyFails || $standing->fails();
        }
        $trippedBy = $gate->trippedBy();
        self::assertSame($gate->fails(), $trippedBy !== [], $what);
        self::assertSame($anyFails, \in_array(Gate::TRIP_FAIL_ON, $trippedBy, true), $what);
        self::assertSame($strict && $report->hadNetworkFailures(), \in_array(Gate::TRIP_STRICT_NETWORK, $trippedBy, true), $what);
        self::assertSame(array_values(array_filter(Gate::TRIPS, static fn (string $trip): bool => \in_array($trip, $trippedBy, true))), $trippedBy, $what.': unique, in the constants\' order');
        if (!$gate->failOnApplied()) {
            self::assertFalse($anyFails, $what);
        }
        self::assertSame(['fails' => $gate->fails(), 'tripped_by' => $trippedBy, 'fail_on_applied' => $gate->failOnApplied()], $gate->toArray(), $what);
    }

    /**
     * One finding of every flagged verdict, direct and transitive, a development one, an unflagged
     * one with priority none, and an ok one carrying S10. The baseline is one of four: none read,
     * every flagged finding known, none known (every one new), and every one accepted at `stale`
     * (so the stale one is known and every other worsened).
     */
    private static function report(string $baseline, bool $networkFailures): Report
    {
        $at = new \DateTimeImmutable(self::AT);
        $findings = [];
        foreach (Verdict::all() as $verdict) {
            $findings[] = (new FindingBuilder())->withPackage('v/'.$verdict)->withVerdict($verdict)->withChain(['v/'.$verdict])->withDataDate($at)->build();
            $findings[] = (new FindingBuilder())->withPackage('v/'.$verdict.'-deep')->withVerdict($verdict)->withChain(['v/root', 'v/'.$verdict.'-deep'])->withDataDate($at)->withDev(true)->build();
        }
        $notChecked = new Signal(Signal::S10, Signal::LEVEL_INFO, 'repository activity not checked', ['unchecked' => [], 'blocks' => ['S3', 'S4']]);
        $findings[] = (new FindingBuilder())->withPackage('v/ok-unchecked')->withSignals([$notChecked])->withChain(['v/ok-unchecked'])->withDataDate($at)->build();
        $report = new Report($findings, $networkFailures ? [Notes::text('a lookup failed', true)] : [], $at, \count($findings), 0);
        if ($baseline === 'none') {
            return $report;
        }
        $entries = [];
        foreach ($report->flagged() as $finding) {
            if ($baseline === 'known') {
                $entries[] = new BaselineEntry($finding->package(), '1.0.0', $finding->verdict(), '2026-01-15');
            } elseif ($baseline === 'worsened') {
                $entries[] = new BaselineEntry($finding->package(), '1.0.0', Verdict::STALE, '2026-01-15');
            }
        }
        $names = array_map(static fn (Finding $finding): string => $finding->package(), $findings);

        return $report->withBaseline(BaselineComparison::compare(Baseline::of($entries, self::AT), $report, 'lockrot-baseline.json', $names));
    }

    /** Oracle: the exit code of a check run, as a loop over the findings. */
    private static function oracleExitCode(Report $report, LockrotConfig $config): int
    {
        if ($config->strictNetwork() && $report->hadNetworkFailures()) {
            return 1;
        }
        $threshold = FailOn::fromString($config->failOn());
        if ($threshold->isNone()) {
            return 0;
        }
        $baseline = $report->baseline();
        foreach ($report->findings() as $finding) {
            if ($baseline !== null && $baseline->isKnown($finding->package())) {
                continue;
            }
            if ($threshold->reaches($finding)) {
                return 1;
            }
        }

        return 0;
    }

    /** Oracle: the exit code of a `--generate-baseline` run. */
    private static function oracleGenerateExitCode(Report $report, LockrotConfig $config): int
    {
        return $config->strictNetwork() && $report->hadNetworkFailures() ? 1 : 0;
    }
}
