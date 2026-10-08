<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Security\Severity;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\ScoreModel;

/**
 * report-2's root blocks, each counted from the finding objects the report writes, so a block
 * cannot disagree with its findings.
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
     * @param list<array<string, mixed>> $findings
     *
     * @return array<string, array<string, mixed>>
     */
    public static function flags(array $findings): array
    {
        $carrying = array_fill_keys(ScoreModel::FLAG_ORDER, 0);
        $leading = $carrying;
        $acceptedAll = $carrying;
        $acceptedGraded = $carrying;
        $byVerdict = array_fill_keys(ScoreModel::FLAG_ORDER, array_fill_keys(ScoreModel::GRADES, 0));
        foreach ($findings as $finding) {
            $graded = isset(self::map($finding, 'score')['terms']);
            foreach (self::countedFlags($finding) as $flag) {
                ++$carrying[$flag];
                ++$byVerdict[$flag][self::str($finding, 'verdict')];
            }
            $lead = self::str($finding, 'lead');
            if ($lead !== '') {
                ++$leading[$lead];
            }
            foreach (self::rows($finding, 'flags') as $flag) {
                if (self::str($flag, 'role') === 'accepted') {
                    ++$acceptedAll[self::str($flag, 'id')];
                    $acceptedGraded[self::str($flag, 'id')] += (int) $graded;
                }
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
     * @param list<array<string, mixed>>           $findings
     * @param array<string, array<string, mixed>> $flags
     *
     * @return array{total: int, with_replacement: int, with_suggestion: int}
     */
    public static function abandoned(array $findings, array $flags): array
    {
        $withReplacement = 0;
        $withSuggestion = 0;
        foreach ($findings as $finding) {
            if (!\in_array(FlagSet::ABANDONED, self::countedFlags($finding), true)) {
                continue;
            }
            if (self::str($finding, 'replacement') !== '') {
                ++$withReplacement;
                continue;
            }
            foreach (self::rows($finding, 'signals') as $signal) {
                $withSuggestion += (int) (self::str($signal, 'id') === 'S1' && self::str(self::map($signal, 'data'), 'replacement') !== '');
            }
        }

        return ['total' => self::int($flags[FlagSet::ABANDONED], 'carrying'), 'with_replacement' => $withReplacement, 'with_suggestion' => $withSuggestion];
    }

    /**
     * @param list<array<string, mixed>> $findings
     *
     * @return array<string, int>
     */
    public static function priorities(array $findings): array
    {
        $counts = array_fill_keys(array_merge(ScoreModel::GRADES, ['none']), 0);
        foreach ($findings as $finding) {
            ++$counts[self::str($finding, 'priority')];
        }

        return $counts;
    }

    /**
     * report-1's block with `packages`, its sums over the published two-decimal values.
     *
     * @param array<string, mixed>       $libyears
     * @param list<array<string, mixed>> $findings
     *
     * @return array<string, mixed>
     */
    public static function libyears(array $libyears, array $findings): array
    {
        $total = null;
        $direct = null;
        foreach ($findings as $finding) {
            $years = $finding['libyears'] ?? null;
            if (!\is_int($years) && !\is_float($years)) {
                continue;
            }
            $total = ($total ?? 0) + $years;
            if (($finding['direct'] ?? false) === true) {
                $direct = ($direct ?? 0) + $years;
            }
        }
        if ($total !== null) {
            $direct ??= 0;
        }

        return array_merge($libyears, ['total' => $total === null ? null : round($total, 2), 'direct_requirements' => $direct === null ? null : round($direct, 2), 'packages' => \count($findings)]);
    }

    /**
     * The report-1 gate with the finding counts.
     *
     * @param array{fails: bool, tripped_by: list<string>, fail_on_applied: bool} $gate
     * @param list<array<string, mixed>>                                          $findings
     *
     * @return array<string, mixed>
     */
    public static function gate(array $gate, array $findings): array
    {
        $reaching = 0;
        $failing = 0;
        $exempt = ['baseline' => 0];
        foreach ($findings as $finding) {
            $standing = self::map($finding, 'gate');
            $reaching += (int) (($standing['reaches_fail_on'] ?? false) === true);
            $failing += (int) (($standing['fails'] ?? false) === true);
            $by = self::str($standing, 'exempt_by');
            if ($by !== '') {
                $exempt[$by] = ($exempt[$by] ?? 0) + 1;
            }
        }

        return $gate + ['reaching' => $reaching, 'failing' => $failing, 'exempt' => $exempt];
    }

    /**
     * @param list<array<string, mixed>> $findings
     *
     * @return array<string, mixed>
     */
    public static function security(array $findings): array
    {
        $checks = [];
        $packages = ['vulnerable' => 0, 'unchecked' => 0, 'ignored' => 0, 'clear' => 0];
        $advisories = ['counted' => 0, 'ignored' => 0];
        $severities = array_fill_keys(Severity::DISPLAY_ORDER, 0);
        $fixes = array_fill_keys(ScoreModel::FIX_KINDS, 0);
        $updateNow = [];
        foreach ($findings as $finding) {
            $security = self::map($finding, 'security');
            $status = self::str($security, 'status');
            $checks[self::str($security, 'check')] = true;
            $packages[$status] = ($packages[$status] ?? 0) + 1;
            $ignored = self::int($security, 'ignored_count');
            $packages['ignored'] += (int) ($ignored > 0);
            $advisories['ignored'] += $ignored;
            if ($status !== 'vulnerable') {
                continue;
            }
            foreach (self::map($security, 'counts') as $severity => $count) {
                $severities[$severity] = ($severities[$severity] ?? 0) + (\is_int($count) ? $count : 0);
                $advisories['counted'] += \is_int($count) ? $count : 0;
            }
            $kind = self::str($security, 'fix_kind');
            $fixes[$kind] = ($fixes[$kind] ?? 0) + 1;
            if ($kind === 'update') {
                $updateNow[] = self::str($finding, 'package');
            }
        }

        return [
            'check' => \count($checks) === 1 ? (string) array_key_first($checks) : ($checks === [] ? 'complete' : 'partial'),
            'packages' => $packages,
            'advisories' => $advisories,
            'severities' => $severities,
            'fixes' => $fixes,
            'update_now' => $updateNow,
            'update_now_command' => $updateNow === [] ? null : array_merge(['composer', 'update'], $updateNow),
            'fix_unknown' => $fixes['unknown'],
        ];
    }

    /** @param list<array<string, mixed>> $findings */
    public static function dataDate(array $findings): ?string
    {
        $oldest = null;
        foreach ($findings as $finding) {
            $date = self::str($finding, 'data_date');
            if ($date !== '' && ($oldest === null || new \DateTimeImmutable($date) < new \DateTimeImmutable($oldest))) {
                $oldest = $date;
            }
        }

        return $oldest;
    }

    /**
     * @param array<string, mixed> $finding
     *
     * @return list<string>
     */
    private static function countedFlags(array $finding): array
    {
        return array_values(array_filter(array_map(static fn (array $term): string => self::str($term, 'flag'), self::rows(self::map($finding, 'score'), 'terms'))));
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string
    {
        return \is_string($row[$key] ?? null) ? $row[$key] : '';
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int
    {
        return \is_int($row[$key] ?? null) ? $row[$key] : 0;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function map(array $row, string $key): array
    {
        return \is_array($row[$key] ?? null) ? array_filter($row[$key], 'is_string', \ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(array $row, string $key): array
    {
        $rows = [];
        foreach (\is_array($row[$key] ?? null) ? $row[$key] : [] as $item) {
            if (\is_array($item)) {
                $rows[] = array_filter($item, 'is_string', \ARRAY_FILTER_USE_KEY);
            }
        }

        return $rows;
    }
}
