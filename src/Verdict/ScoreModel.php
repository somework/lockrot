<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Security\Severity;

/**
 * Score model 1: the weights, the bands, the severities, the fix kinds and the sixteen rules that turn
 * a finding's flags into a grade, and the self-describing `run.score_model` that a report publishes.
 * Weights are fixed per model, never configurable. A change to a number, a band floor or a rule
 * bumps {@see self::ID}.
 *
 * @internal
 */
final class ScoreModel
{
    public const ID = 1;
    public const SCORE_TEXT_GRAMMAR = 1;

    /** Lists, sums, pills, gates and the baseline cover read this order. */
    public const FLAG_ORDER = [FlagSet::ABANDONED, FlagSet::SILENT, FlagSet::PINNED, FlagSet::LEFT_BEHIND, FlagSet::OLD_PROMISE, FlagSet::STALE, FlagSet::VULNERABLE];

    /** The maintenance flags in flag order. Their points never increase along it. */
    public const POINTS = [FlagSet::ABANDONED => 32, FlagSet::SILENT => 32, FlagSet::PINNED => 16, FlagSet::LEFT_BEHIND => 16, FlagSet::OLD_PROMISE => 16, FlagSet::STALE => 8];

    /** @var array<string, list<string>> the signals that raise each flag: a removed flag removes them */
    public const RAISED_BY = [
        FlagSet::ABANDONED => ['S1', 'S3'],
        FlagSet::SILENT => ['S2', 'S4'],
        FlagSet::PINNED => ['S6'],
        FlagSet::LEFT_BEHIND => ['S8'],
        FlagSet::OLD_PROMISE => ['S5'],
        FlagSet::STALE => ['S2', 'S4'],
        FlagSet::VULNERABLE => ['S9'],
    ];

    public const GRADES = ['critical', 'high', 'medium', 'low'];

    /** The floor of each grade's band, worst first. */
    public const BANDS = ['critical' => 32, 'high' => 16, 'medium' => 8, 'low' => 1];

    private const NEXT = ['critical' => null, 'high' => 'critical', 'medium' => 'high', 'low' => 'medium'];

    /** The display order, which also breaks a tie between advisories of equal points. */
    public const SEVERITIES = Severity::DISPLAY_ORDER;

    /** In ease order. */
    public const FIX_KINDS = ['update', 'upgrade', 'raise-php', 'unknown', 'blocked', 'none'];

    /** The fix kinds that leave no reachable fix: the advisory counts twice. */
    public const DOUBLING = ['none', 'blocked'];

    public const CORROBORATING_DIVISOR = 4;
    public const NO_REACHABLE_FIX_FACTOR = 2;
    public const REACH_DIVISOR = 2;
    public const DEV_DIVISOR = 2;

    public const RULES = [
        'counted', 'lead-first', 'corroborating-share', 'advisory-points', 'no-reachable-fix-multiplier', 'security-max', 'divide-reach',
        'security-exempt-from-reach', 'sum', 'divide-dev', 'floor-once', 'band-floors', 'zero-verdicts', 'sort', 'gate-bounded-new', 'baseline-cover',
    ];

    /** The flag sets that lockrot's signals never raise together. */
    private const EXCLUSIVE_GROUPS = [
        ['id' => 'liveness', 'flag_ids' => [FlagSet::ABANDONED, FlagSet::SILENT, FlagSet::STALE], 'holds_unless' => null],
        ['id' => 'branch', 'flag_ids' => [FlagSet::PINNED, FlagSet::LEFT_BEHIND], 'holds_unless' => null],
        ['id' => 'release_age', 'flag_ids' => [FlagSet::SILENT, FlagSet::LEFT_BEHIND], 'holds_unless' => null],
        ['id' => 'release_push', 'flag_ids' => [FlagSet::LEFT_BEHIND, FlagSet::STALE], 'holds_unless' => ['threshold' => 'push-warn-years', 'below' => 'release-warn-years']],
    ];

    private const THRESHOLDS = [
        FlagSet::SILENT => ['release-high-years', 'push-high-years'],
        FlagSet::LEFT_BEHIND => ['release-warn-years'],
        FlagSet::STALE => ['release-warn-years', 'push-warn-years'],
    ];

    private const V013_BASE = [FlagSet::ABANDONED => 'critical', FlagSet::SILENT => 'critical', FlagSet::PINNED => 'high', FlagSet::LEFT_BEHIND => 'high', FlagSet::OLD_PROMISE => 'high', FlagSet::STALE => 'medium'];

    /** The band of a total, null at 0. */
    public static function band(int $total): ?string
    {
        foreach (self::BANDS as $grade => $floor) {
            if ($total >= $floor) {
                return $grade;
            }
        }

        return null;
    }

    /** @throws \InvalidArgumentException for a word that is not a grade */
    public static function floorOf(string $grade): int
    {
        if (!isset(self::BANDS[$grade])) {
            throw new \InvalidArgumentException('not a grade: '.$grade);
        }

        return self::BANDS[$grade];
    }

    /**
     * The band above a grade, null above critical.
     *
     * @throws \InvalidArgumentException for a word that is not a grade
     */
    public static function nextBand(string $grade): ?string
    {
        if (!\array_key_exists($grade, self::NEXT)) {
            throw new \InvalidArgumentException('not a grade: '.$grade);
        }

        return self::NEXT[$grade];
    }

    public static function advisoryPoints(string $severity, string $fixKind): int
    {
        return Severity::fromComposer($severity)->points() * (\in_array($fixKind, self::DOUBLING, true) ? self::NO_REACHABLE_FIX_FACTOR : 1);
    }

    /**
     * `run.score_model`: every key that report-2 writes, in its order. The illustrations, the largest
     * total and the largest maintenance under each flag come from the engine.
     *
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        return [
            'id' => self::ID,
            'score_text_grammar' => self::SCORE_TEXT_GRAMMAR,
            'flag_order' => self::FLAG_ORDER,
            'parts' => [
                ['id' => 'maintenance', 'flag_ids' => array_keys(self::POINTS), 'combine' => 'lead_plus_share'],
                ['id' => 'security', 'flag_ids' => [FlagSet::VULNERABLE], 'combine' => 'max'],
            ],
            'flags' => self::flags(),
            'severities' => self::severities(),
            'fix_kinds' => array_map(
                static fn (string $fix, int $i): array => ['id' => $fix, 'ease' => $i + 1, 'doubles' => \in_array($fix, self::DOUBLING, true)],
                self::FIX_KINDS,
                array_keys(self::FIX_KINDS)
            ),
            'bands' => array_map(static fn (string $grade, int $floor): array => ['verdict' => $grade, 'floor' => $floor], array_keys(self::BANDS), self::BANDS),
            'zero_verdicts' => [['verdict' => 'finished', 'when' => 'allowlisted'], ['verdict' => 'unknown', 'when' => 'no_metadata'], ['verdict' => 'ok', 'when' => 'otherwise']],
            'exact_unit' => 0.5,
            'rounding' => 'floor_once',
            'max_total' => self::maxTotal(),
            'bar_max' => 64,
            'bar_overflow' => 'clip',
            'exclusive_groups' => self::EXCLUSIVE_GROUPS,
            'sort' => [
                ['key' => 'verdict', 'path' => 'verdict', 'dir' => 'order', 'order' => [['critical'], ['high'], ['medium'], ['low'], ['unknown'], ['finished', 'ok']], 'default' => null, 'collation' => null],
                ['key' => 'security', 'path' => 'score.parts.security.contribution', 'dir' => 'desc', 'default' => 0, 'collation' => null],
                ['key' => 'score', 'path' => 'score.exact', 'dir' => 'desc', 'default' => 0, 'collation' => null],
                ['key' => 'direct', 'path' => 'direct', 'dir' => 'true_first', 'default' => null, 'collation' => null],
                ['key' => 'package', 'path' => 'package', 'dir' => 'asc', 'collation' => 'bytes', 'default' => null],
            ],
            'rules' => self::rules(),
            'docs' => 'https://lockrot.dev/verdicts/model-1/',
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function flags(): array
    {
        $rows = [];
        foreach (self::POINTS as $flag => $points) {
            $canCorroborate = !\in_array($flag, [FlagSet::ABANDONED, FlagSet::SILENT], true);
            [$max, $maxFlags] = self::maxMaintenance($flag);
            $rows[] = [
                'id' => $flag, 'part' => 'maintenance', 'points' => $points, 'band' => self::band($points),
                'can_corroborate' => $canCorroborate, 'corroborating_points' => $canCorroborate ? intdiv($points, self::CORROBORATING_DIVISOR) : null,
                'max_maintenance' => $max, 'max_maintenance_flags' => $maxFlags,
                'raised_by' => self::RAISED_BY[$flag], 'thresholds' => self::THRESHOLDS[$flag] ?? [],
                'basis' => ['v013_base' => self::V013_BASE[$flag], 'points' => 'band_floor'], 'points_from' => null,
            ];
        }
        $rows[] = [
            'id' => FlagSet::VULNERABLE, 'part' => 'security', 'points' => null, 'band' => null,
            'can_corroborate' => false, 'corroborating_points' => null, 'max_maintenance' => null, 'max_maintenance_flags' => null,
            'raised_by' => self::RAISED_BY[FlagSet::VULNERABLE], 'thresholds' => [],
            'basis' => ['v013_base' => null, 'points' => 'severities'], 'points_from' => 'severities',
        ];

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private static function severities(): array
    {
        $rows = [];
        foreach (self::SEVERITIES as $severity) {
            $points = Severity::fromComposer($severity)->points();
            $doubled = $points * self::NO_REACHABLE_FIX_FACTOR;
            $basis = ['points' => 'band_floor'];
            if ($severity === Severity::UNRATED) {
                $basis = ['points' => 'counts_as'];
            } elseif ($severity === Severity::LOW) {
                $basis = ['points' => 'share', 'of' => Severity::MEDIUM, 'share' => ['num' => 1, 'den' => 4]];
            }
            $rows[] = [
                'id' => $severity, 'points' => $points, 'band' => self::band($points),
                'points_no_reachable_fix' => $doubled, 'band_no_reachable_fix' => self::band($doubled),
                'gate_rank' => Severity::fromComposer($severity)->gateRank(), 'counts_as' => $severity === Severity::UNRATED ? Severity::MEDIUM : null, 'basis' => $basis,
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private static function rules(): array
    {
        $rules = [
            ['id' => 'counted', 'kind' => 'select', 'applies_to' => 'flags', 'accept_group' => 'liveness', 'accept_covers' => 'listed_and_below', 'why' => 'accepted-facts-shown-not-counted'],
            ['id' => 'lead-first', 'kind' => 'select', 'applies_to' => 'maintenance', 'order' => 'flag_order', 'share' => ['num' => 1, 'den' => 1], 'why' => 'strongest-fact-in-full'],
            ['id' => 'corroborating-share', 'kind' => 'share', 'applies_to' => 'maintenance', 'share' => ['num' => 1, 'den' => self::CORROBORATING_DIVISOR], 'why' => 'correlated-evidence'],
            ['id' => 'advisory-points', 'kind' => 'lookup', 'applies_to' => 'security', 'table' => 'severities', 'why' => 'severity-points'],
            ['id' => 'no-reachable-fix-multiplier', 'kind' => 'multiply', 'applies_to' => 'advisory', 'factor' => self::NO_REACHABLE_FIX_FACTOR, 'when_fix' => self::DOUBLING, 'why' => 'one-band-up'],
            ['id' => 'security-max', 'kind' => 'max', 'applies_to' => 'security', 'tie_break' => [
                ['key' => 'points', 'dir' => 'desc', 'collation' => null], ['key' => 'severity', 'dir' => 'order', 'collation' => null], ['key' => 'id', 'dir' => 'asc', 'collation' => 'bytes'],
            ], 'why' => 'one-number-per-part'],
            ['id' => 'divide-reach', 'kind' => 'divide', 'applies_to' => 'maintenance', 'by' => self::REACH_DIVISOR, 'when' => [Score::TRANSITIVE, Score::UNREACHED], 'why' => 'reach-halves-maintenance'],
            ['id' => 'security-exempt-from-reach', 'kind' => 'exempt', 'applies_to' => 'security', 'of' => 'divide-reach', 'why' => 'exploitability-independent-of-reach'],
            ['id' => 'sum', 'kind' => 'combine', 'applies_to' => 'total', 'op' => 'sum', 'ratio_from' => 'medium', 'why' => 'parts-add'],
            ['id' => 'divide-dev', 'kind' => 'divide', 'applies_to' => 'total', 'by' => self::DEV_DIVISOR, 'when' => ['dev'], 'why' => 'dev-halves-total'],
            ['id' => 'floor-once', 'kind' => 'floor', 'applies_to' => 'total', 'why' => 'integer-band-edges'],
            ['id' => 'band-floors', 'kind' => 'band', 'applies_to' => 'total', 'ratio' => 2, 'ratio_from' => 'medium', 'why' => 'two-floor-parts-make-next'],
            ['id' => 'zero-verdicts', 'kind' => 'zero', 'applies_to' => 'total', 'table' => 'zero_verdicts', 'why' => 'score-zero-says-why'],
            ['id' => 'sort', 'kind' => 'order', 'applies_to' => 'findings', 'why' => 'advisory-findings-first'],
            ['id' => 'gate-bounded-new', 'kind' => 'gate', 'applies_to' => 'gate', 'cap_multiplier' => 2, 'why' => 'new-fact-sets-level'],
            ['id' => 'baseline-cover', 'kind' => 'cover', 'applies_to' => 'gate', 'order' => 'flag_order', 'covers' => 'listed_and_below', 'why' => 'recorded-lead-covers-below'],
        ];
        $out = [];
        foreach ($rules as $i => $rule) {
            $out[] = \array_slice($rule, 0, 1) + ['stage' => $i + 1] + \array_slice($rule, 1) + ['doc' => 'score-'.$rule['id'], 'illustration' => self::illustration($rule['id'])];
        }

        return $out;
    }

    /**
     * A rule's numbers on a fixed example, so a page can show a rule that no finding of the report uses.
     *
     * @return ?array<string, mixed>
     */
    private static function illustration(string $rule): ?array
    {
        switch ($rule) {
            case 'corroborating-share':
                return ['lead' => FlagSet::LEFT_BEHIND, 'flag' => FlagSet::OLD_PROMISE, 'weight' => self::POINTS[FlagSet::OLD_PROMISE], 'points' => self::total([FlagSet::LEFT_BEHIND, FlagSet::OLD_PROMISE]) - self::total([FlagSet::LEFT_BEHIND])];
            case 'no-reachable-fix-multiplier':
                return self::step(['severity' => 'high'], self::total([], [['high', 'update']]), self::total([], [['high', 'none']]));
            case 'divide-reach':
                return self::step(['flag' => FlagSet::ABANDONED], self::total([FlagSet::ABANDONED]), self::total([FlagSet::ABANDONED], [], Score::TRANSITIVE));
            case 'security-exempt-from-reach':
                return ['severity' => 'critical', 'reach' => Score::TRANSITIVE, 'total' => self::total([], [['critical', 'update']], Score::TRANSITIVE)];
            case 'divide-dev':
                return self::step([], self::total([], [['critical', 'update']]), self::total([], [['critical', 'update']], Score::DIRECT, true));
            case 'floor-once':
                $score = Score::compute([FlagSet::LEFT_BEHIND, FlagSet::STALE], [], Score::TRANSITIVE, true);

                return ['exact' => $score->exactHalves() / 2, 'total' => $score->total()];
            case 'sum':
                $low = Score::compute([FlagSet::STALE], [['id' => 'A', 'severity' => 'low', 'fix_kind' => 'none']], Score::TRANSITIVE, false);
                $total = self::total([FlagSet::LEFT_BEHIND], [['high', 'update']]);

                return [
                    'maintenance' => self::total([FlagSet::LEFT_BEHIND]), 'security' => self::total([], [['high', 'update']]), 'total' => $total, 'band' => self::band($total),
                    'low_maintenance' => intdiv($low->maintenanceHalves(), 2), 'low_security' => intdiv($low->securityHalves(), 2), 'low_total' => $low->total(), 'low_band' => self::band($low->total()),
                ];
            case 'gate-bounded-new':
                $new = self::total([], [['high', 'upgrade']]);

                return ['score' => self::total([FlagSet::LEFT_BEHIND, FlagSet::OLD_PROMISE], [['high', 'upgrade']]), 'new' => $new, 'gate' => min(self::total([FlagSet::LEFT_BEHIND, FlagSet::OLD_PROMISE], [['high', 'upgrade']]), 2 * $new)];
        }

        return null;
    }

    /**
     * @param array<string, string> $head
     *
     * @return array<string, mixed>
     */
    private static function step(array $head, int $before, int $after): array
    {
        return $head + ['before' => $before, 'after' => $after, 'band_before' => self::band($before), 'band_after' => self::band($after)];
    }

    /**
     * @param list<string>                $maintenance
     * @param list<array{string, string}> $advisories  severity and fix kind
     */
    private static function total(array $maintenance, array $advisories = [], string $reach = Score::DIRECT, bool $dev = false): int
    {
        $counted = [];
        foreach ($advisories as $i => [$severity, $fix]) {
            $counted[] = ['id' => 'A'.$i, 'severity' => $severity, 'fix_kind' => $fix];
        }

        return Score::compute($maintenance, $counted, $reach, $dev)->total();
    }

    /**
     * The most a maintenance part with this lead can score, and the flag set that reaches it: every
     * set of other flags that holds no exclusive group, the first set found winning a tie.
     *
     * @return array{int, list<string>}
     */
    private static function maxMaintenance(string $lead): array
    {
        $others = array_values(array_diff(array_keys(self::POINTS), [$lead]));
        $best = [0, [$lead]];
        for ($mask = 0; $mask < 1 << \count($others); ++$mask) {
            $flags = [$lead];
            foreach ($others as $i => $flag) {
                if (($mask >> (\count($others) - 1 - $i)) & 1) {
                    $flags[] = $flag;
                }
            }
            if (self::holdsAGroup($flags)) {
                continue;
            }
            $score = Score::compute($flags, [], Score::DIRECT, false);
            if ($score->lead() === $lead && $score->total() > $best[0]) {
                $best = [$score->total(), array_values(array_intersect(self::FLAG_ORDER, $flags))];
            }
        }

        return $best;
    }

    /** With a critical advisory that nothing fixes, beside every flag set the signals can raise. */
    private static function maxTotal(): int
    {
        $largest = 0;
        foreach ([[], [FlagSet::ABANDONED], [FlagSet::SILENT], [FlagSet::STALE]] as $liveness) {
            foreach ([[], [FlagSet::PINNED], [FlagSet::LEFT_BEHIND]] as $branch) {
                foreach ([[], [FlagSet::OLD_PROMISE]] as $promise) {
                    $flags = array_merge($liveness, $branch, $promise);
                    if (!self::holdsAGroup($flags)) {
                        $largest = max($largest, self::total($flags, [['critical', 'none']]));
                    }
                }
            }
        }

        return $largest;
    }

    /** @param list<string> $flags */
    private static function holdsAGroup(array $flags): bool
    {
        foreach (self::EXCLUSIVE_GROUPS as $group) {
            if (\count(array_intersect($group['flag_ids'], $flags)) >= ($group['id'] === 'liveness' ? 2 : \count($group['flag_ids']))) {
                return true;
            }
        }

        return false;
    }
}
