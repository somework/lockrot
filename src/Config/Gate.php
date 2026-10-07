<?php

declare(strict_types=1);

namespace Lockrot\Config;

use Lockrot\Analyzer\Report;
use Lockrot\Verdict\FailOn;

/**
 * The decision behind the exit code of `composer lockrot`, the `gate` of the json and html
 * reports, and the install-time block. The rules are in docs/schema.md#the-gate.
 *
 * The annotation level of the machine formats ({@see \Lockrot\Output\FormatContext::levelOf()})
 * reads {@see FailOn::reaches()} and the baseline's `known` status by a rule of its own:
 * docs/ci.md#how-each-format-marks-a-finding.
 *
 * @internal
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

    /**
     * @param list<string>       $trippedBy
     * @param list<GateStanding> $standings
     */
    private function __construct(array $trippedBy, bool $failOnApplied, array $standings)
    {
        $this->trippedBy = $trippedBy;
        $this->failOnApplied = $failOnApplied;
        $this->standings = $standings;
    }

    /**
     * Every finding is read, with no stop at the first that fails: the document records each one.
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
        $anyFails = false;
        foreach ($report->findings() as $finding) {
            $reaches = $failOn->reaches($finding);
            $exemptBy = $reaches && $baseline !== null && $baseline->isKnown($finding->package()) ? self::EXEMPT_BASELINE : null;
            $fails = $reaches && $exemptBy === null && $applied;
            $standings[] = new GateStanding($reaches, $fails, $exemptBy);
            $anyFails = $anyFails || $fails;
        }
        $trippedBy = [];
        if ($strictNetwork && $report->hadNetworkFailures()) {
            $trippedBy[] = self::TRIP_STRICT_NETWORK;
        }
        if ($anyFails) {
            $trippedBy[] = self::TRIP_FAIL_ON;
        }

        return new self($trippedBy, $applied, $standings);
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

    /** @return list<GateStanding> one per finding, in the order of the report's findings() */
    public function standings(): array
    {
        return $this->standings;
    }

    /** @return array{fails: bool, tripped_by: list<string>, fail_on_applied: bool} */
    public function toArray(): array
    {
        return [
            'fails' => $this->fails(),
            'tripped_by' => $this->trippedBy,
            'fail_on_applied' => $this->failOnApplied,
        ];
    }
}
