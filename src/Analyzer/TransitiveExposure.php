<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Graph\DependencyGraph;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;

/**
 * Signal S7, the parent-side view of transitive exposure (docs/verdicts.md#transitive-exposure).
 * The signal is attached after the verdicts are decided and {@see \Lockrot\Verdict\VerdictEngine}
 * never reads it: priority, exit code and baseline stay untouched.
 *
 * @internal
 */
final class TransitiveExposure
{
    /** Names spelled out in the signal summary before the rest is counted. The data carries them all. */
    public const SUMMARY_NAMES = 5;

    /**
     * A flagged transitive package reached from more direct requirements than this is shared
     * infrastructure, such as a framework's contracts reached from every bundle, and counts under
     * no parent: docs/verdicts.md#shared-packages-and-unattributed. The JSON document states the
     * cap as `exposure_rule.max_fan_in`.
     */
    public const MAX_FAN_IN = 8;

    /**
     * Whether a direct requirement answers for the finding: flagged, transitive, and reached from
     * one to {@see MAX_FAN_IN} direct requirements. S7 and {@see Report::exposure()} share this
     * rule, so their counts agree.
     */
    public static function attributable(Finding $finding): bool
    {
        $parents = \count($finding->directDependents());

        return Verdict::flagged($finding->verdict())
            && !$finding->isDirect()
            && $parents > 0
            && $parents <= self::MAX_FAN_IN;
    }

    /**
     * The complement of {@see attributable()} above the cap: flagged, transitive, and reached from
     * more than {@see MAX_FAN_IN} direct requirements. A flagged transitive package that no direct
     * requirement reaches has no fan-in and is neither.
     */
    public static function sharedAboveCap(Finding $finding): bool
    {
        return Verdict::flagged($finding->verdict())
            && !$finding->isDirect()
            && \count($finding->directDependents()) > self::MAX_FAN_IN;
    }

    /**
     * Attaches S7 to every direct requirement that pulls in an {@see attributable()} finding. Only
     * findings in $findings can carry it, so at install time a parent outside the transaction gets
     * none.
     *
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    public static function attach(array $findings, DependencyGraph $graph): array
    {
        $exposed = self::byParent($findings, $graph);
        $result = [];
        foreach ($findings as $finding) {
            $descendants = $exposed[$finding->package()] ?? [];
            if ($descendants === []) {
                $result[] = $finding;
                continue;
            }
            // Sort by number, as SignalSet does: S7 belongs between S6 and S8.
            $signals = array_merge($finding->signals(), [self::signal($descendants)]);
            usort($signals, static fn (Signal $a, Signal $b): int => strnatcmp($a->id(), $b->id()));
            $result[] = $finding->withSignals($signals);
        }

        return $result;
    }

    /**
     * Parent => the attributable findings reachable from it, each paired with the shortest chain
     * from the parent, in report order.
     *
     * @param list<Finding> $findings
     *
     * @return array<string, list<array{Finding, list<string>}>>
     */
    private static function byParent(array $findings, DependencyGraph $graph): array
    {
        $flagged = array_filter($findings, [self::class, 'attributable']);
        usort($flagged, [Report::class, 'compare']);
        $byParent = [];
        foreach ($flagged as $finding) {
            foreach ($graph->chainsTo($finding->package()) as $parent => $chain) {
                $byParent[$parent][] = [$finding, $chain];
            }
        }

        return $byParent;
    }

    /** @param non-empty-list<array{Finding, list<string>}> $descendants */
    private static function signal(array $descendants): Signal
    {
        $packages = [];
        foreach ($descendants as [$finding, $chain]) {
            $packages[] = ['package' => $finding->package(), 'verdict' => $finding->verdict(), 'chain' => $chain];
        }

        return new Signal(Signal::S7, Signal::LEVEL_INFO, self::summary($packages), ['flagged' => \count($packages), 'packages' => $packages]);
    }

    /** @param non-empty-list<array{package: string, verdict: string, chain: list<string>}> $packages */
    private static function summary(array $packages): string
    {
        $count = \count($packages);
        $named = [];
        foreach (\array_slice($packages, 0, self::SUMMARY_NAMES) as $package) {
            $named[] = $package['package'].' ('.$package['verdict'].')';
        }
        $summary = \sprintf('pulls in %d flagged %s: %s', $count, $count === 1 ? 'package' : 'packages', implode(', ', $named));
        if ($count > self::SUMMARY_NAMES) {
            $summary .= \sprintf(' and %d more', $count - self::SUMMARY_NAMES);
        }

        return $summary;
    }
}
