<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Security\Severity;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\ScoreModel;

/**
 * report-2's root blocks, each counted from the findings that the report writes. RootRecountTest
 * counts each block again from the written `findings[]`. Pass the findings in the written order.
 * The libyears sums, `update_now` and the first of two equal data dates follow that order.
 *
 * @internal
 */
final class Report2Root
{
    /**
     * Per flag: the graded findings that count it, the findings it leads, the fired flags the
     * allowlist accepts and the counting findings by grade. `vulnerable` leads nothing and no entry
     * accepts it.
     *
     * @param list<Finding> $findings
     *
     * @return array<string, array{carrying: int, leading: ?int, accepted: ?array{all: int, in_graded: int}, by_verdict: array<string, int>}>
     */
    public static function toFlagsArray(array $findings): array
    {
        $carrying = array_fill_keys(ScoreModel::FLAG_ORDER, 0);
        $leading = $carrying;
        $acceptedAll = $carrying;
        $acceptedGraded = $carrying;
        $byVerdict = array_fill_keys(ScoreModel::FLAG_ORDER, array_fill_keys(ScoreModel::GRADES, 0));
        foreach ($findings as $finding) {
            foreach ($finding->flagIds() as $flag) {
                ++$carrying[$flag];
                ++$byVerdict[$flag][$finding->verdict()];
            }
            $lead = $finding->lead();
            if ($lead !== null) {
                ++$leading[$lead];
            }
            foreach ($finding->flags()->accepted() as $flag) {
                ++$acceptedAll[$flag];
                $acceptedGraded[$flag] += $finding->isGraded() ? 1 : 0;
            }
        }
        $out = [];
        foreach (ScoreModel::FLAG_ORDER as $flag) {
            $maintenance = $flag !== FlagSet::VULNERABLE;
            $out[$flag] = [
                'carrying' => $carrying[$flag],
                'leading' => $maintenance ? $leading[$flag] : null,
                'accepted' => $maintenance ? ['all' => $acceptedAll[$flag], 'in_graded' => $acceptedGraded[$flag]] : null,
                'by_verdict' => $byVerdict[$flag],
            ];
        }

        return $out;
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array{total: int, with_replacement: int, with_suggestion: int}
     */
    public static function toAbandonedArray(array $findings): array
    {
        $total = 0;
        $withReplacement = 0;
        $withSuggestion = 0;
        foreach ($findings as $finding) {
            if (!\in_array(FlagSet::ABANDONED, $finding->flagIds(), true)) {
                continue;
            }
            ++$total;
            if ($finding->countedSuccessor() !== null) {
                ++$withReplacement;
            } elseif ($finding->replacement() !== null) {
                ++$withSuggestion;
            }
        }

        return ['total' => $total, 'with_replacement' => $withReplacement, 'with_suggestion' => $withSuggestion];
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array<string, int>
     */
    public static function toPrioritiesArray(array $findings): array
    {
        $counts = array_fill_keys(array_merge(ScoreModel::GRADES, ['none']), 0);
        foreach ($findings as $finding) {
            ++$counts[$finding->gradeOrNone()];
        }

        return $counts;
    }

    /**
     * The sums add the written two-decimal values in whole hundredths, so the float error of a long
     * sum stays out of the written number.
     *
     * @param list<Finding> $findings
     *
     * @return array{total: ?float, direct_requirements: ?float, measured: int, at_least_count: int, unmeasured: array<string, int>, furthest_behind: ?array{package: string, version: string, libyears: ?float, at_least: bool}, packages: int}
     */
    public static function toLibyearsArray(Libyears $libyears, array $findings): array
    {
        $total = null;
        $direct = null;
        foreach ($findings as $finding) {
            $years = $finding->libyearsRounded();
            if ($years === null) {
                continue;
            }
            $hundredths = round($years * 100);
            $total = ($total ?? 0) + $hundredths;
            if ($finding->isDirect()) {
                $direct = ($direct ?? 0) + $hundredths;
            }
        }
        if ($total !== null) {
            $direct ??= 0;
        }

        $worst = $libyears->worst();

        return [
            'total' => $total === null ? null : $total / 100.0,
            'direct_requirements' => $direct === null ? null : $direct / 100.0,
            'measured' => $libyears->measured(),
            'at_least_count' => $libyears->atLeastCount(),
            'unmeasured' => $libyears->unmeasured(),
            'furthest_behind' => $worst === null ? null : ['package' => $worst->package(), 'version' => $worst->version(), 'libyears' => $worst->libyearsRounded(), 'at_least' => $worst->isLibyearsAtLeast()],
            'packages' => \count($findings),
        ];
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array{check: string, packages: array<string, int>, advisories: array{counted: int, ignored: int}, severities: array<string, int>, fixes: array<string, int>, update_now: list<string>, update_now_command: ?list<string>, fix_unknown: int}
     */
    public static function toSecurityArray(array $findings): array
    {
        $checks = [];
        $packages = ['vulnerable' => 0, 'unchecked' => 0, 'ignored' => 0, 'clear' => 0];
        $advisories = ['counted' => 0, 'ignored' => 0];
        $severities = array_fill_keys(Severity::DISPLAY_ORDER, 0);
        $fixes = array_fill_keys(ScoreModel::FIX_KINDS, 0);
        foreach ($findings as $finding) {
            $standing = $finding->securityStanding();
            $checks[$standing->check()] = $standing->check();
            ++$packages[$standing->status()];
            $packages['ignored'] += $standing->ignoredCount() > 0 ? 1 : 0;
            $advisories['ignored'] += $standing->ignoredCount();
            if (!$standing->isVulnerable()) {
                continue;
            }
            $kind = $standing->fixKind();
            foreach ($standing->counts() as $severity => $count) {
                $severities[$severity] = ($severities[$severity] ?? 0) + $count;
                $advisories['counted'] += $count;
            }
            $fixes[$kind] = ($fixes[$kind] ?? 0) + 1;
        }
        $updateNow = self::updateNow($findings);

        return [
            'check' => \count($checks) === 1 ? reset($checks) : ($checks === [] ? 'complete' : 'partial'),
            'packages' => $packages,
            'advisories' => $advisories,
            'severities' => $severities,
            'fixes' => $fixes,
            'update_now' => $updateNow,
            'update_now_command' => $updateNow === [] ? null : array_merge(['composer', 'update'], $updateNow),
            'fix_unknown' => $fixes['unknown'],
        ];
    }

    /**
     * `security.update_now`: the vulnerable packages whose fix is a `composer update`, in report order.
     *
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    public static function updateNow(array $findings): array
    {
        $packages = [];
        foreach ($findings as $finding) {
            $standing = $finding->securityStanding();
            if ($standing->isVulnerable() && $standing->fixKind() === 'update') {
                $packages[] = $finding->package();
            }
        }

        return $packages;
    }

    /**
     * The oldest data date, to the second as report-2 writes it. Of two in one second, the first.
     *
     * @param list<Finding> $findings
     */
    public static function dataDate(array $findings): ?string
    {
        $oldest = null;
        foreach ($findings as $finding) {
            $date = $finding->dataDate();
            if ($date !== null && ($oldest === null || $date->getTimestamp() < $oldest->getTimestamp())) {
                $oldest = $date;
            }
        }

        return $oldest === null ? null : $oldest->format(\DATE_ATOM);
    }
}
