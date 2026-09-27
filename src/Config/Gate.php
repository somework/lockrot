<?php

declare(strict_types=1);

namespace Lockrot\Config;

use Lockrot\Analyzer\Report;
use Lockrot\Verdict\FailOn;

/**
 * Whether a run fails, and where each finding stands against fail-on: the one decision behind the
 * exit code of `composer lockrot`, the `gate` its json and html documents write, and the install-time
 * block. {@see Policy::exitCode()} and the report read it; nothing else composes the same rule.
 *
 * A finding reaches fail-on by {@see FailOn::reaches()}. It is exempt when the baseline already
 * accepted it (`known`; a worsened finding is measured like a new one), and it fails the run when it
 * reaches, nothing exempts it and the run judges findings at all, which a `--generate-baseline` run
 * does not. The run fails when a finding does, or when --strict-network is on and a lookup failed;
 * both causes are recorded when both hold.
 *
 * The annotation level the machine formats print ({@see \Lockrot\Output\FormatContext::levelOf()})
 * reads the same two primitives by a rule of its own: `error` exactly where a finding reaches and the
 * baseline did not accept it, in either mode.
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

    /** @return list<string> the causes, in the order of {@see TRIPS}; empty exactly when the run passes */
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
