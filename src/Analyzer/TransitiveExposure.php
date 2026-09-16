<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Graph\DependencyGraph;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;

/**
 * The parent-side view of transitive rot — signal S7.
 *
 * A maintainer can only act on what composer.json names, so once every verdict is known, each
 * direct requirement whose subtree holds flagged packages gets an `info` signal listing them, with
 * the shortest chain from that requirement to each. The signal is attached after the verdicts are
 * decided and {@see \Lockrot\Verdict\VerdictEngine} never reads it: a package is not flagged for
 * what it depends on, only described. Priority, exit code and baseline are equally untouched.
 *
 * "Flagged" is the same line every other part of the report draws ({@see Verdict::flagged()}), so
 * the packages a parent is said to pull in are exactly the rows the default report prints.
 */
final class TransitiveExposure
{
    /** Names spelled out in the signal summary before the rest is counted. The data carries them all. */
    public const SUMMARY_NAMES = 5;

    /**
     * A flagged transitive package reached from more direct requirements than this is shared
     * infrastructure — in a framework application its framework's own contracts, reached from every
     * bundle — and nobody's to remove, so it is attributed to no parent. On a 200-package Symfony
     * lock the fan-in of flagged transitive packages was 1 or 2 for 32 of 37, then 6, 8, 16, 33 and
     * 44: the cap sits in the gap. The package keeps its own row, with `also via … and N more`, and
     * `direct_dependents` in the JSON document names every parent.
     */
    public const MAX_FAN_IN = 8;

    /**
     * Whether a finding is exposure some direct requirement is answerable for: flagged, transitive,
     * and reached from at least one and at most {@see MAX_FAN_IN} direct requirements. The one rule
     * behind S7 and {@see Report::exposure()}, so the signal's count and the `pulled in by:` line
     * always agree.
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
     * The same findings, with S7 attached to every direct requirement that pulls in an
     * {@see attributable()} one. A flagged package the project requires directly is nobody's
     * exposure, whoever else reaches it, and neither is one every bundle reaches
     * ({@see Report::exposure()} draws the same line). Only findings in $findings can be annotated:
     * at install time that is the transaction, so a parent left untouched by the transaction is
     * not in the list and gets nothing.
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
            $result[] = $descendants === [] ? $finding : $finding->withSignals(array_merge($finding->signals(), [self::signal($descendants)]));
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

    /**
     * `pulls in 3 flagged packages: a (abandoned), b (silent), c (stale)`, the tail counted once the
     * named ones would stop being readable as a line of evidence.
     *
     * @param non-empty-list<array{package: string, verdict: string, chain: list<string>}> $packages
     */
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
