<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

/**
 * A second engine that reads only a decoded `run.score_model` and a finding's facts, as a consumer
 * would. It shares no code with src/: it computes in exact fractions, not in half points, and takes
 * every number, order and divisor from the model. ScoreInterpreterTest asserts that it gives the
 * sweep row of ScoreSweep on every input.
 *
 * @phpstan-import-type Inputs from ScoreSweep
 *
 * @phpstan-type Fraction array{int, int}
 * @phpstan-type Run array{exact: Fraction, total: int, terms: list<array{part: string, flag: string, role: string, contribution: Fraction, advisory: ?string}>, maintenance: Fraction, security: Fraction, lead: ?string, deciding: ?string, advisories: int}
 */
final class ScoreInterpreter
{
    /** @var list<string> */
    private array $flagOrder = [];
    /** @var array<string, int> the points of each maintenance flag */
    private array $points = [];
    /** @var array<string, list<string>> */
    private array $raisedBy = [];
    /** @var array<string, int> the points of each severity, in the model's order */
    private array $severities = [];
    /** @var array<string, Fraction> */
    private array $shares = [];
    /** @var array<string, array{by: int, when: list<string>}> the divisors of divide-reach and divide-dev */
    private array $divisions = [];
    private int $factor;
    /** @var list<string> */
    private array $doubling;
    /** @var array<string, int> the floor of each band, worst first */
    private array $bands = [];
    private string $judgedZero = '';
    /** @var array<string, list<string>> */
    private array $groups = [];
    /** @var list<string> */
    private array $coverGroup;

    /** @param array<mixed, mixed> $model the decoded `run.score_model` */
    public function __construct(array $model)
    {
        $this->flagOrder = self::strings($model, ['flag_order']);
        foreach (self::rows($model, 'flags') as $flag) {
            $id = JsonPath::stringAt($flag, ['id']);
            $this->raisedBy[$id] = self::strings($flag, ['raised_by']);
            if (\is_int($flag['points'])) {
                $this->points[$id] = $flag['points'];
            }
        }
        foreach (self::rows($model, 'severities') as $severity) {
            $this->severities[JsonPath::stringAt($severity, ['id'])] = JsonPath::intAt($severity, ['points']);
        }
        foreach (self::rows($model, 'bands') as $band) {
            $this->bands[JsonPath::stringAt($band, ['verdict'])] = JsonPath::intAt($band, ['floor']);
        }
        foreach (self::rows($model, 'zero_verdicts') as $zero) {
            if (JsonPath::stringAt($zero, ['when']) === 'otherwise') {
                $this->judgedZero = JsonPath::stringAt($zero, ['verdict']);
            }
        }
        foreach (self::rows($model, 'exclusive_groups') as $group) {
            $this->groups[JsonPath::stringAt($group, ['id'])] = self::strings($group, ['flag_ids']);
        }
        $rules = [];
        foreach (self::rows($model, 'rules') as $rule) {
            $rules[JsonPath::stringAt($rule, ['id'])] = $rule;
        }
        foreach (['lead-first', 'corroborating-share'] as $id) {
            $this->shares[$id] = [JsonPath::intAt($rules, [$id, 'share', 'num']), JsonPath::intAt($rules, [$id, 'share', 'den'])];
        }
        foreach (['divide-reach', 'divide-dev'] as $id) {
            $this->divisions[$id] = ['by' => JsonPath::intAt($rules, [$id, 'by']), 'when' => self::strings($rules, [$id, 'when'])];
        }
        $this->factor = JsonPath::intAt($rules, ['no-reachable-fix-multiplier', 'factor']);
        $this->doubling = self::strings($rules, ['no-reachable-fix-multiplier', 'when_fix']);
        $covers = JsonPath::stringAt($rules, ['counted', 'accept_covers']) === 'listed_and_below';
        $this->coverGroup = $covers ? $this->groups[JsonPath::stringAt($rules, ['counted', 'accept_group'])] : [];
    }

    /** @param Inputs $facts */
    public function row(array $facts): string
    {
        $accepted = $this->cover($facts['accepted']);
        $advisories = ScoreSweep::advisories($facts['advisories']);
        $run = $this->run($facts['flags'], $advisories, $accepted, $facts['reach'], $facts['dev']);

        $without = [];
        if (\count($run['terms']) >= 2) {
            foreach ($run['terms'] as $term) {
                if ($term['part'] !== 'maintenance') {
                    continue;
                }
                $rerun = $this->run($this->withoutFlag($facts['flags'], $facts['under'], $term['flag']), $advisories, $accepted, $facts['reach'], $facts['dev']);
                $without[] = $term['flag'].':'.$rerun['total'].':'.$this->verdict($rerun['total']);
            }
            if ($run['deciding'] !== null) {
                $rerun = $this->run($facts['flags'], [], $accepted, $facts['reach'], $facts['dev']);
                $without[] = 'vulnerable:'.$rerun['total'].':'.$this->verdict($rerun['total']);
            }
        }
        if ($run['advisories'] >= 2) {
            $left = array_values(array_filter($advisories, static fn (array $a): bool => $a['id'] !== $run['deciding']));
            $rerun = $this->run($facts['flags'], $left, $accepted, $facts['reach'], $facts['dev']);
            $without[] = $run['deciding'].':'.$rerun['total'].':'.$this->verdict($rerun['total']);
        }

        $ifCounted = [];
        foreach ($this->flagOrder as $flag) {
            if (\in_array($flag, $facts['flags'], true) && \in_array($flag, $accepted, true)) {
                $rerun = $this->run($facts['flags'], $advisories, array_values(array_diff($accepted, [$flag])), $facts['reach'], $facts['dev']);
                $ifCounted[] = $flag.':'.$rerun['total'].':'.$this->verdict($rerun['total']);
            }
        }

        $gate = '';
        foreach ($this->bands as $floor) {
            $gate .= $run['total'] >= $floor && $this->marked($run, $floor) ? '1' : '0';
        }
        $band = $this->band($run['total']);

        return implode('|', [
            $facts['axis'],
            $facts['flags'] === [] ? '-' : implode(',', $facts['flags']),
            $facts['advisories'] === [] ? '-' : implode(',', array_map(static fn (array $a): string => $a[0].':'.$a[1], $facts['advisories'])),
            $facts['reach'],
            $facts['dev'] ? '1' : '0',
            $facts['under'] ?? '-',
            $facts['accepted'] ?? '-',
            (string) intdiv(2 * $run['exact'][0], $run['exact'][1]),
            (string) $run['total'],
            $band ?? '-',
            $run['terms'] === [] ? '-' : $this->decidedBy($run, $band),
            $without === [] ? '-' : implode(',', $without),
            $ifCounted === [] ? '-' : implode(',', $ifCounted),
            $gate,
        ]);
    }

    /**
     * @param list<string>                                                 $flags      the fired maintenance flags
     * @param list<array{id: string, severity: string, fix_kind: string}> $advisories
     * @param list<string>                                                 $accepted
     *
     * @return Run
     */
    private function run(array $flags, array $advisories, array $accepted, string $reach, bool $dev): array
    {
        $terms = [];
        $maintenance = [0, 1];
        $lead = null;
        foreach ($this->flagOrder as $flag) {
            if (!\in_array($flag, $flags, true) || \in_array($flag, $accepted, true) || !isset($this->points[$flag])) {
                continue;
            }
            $term = $this->times([$this->points[$flag], 1], $this->shares[$lead === null ? 'lead-first' : 'corroborating-share']);
            $terms[] = ['part' => 'maintenance', 'flag' => $flag, 'role' => $lead === null ? 'lead' : 'corroborating', 'contribution' => $term, 'advisory' => null];
            $maintenance = $this->plus($maintenance, $term);
            $lead ??= $flag;
        }
        $reachRule = $this->divisions['divide-reach'];
        if (\in_array($reach, $reachRule['when'], true)) {
            $maintenance = $this->times($maintenance, [1, $reachRule['by']]);
            $terms = array_map(fn (array $t): array => ['contribution' => $this->times($t['contribution'], [1, $reachRule['by']])] + $t, $terms);
        }

        $deciding = $this->deciding($advisories);
        $security = [0, 1];
        if ($deciding !== null) {
            $security = [$this->advisoryPoints($deciding), 1];
            $terms[] = ['part' => 'security', 'flag' => 'vulnerable', 'role' => 'security', 'contribution' => $security, 'advisory' => $deciding['id']];
        }
        $exact = $this->plus($maintenance, $security);
        $devRule = $this->divisions['divide-dev'];
        if ($dev && \in_array('dev', $devRule['when'], true)) {
            $half = [1, $devRule['by']];
            $exact = $this->times($exact, $half);
            $maintenance = $this->times($maintenance, $half);
            $security = $this->times($security, $half);
            $terms = array_map(fn (array $t): array => ['contribution' => $this->times($t['contribution'], $half)] + $t, $terms);
        }

        return ['exact' => $exact, 'total' => intdiv($exact[0], $exact[1]), 'terms' => $terms, 'maintenance' => $maintenance, 'security' => $security, 'lead' => $lead, 'deciding' => $deciding['id'] ?? null, 'advisories' => \count($advisories)];
    }

    /**
     * The advisory that `security-max`'s tie-break puts first.
     *
     * @param list<array{id: string, severity: string, fix_kind: string}> $advisories
     *
     * @return ?array{id: string, severity: string, fix_kind: string}
     */
    private function deciding(array $advisories): ?array
    {
        $order = array_keys($this->severities);
        usort($advisories, function (array $a, array $b) use ($order): int {
            return $this->advisoryPoints($b) <=> $this->advisoryPoints($a)
                ?: array_search($a['severity'], $order, true) <=> array_search($b['severity'], $order, true)
                ?: strcmp($a['id'], $b['id']);
        });

        return $advisories[0] ?? null;
    }

    /** @param array{id: string, severity: string, fix_kind: string} $advisory */
    private function advisoryPoints(array $advisory): int
    {
        return $this->severities[$advisory['severity']] * (\in_array($advisory['fix_kind'], $this->doubling, true) ? $this->factor : 1);
    }

    /**
     * The fired flags once the signals that raise $flag are gone. The liveness word that S2 and S4
     * give comes back when the removed flag hid it: a liveness word whose own signals stay.
     *
     * @param list<string> $flags
     *
     * @return list<string>
     */
    private function withoutFlag(array $flags, ?string $under, string $flag): array
    {
        $left = array_values(array_diff($flags, [$flag]));
        if ($under === null || !\in_array($flag, $this->groups['liveness'], true)) {
            return $left;
        }
        if (array_intersect($this->raisedBy[$flag], $this->raisedBy[$under]) === []) {
            $left[] = $under;
        }

        return $left;
    }

    /**
     * The flags an ignore[] entry that lists $flag accepts: rule `counted` covers the liveness words
     * after a listed one.
     *
     * @return list<string>
     */
    private function cover(?string $flag): array
    {
        if ($flag === null) {
            return [];
        }
        $at = array_search($flag, $this->coverGroup, true);

        return $at === false ? [$flag] : \array_slice($this->coverGroup, $at);
    }

    /** @param Run $run */
    private function decidedBy(array $run, ?string $band): string
    {
        $maintenance = $this->band(intdiv($run['maintenance'][0], $run['maintenance'][1]));
        $security = $this->band(intdiv($run['security'][0], $run['security'][1]));
        if ($band === $maintenance && $band === $security) {
            return 'either';
        }
        if ($band === $maintenance) {
            return 'maintenance';
        }

        return $band === $security ? 'security' : 'combination';
    }

    /**
     * A failing grade value names a term: the lead or the security term at half its floor or more.
     *
     * @param Run $run
     */
    private function marked(array $run, int $floor): bool
    {
        foreach ($run['terms'] as $term) {
            if ($term['role'] !== 'corroborating' && 2 * $term['contribution'][0] >= $floor * $term['contribution'][1]) {
                return true;
            }
        }

        return false;
    }

    private function verdict(int $total): string
    {
        $band = $this->band($total);
        if ($band !== null) {
            return $band;
        }
        return $this->judgedZero;
    }

    private function band(int $total): ?string
    {
        foreach ($this->bands as $verdict => $floor) {
            if ($total >= $floor) {
                return $verdict;
            }
        }

        return null;
    }

    /**
     * @param array<mixed, mixed> $data
     *
     * @return list<array<mixed, mixed>>
     */
    private static function rows(array $data, string $key): array
    {
        $rows = [];
        foreach (JsonPath::arrayAt($data, [$key]) as $i => $row) {
            $rows[] = JsonPath::arrayAt($data, [$key, $i]);
        }

        return $rows;
    }

    /**
     * @param array<mixed, mixed> $data
     * @param list<string>        $path
     *
     * @return list<string>
     */
    private static function strings(array $data, array $path): array
    {
        $out = [];
        foreach (array_keys(JsonPath::arrayAt($data, $path)) as $i) {
            $out[] = JsonPath::stringAt($data, array_merge($path, [$i]));
        }

        return $out;
    }

    /**
     * @param Fraction $a
     * @param Fraction $b
     *
     * @return Fraction
     */
    private function plus(array $a, array $b): array
    {
        return $this->reduce($a[0] * $b[1] + $b[0] * $a[1], $a[1] * $b[1]);
    }

    /**
     * @param Fraction $a
     * @param Fraction $b
     *
     * @return Fraction
     */
    private function times(array $a, array $b): array
    {
        return $this->reduce($a[0] * $b[0], $a[1] * $b[1]);
    }

    /** @return Fraction */
    private function reduce(int $numerator, int $denominator): array
    {
        $a = $numerator;
        $b = $denominator;
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return [intdiv($numerator, $a), intdiv($denominator, $a)];
    }
}
