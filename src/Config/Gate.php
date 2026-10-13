<?php

declare(strict_types=1);

namespace Lockrot\Config;

use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Output\RecordMarks;
use Lockrot\Output\RunGateText;
use Lockrot\Score\Standing;
use Lockrot\Score\Term;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\FailOnValue;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\ScoreModel;
use Lockrot\Verdict\SecurityStanding;

/**
 * The decision behind the exit code of `composer lockrot`, the `gate` of the json and html
 * reports, and the install-time block. The rules are in docs/schema.md#the-gate.
 *
 * A grade value reads the gate score, a flag value the flags that the baseline does not cover, a
 * `vulnerable` value the advisories that the baseline does not know, and `unchecked` S10. A finding
 * that reaches a value and does not fail it, under a baseline entry, is exempt.
 *
 * @internal
 *
 * @phpstan-type Shape array{fails: bool, tripped_by: list<string>, fail_on_applied: bool, reaching: int, failing: int, exempt: array{baseline: int}, failing_by: list<GateValueFailing>, not_applied: list<GateNotApplied>, text: string}
 */
final class Gate
{
    public const MODE_CHECK = 'check';
    public const MODE_GENERATE_BASELINE = 'generate_baseline';
    /** What a run can be asked to do, as `run.mode` writes it. */
    public const MODES = [self::MODE_CHECK, self::MODE_GENERATE_BASELINE];

    public const TRIP_STRICT_NETWORK = 'strict_network';
    public const TRIP_FAIL_ON = 'fail_on';
    /** What can fail a run, in the order `gate.tripped_by` lists them. */
    public const TRIPS = [self::TRIP_STRICT_NETWORK, self::TRIP_FAIL_ON];

    public const EXEMPT_BASELINE = 'baseline';
    /** What can keep a finding that reaches fail-on from failing the run, as a finding's `gate.exempt_by` writes it. */
    public const EXEMPTIONS = [self::EXEMPT_BASELINE];

    /** @var list<string> */
    private array $trippedBy;
    private bool $failOnApplied;
    /** @var list<GateStanding> */
    private array $standings;
    private FailOn $failOn;
    /** @var list<GateNotApplied> */
    private array $notApplied;
    /** @var array<string, GateStanding> by package name */
    private array $byPackage = [];

    /**
     * @param list<string>         $trippedBy
     * @param list<GateStanding>   $standings
     * @param list<GateNotApplied> $notApplied
     */
    private function __construct(array $trippedBy, bool $failOnApplied, array $standings, FailOn $failOn, array $notApplied)
    {
        $this->trippedBy = $trippedBy;
        $this->failOnApplied = $failOnApplied;
        $this->standings = $standings;
        $this->failOn = $failOn;
        $this->notApplied = $notApplied;
    }

    /**
     * Every finding is read, with no stop at the first that fails: the document records each one.
     * A `--generate-baseline` run judges no finding: each keeps what it reaches and whether the
     * baseline read before the write exempts it.
     *
     * @throws \InvalidArgumentException for a mode not in {@see MODES}
     */
    public static function decide(Report $report, FailOn $failOn, bool $strictNetwork, string $mode): self
    {
        if (!\in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException(\sprintf('no gate for a run in mode "%s"', $mode));
        }
        $applied = $mode === self::MODE_CHECK;
        $baseline = $report->baseline();
        $standings = [];
        $byPackage = [];
        $anyFails = false;
        foreach ($report->findings() as $finding) {
            $standing = self::judge($finding, $baseline === null ? null : $baseline->standingOf($finding->package()), $failOn->values(), $applied);
            $standings[] = $standing;
            $byPackage[$finding->package()] = $standing;
            $anyFails = $anyFails || $standing->fails();
        }
        $trippedBy = [];
        if ($strictNetwork && $report->hadNetworkFailures()) {
            $trippedBy[] = self::TRIP_STRICT_NETWORK;
        }
        if ($anyFails) {
            $trippedBy[] = self::TRIP_FAIL_ON;
        }

        $gate = new self($trippedBy, $applied, $standings, $failOn, $applied ? self::notApplied($report->findings(), $failOn) : []);
        $gate->byPackage = $byPackage;

        return $gate;
    }

    /** @param list<FailOnValue> $values */
    private static function judge(Finding $finding, ?Standing $baseline, array $values, bool $applied): GateStanding
    {
        $score = $finding->score();
        $counted = array_map(static fn (Term $term): string => $term->flag(), $score->maintenanceTerms());
        $gate = $baseline === null ? null : $baseline->gateScore($score);
        $missing = $finding->checksMissing();
        $by = RecordMarks::of(
            $score,
            $gate,
            $baseline === null ? $counted : $baseline->uncoveredFlags(),
            $baseline === null ? $finding->flags()->advisories() : $baseline->unknownAdvisories(),
            $missing,
            $values
        );
        $reaches = false;
        foreach ($values as $value) {
            $reaches = $reaches || self::reaches($finding, $counted, $value, $missing !== []);
        }
        $exemptBy = $reaches && $by === [] && $gate !== null ? self::EXEMPT_BASELINE : null;

        return new GateStanding($reaches, $applied && $by !== [], $exemptBy, $applied ? $by : [], $gate);
    }

    /**
     * Before the baseline, as 0.13 read it: the score's band, any counted flag at or above the
     * value, any counted advisory at or above it, or S10.
     *
     * @param list<string> $counted the counted maintenance flags
     */
    private static function reaches(Finding $finding, array $counted, FailOnValue $value, bool $unchecked): bool
    {
        $total = $finding->score()->total();
        switch ($value->kind()) {
            case FailOn::KIND_UNCHECKED:
                return $unchecked;
            case FailOn::KIND_FLAG:
                $limit = array_search($value->threshold(), ScoreModel::FLAG_ORDER, true);

                return array_filter($counted, static fn (string $flag): bool => array_search($flag, ScoreModel::FLAG_ORDER, true) <= $limit) !== [];
            case FailOn::KIND_VULNERABLE:
                return $total > 0 && RecordMarks::anyAtOrAbove($finding->flags()->advisories(), (string) $value->threshold());
            default:
                return $total >= ScoreModel::floorOf((string) $value->threshold());
        }
    }

    /**
     * The advisories a grade or `vulnerable` value could not read, one entry per reason (SPEC §6.2,
     * "Fail-open, visibly"). With `unchecked` active, a finding whose advisories carry S10 fails
     * closed on it, so only a skip or an accepted `no_feed` is a gap.
     *
     * @param list<Finding> $findings
     *
     * @return list<GateNotApplied> by `packages` descending, then by reason
     */
    private static function notApplied(array $findings, FailOn $failOn): array
    {
        if (!$failOn->has(FailOn::KIND_GRADE) && !$failOn->has(FailOn::KIND_VULNERABLE)) {
            return [];
        }
        $byReason = [];
        foreach ($findings as $finding) {
            if ($finding->securityStanding()->status() !== SecurityStanding::UNCHECKED) {
                continue;
            }
            if ($failOn->has(FailOn::KIND_UNCHECKED) && self::advisoriesInS10($finding)) {
                continue;
            }
            $reason = (string) $finding->details()->uncheckedReason();
            $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
        }
        uksort($byReason, static fn ($a, $b): int => $byReason[$b] <=> $byReason[$a] ?: strcmp((string) $a, (string) $b));
        $out = [];
        foreach ($byReason as $reason => $packages) {
            $out[] = new GateNotApplied('advisories', $packages, (string) $reason);
        }

        return $out;
    }

    private static function advisoriesInS10(Finding $finding): bool
    {
        foreach ($finding->checksMissing() as $check) {
            if ($check->check() === 'advisories') {
                return true;
            }
        }

        return false;
    }

    public function fails(): bool
    {
        return $this->trippedBy !== [];
    }

    /** @return list<string> the causes in the order of {@see TRIPS}, empty when the run passes */
    public function trippedBy(): array
    {
        return $this->trippedBy;
    }

    /** Whether the run judged its findings against fail-on: false for a `--generate-baseline` run. */
    public function failOnApplied(): bool
    {
        return $this->failOnApplied;
    }

    /** The standing of the finding of that package, null for a package the report does not hold. */
    public function standingOf(string $package): ?GateStanding
    {
        return $this->byPackage[$package] ?? null;
    }

    /** @return list<GateStanding> one per finding, in the order of the report's findings() */
    public function standings(): array
    {
        return $this->standings;
    }

    public function reaching(): int
    {
        return \count(array_filter($this->standings, static fn (GateStanding $standing): bool => $standing->reachesFailOn()));
    }

    public function failing(): int
    {
        return \count(array_filter($this->standings, static fn (GateStanding $standing): bool => $standing->fails()));
    }

    public function exemptByBaseline(): int
    {
        return \count(array_filter($this->standings, static fn (GateStanding $standing): bool => $standing->exemptBy() === self::EXEMPT_BASELINE));
    }

    /** @return list<GateValueFailing> each `run.gates[]` value with the findings that fail it, in its order, zeros included */
    public function failingBy(): array
    {
        $out = [];
        foreach ($this->failOn->values() as $value) {
            $out[] = new GateValueFailing($value->value(), \count(array_filter($this->standings, static fn (GateStanding $standing): bool => $standing->failsValue($value->value()))));
        }

        return $out;
    }

    /** @return list<GateNotApplied> */
    public function notAppliedEntries(): array
    {
        return $this->notApplied;
    }

    /**
     * The root `gate` of report-2, with the `gate:` line rendered from it and the run.
     *
     * @return Shape
     */
    public function toArray(RunSettings $run): array
    {
        return [
            'fails' => $this->fails(),
            'tripped_by' => $this->trippedBy,
            'fail_on_applied' => $this->failOnApplied,
            'reaching' => $this->reaching(),
            'failing' => $this->failing(),
            'exempt' => ['baseline' => $this->exemptByBaseline()],
            'failing_by' => $this->failingBy(),
            'not_applied' => $this->notApplied,
            'text' => RunGateText::render($this, $run),
        ];
    }
}
