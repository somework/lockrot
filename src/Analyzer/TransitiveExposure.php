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
     * The same findings, with S7 attached to every direct requirement that pulls in a flagged one.
     * Only findings in $findings can be annotated: at install time that is the transaction, so a
     * parent left untouched by the transaction is not in the list and gets nothing.
     *
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    public static function attach(array $findings, DependencyGraph $graph): array
    {
        $exposed = self::byParent($findings, $graph);
        if ($exposed === []) {
            return $findings;
        }
        $result = [];
        foreach ($findings as $finding) {
            $descendants = $exposed[$finding->package()] ?? [];
            $result[] = $descendants === [] ? $finding : $finding->withSignals(array_merge($finding->signals(), [self::signal($descendants)]));
        }

        return $result;
    }

    /**
     * Parent => the flagged findings reachable from it, each paired with the shortest chain from the
     * parent, in report order. A flagged direct package is listed under every *other* root that
     * reaches it, never under itself.
     *
     * @param list<Finding> $findings
     *
     * @return array<string, list<array{Finding, list<string>}>>
     */
    private static function byParent(array $findings, DependencyGraph $graph): array
    {
        $flagged = array_filter($findings, static fn (Finding $f): bool => Verdict::flagged($f->verdict()));
        usort($flagged, [Report::class, 'compare']);
        $byParent = [];
        foreach ($flagged as $finding) {
            foreach ($graph->chainsTo($finding->package()) as $parent => $chain) {
                if ($parent !== $finding->package()) {
                    $byParent[$parent][] = [$finding, $chain];
                }
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
