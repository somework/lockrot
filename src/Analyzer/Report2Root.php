<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Verdict\CommandArgument;
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
            if ($standing->isVulnerable() && $standing->fixKind() === 'update' && CommandArgument::passes($finding->package())) {
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
