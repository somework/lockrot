<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Score\ScoreBasis;
use Lockrot\Score\ScoreText;
use Lockrot\Verdict\ScoreModel;

/**
 * The invariants I0 to I17b of the structured basis, checked on one sweep input and its score object,
 * except I14 to I15b. The accepted set comes from AllowlistEntry::accepts(). ScoreInterpreter keeps its
 * own copy of the cover, because it reads only the decoded model.
 *
 * @phpstan-import-type Inputs from ScoreSweep
 * @phpstan-import-type Graded from ScoreBasis
 * @phpstan-import-type Zero from ScoreBasis
 * @phpstan-import-type SecurityTerm from ScoreBasis
 * @phpstan-import-type Accepted from ScoreBasis
 */
final class ScoreInvariants
{
    private const DOUBLING = ['none', 'blocked'];

    /**
     * @param Inputs       $inputs
     * @param Graded|Zero  $s      the score object
     *
     * @return list<string> the invariants that do not hold
     */
    public static function violations(array $inputs, array $s): array
    {
        $bad = [];
        $total = $s['total'];
        $exact = ScoreSweep::halves($s['exact']);
        if ($total !== self::reference($inputs)) {
            $bad[] = 'I0 total differs from the reference formula';
        }
        if ($total !== intdiv($exact, 2)) {
            $bad[] = 'I2 total is not the floor of exact';
        }
        if (!isset($s['terms'])) {
            if ($total !== 0 || array_keys($s) !== ['model', 'total', 'exact', 'accepted', 'text']) {
                $bad[] = 'I4 the score-0 shape appears exactly when there is no term';
            }

            return array_merge($bad, self::accepted($s, 0), self::text($s));
        }
        $terms = $s['terms'];
        if ($terms === [] || ($s['rounded_down'] !== ($exact % 2 !== 0))) {
            $bad[] = 'I2 rounded_down';
        }
        if (array_sum(array_map(static fn (array $t): int => ScoreSweep::halves($t['contribution']), $terms)) !== $exact) {
            $bad[] = 'I1 the contributions do not add up to exact';
        }
        $grade = ScoreModel::band($total);
        $next = ['low' => 'medium', 'medium' => 'high', 'high' => 'critical', 'critical' => null][$grade ?? 'low'];
        if ($grade === null || $s['band'] !== ['floor' => ScoreModel::floorOf($grade), 'next' => $next, 'to_next' => $next === null ? null : ScoreModel::floorOf($next) - $total]) {
            $bad[] = 'I3 band';
        }

        $order = array_flip(ScoreModel::FLAG_ORDER);
        $positions = array_map(static fn (array $t): int => $order[$t['flag']], $terms);
        $sorted = $positions;
        sort($sorted);
        if ($positions !== $sorted || \count(array_unique($positions)) !== \count($positions)) {
            $bad[] = 'I6 terms in flag order, each flag once';
        }
        $parts = ['maintenance' => 0, 'security' => 0];
        foreach ($terms as $i => $t) {
            $parts[$t['part']] += ScoreSweep::halves($t['contribution']);
            if ($t['part'] === 'maintenance') {
                if ($t['weight'] !== ScoreModel::POINTS[$t['flag']] || $t['points'] * $t['divisor'] !== $t['weight'] || ($t['divisor'] === 4) !== ($t['role'] === 'corroborating') || ($t['role'] === 'lead') !== ($i === 0)) {
                    $bad[] = 'I5 or I6 maintenance term '.$t['flag'];
                }
                continue;
            }
            if ($i !== \count($terms) - 1 || $t['points'] !== $t['weight'] * $t['multiplier'] || ($t['multiplier'] === 2) !== \in_array($t['fix_kind'], self::DOUBLING, true)) {
                $bad[] = 'I5, I6 or I8 security term';
            }
            $bad = array_merge($bad, self::deciding($inputs, $t, $s['parts']['security']['tied']));
        }
        foreach ($parts as $part => $halves) {
            $p = $s['parts'][$part];
            $hasTerm = \in_array($part, array_column($terms, 'part'), true);
            if (ScoreSweep::halves($p['contribution']) !== $halves || ($p['status'] === 'counted') !== $hasTerm || $p['alone'] !== ['total' => intdiv($halves, 2), 'verdict' => ScoreModel::band(intdiv($halves, 2))]) {
                $bad[] = 'I4 or I10 part '.$part;
            }
        }
        $bad = array_merge($bad, self::modifiers($inputs, $s, $parts), self::decidedBy($s), self::without($inputs, $s), self::accepted($s, $total), self::text($s));
        $fired = array_merge($inputs['flags'], $inputs['advisories'] === [] ? [] : ['vulnerable']);
        $shown = array_merge(array_column($terms, 'flag'), array_column($s['accepted'], 'flag'));
        sort($fired);
        sort($shown);
        if ($fired !== $shown) {
            $bad[] = 'I16 every fired flag is a term or accepted';
        }

        return $bad;
    }

    /**
     * ⌊((¾ · lead + ¼ · Σ counted maintenance) ÷ reach + security) ÷ dev⌋, in quarter points.
     *
     * @param Inputs $inputs
     */
    private static function reference(array $inputs): int
    {
        $counted = self::counted($inputs, $inputs['flags']);
        $quarters = $counted === [] ? 0 : 3 * ScoreModel::POINTS[$counted[0]] + array_sum(array_map(static fn (string $f): int => ScoreModel::POINTS[$f], $counted));
        $security = 0;
        foreach ($inputs['advisories'] as [$severity, $fix]) {
            $security = max($security, ['critical' => 32, 'high' => 16, 'medium' => 8, 'unrated' => 8, 'low' => 2][$severity] * (\in_array($fix, self::DOUBLING, true) ? 2 : 1));
        }
        $reach = $inputs['reach'] === 'direct' ? 1 : 2;
        $dev = $inputs['dev'] ? 2 : 1;

        return intdiv($quarters + 4 * $reach * $security, 4 * $reach * $dev);
    }

    /**
     * @param Inputs       $inputs
     * @param SecurityTerm $term
     * @param list<string> $tied
     *
     * @return list<string>
     */
    private static function deciding(array $inputs, array $term, array $tied): array
    {
        $points = [];
        foreach (ScoreSweep::advisories($inputs['advisories']) as $a) {
            $points[$a['id']] = [ScoreModel::advisoryPoints($a['severity'], $a['fix_kind']), array_search($a['severity'], ScoreModel::SEVERITIES, true), $a['id']];
        }
        $best = max(array_merge([0], array_column($points, 0)));
        $others = array_keys(array_filter($points, static fn (array $p, string $id): bool => $p[0] === $best && $id !== $term['advisory'], \ARRAY_FILTER_USE_BOTH));
        usort($others, static fn (string $a, string $b): int => [$points[$a][1], $a] <=> [$points[$b][1], $b]);
        if ($points[$term['advisory']][0] !== $best || $others !== $tied || $term['points'] !== $best) {
            return ['I7 the deciding advisory and tied'];
        }
        foreach ($others as $id) {
            if ([$points[$id][1], $id] < [$points[$term['advisory']][1], $term['advisory']]) {
                return ['I7 a tied advisory precedes the deciding one'];
            }
        }

        return [];
    }

    /**
     * @param Inputs             $inputs
     * @param Graded             $s
     * @param array<string, int> $parts  the contributions in half points
     *
     * @return list<string>
     */
    private static function modifiers(array $inputs, array $s, array $parts): array
    {
        $expected = [];
        $maintenance = 0;
        foreach ($s['terms'] as $t) {
            if ($t['part'] === 'maintenance') {
                $maintenance += 2 * $t['points'];
            }
        }
        $security = $s['terms'][\count($s['terms']) - 1]['part'] === 'security' ? 2 * $s['terms'][\count($s['terms']) - 1]['points'] : 0;
        $after = $maintenance;
        if ($inputs['reach'] !== 'direct') {
            $after = intdiv($maintenance, 2);
            $expected[] = ['reason' => $inputs['reach'], 'applies_to' => 'maintenance', 'divide_by' => 2, 'before' => $maintenance, 'after' => $after];
        }
        $sum = $after + $security;
        if ($inputs['dev']) {
            $expected[] = ['reason' => 'dev', 'applies_to' => 'total', 'divide_by' => 2, 'before' => $sum, 'after' => intdiv($sum, 2)];
            $sum = intdiv($sum, 2);
        }
        $actual = array_map(static fn (array $m): array => array_merge($m, ['before' => ScoreSweep::halves($m['before']), 'after' => ScoreSweep::halves($m['after'])]), $s['modifiers']);
        if ($actual !== $expected || $sum !== ScoreSweep::halves($s['exact']) || $parts['maintenance'] + $parts['security'] !== $sum) {
            return ['I9 modifiers'];
        }

        return [];
    }

    /**
     * @param Graded $s
     *
     * @return list<string>
     */
    private static function decidedBy(array $s): array
    {
        $grade = ScoreModel::band($s['total']);
        $maintenance = $s['parts']['maintenance']['alone']['verdict'];
        $security = $s['parts']['security']['alone']['verdict'];
        $expected = $grade === $maintenance ? ($grade === $security ? 'either' : 'maintenance') : ($grade === $security ? 'security' : 'combination');

        return $s['decided_by'] === $expected ? [] : ['I11 decided_by'];
    }

    /**
     * @param Inputs $inputs
     * @param Graded $s
     *
     * @return list<string>
     */
    private static function without(array $inputs, array $s): array
    {
        $bad = [];
        $flagRows = array_values(array_filter($s['without'], static fn (array $w): bool => $w['remove']['kind'] === 'flag'));
        $advisoryRows = array_values(array_filter($s['without'], static fn (array $w): bool => $w['remove']['kind'] === 'advisory'));
        if (($flagRows !== []) !== (\count($s['terms']) >= 2) || ($advisoryRows !== []) !== (\count($inputs['advisories']) >= 2)) {
            $bad[] = 'I12 which rows exist';
        }
        if ($flagRows !== [] && array_column(array_column($flagRows, 'remove'), 'id') !== array_column($s['terms'], 'flag')) {
            $bad[] = 'I12 one flag row per term, in terms order';
        }
        foreach ($s['without'] as $w) {
            if ($w['revealed'] !== self::revealed($inputs, $w['remove']['id'])) {
                $bad[] = 'I12b revealed of row '.$w['remove']['id'];
            }
            if ($w['total'] > $s['total'] || $w['at_least'] !== false || $w['verdict'] !== ScoreModel::band($w['total'])) {
                $bad[] = 'I12 row '.$w['remove']['id'];
            }
            if ($w['remove']['id'] === 'vulnerable' && $w['total'] !== $s['parts']['maintenance']['alone']['total']) {
                $bad[] = 'I10 without[vulnerable] is maintenance alone';
            }
        }

        return $bad;
    }

    /**
     * The hidden liveness word that a without[abandoned] row restores: `accepted` when the entry
     * accepts it, else its role among the maintenance flags that count once `abandoned` is gone.
     *
     * @param Inputs $inputs
     *
     * @return list<array{flag: string, role: string}>
     */
    private static function revealed(array $inputs, string $removed): array
    {
        $word = $inputs['under'];
        if ($removed !== 'abandoned' || $word === null) {
            return [];
        }
        $counted = self::counted($inputs, array_merge(array_diff($inputs['flags'], ['abandoned']), [$word]));
        $at = array_search($word, $counted, true);

        return [['flag' => $word, 'role' => $at === false ? 'accepted' : ($at === 0 ? 'lead' : 'corroborating')]];
    }

    /**
     * @param Inputs       $inputs
     * @param list<string> $flags
     *
     * @return list<string> the maintenance flags of $flags that the row's entry does not accept, in flag order
     */
    private static function counted(array $inputs, array $flags): array
    {
        $entry = ScoreSweep::entry($inputs);

        return array_values(array_filter(array_keys(ScoreModel::POINTS), static fn (string $f): bool => \in_array($f, $flags, true) && ($entry === null || !$entry->accepts($f))));
    }

    /**
     * @param Graded|Zero $s
     *
     * @return list<string>
     */
    private static function accepted(array $s, int $total): array
    {
        foreach ($s['accepted'] as $a) {
            $counted = $a['if_counted'];
            if ($counted['total'] < $total || $counted['at_least'] !== false || $a['weight'] !== ScoreModel::POINTS[$a['flag']]) {
                return ['I13 accepted '.$a['flag']];
            }
        }

        return [];
    }

    /**
     * @param Graded|Zero $s
     *
     * @return list<string>
     */
    private static function text(array $s): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) json_encode($s, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        $bad = [];
        if (ScoreText::render($decoded) !== $s['text']) {
            $bad[] = 'I17 the text from the decoded object';
        }
        [$head, $body] = ScoreLineEvaluator::evaluate($s['text']);
        if ($head !== $s['total'] || $body !== ScoreSweep::halves($s['exact'])) {
            $bad[] = 'I17b the line does not evaluate to exact';
        }

        return $bad;
    }
}
