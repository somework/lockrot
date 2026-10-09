<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Json\JsonWriter;

/**
 * Counts report-2's root blocks and `run.score_rules_used` again from a decoded document's
 * `findings[]` alone, as a consumer that reads only the JSON does. It shares no code with
 * src/Analyzer, so a root block that disagrees with its findings gives a mismatch.
 */
final class RootRecount
{
    private const FLAG_ORDER = ['abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale', 'vulnerable'];
    private const GRADES = ['critical', 'high', 'medium', 'low'];
    private const SEVERITIES = ['critical', 'high', 'medium', 'unrated', 'low'];
    private const FIX_KINDS = ['update', 'upgrade', 'raise-php', 'unknown', 'blocked', 'none'];

    /**
     * @param array<mixed, mixed> $document a decoded report-2 document
     *
     * @return list<string> one line per block that its findings do not give
     */
    public static function mismatches(array $document): array
    {
        $findings = [];
        foreach (JsonPath::arrayAt($document, ['findings']) as $finding) {
            if (!\is_array($finding)) {
                throw new \UnexpectedValueException('a finding is not an object');
            }
            $findings[] = $finding;
        }
        $libyears = self::libyears($findings);
        $gate = self::gate($findings);
        $expected = [
            'flags' => self::flags($findings),
            'abandoned' => self::abandoned($findings),
            'priorities' => self::priorities($findings),
            'libyears' => $libyears,
            'gate' => $gate,
            'security' => self::security($findings),
            'data_date' => self::dataDate($findings),
            'run.score_rules_used' => self::scoreRulesUsed($findings, JsonPath::arrayAt($document, ['run', 'score_rules_used'])),
        ];
        $written = [
            'flags' => $document['flags'] ?? null,
            'abandoned' => $document['abandoned'] ?? null,
            'priorities' => $document['priorities'] ?? null,
            'libyears' => array_intersect_key(JsonPath::arrayAt($document, ['libyears']), $libyears),
            'gate' => array_intersect_key(JsonPath::arrayAt($document, ['gate']), $gate),
            'security' => $document['security'] ?? null,
            'data_date' => $document['data_date'] ?? null,
            'run.score_rules_used' => JsonPath::arrayAt($document, ['run', 'score_rules_used']),
        ];
        $bad = [];
        foreach ($expected as $block => $value) {
            $recounted = JsonWriter::encode($value, \JSON_UNESCAPED_SLASHES);
            $as = JsonWriter::encode($written[$block], \JSON_UNESCAPED_SLASHES);
            if ($recounted !== $as) {
                $bad[] = $block.': written '.$as.', recounted '.$recounted;
            }
        }

        return $bad;
    }

    /**
     * @param list<array<mixed, mixed>> $findings
     *
     * @return array<string, array<string, mixed>>
     */
    private static function flags(array $findings): array
    {
        $carrying = array_fill_keys(self::FLAG_ORDER, 0);
        $leading = $carrying;
        $acceptedAll = $carrying;
        $acceptedGraded = $carrying;
        $byVerdict = array_fill_keys(self::FLAG_ORDER, array_fill_keys(self::GRADES, 0));
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
                    if ($graded) {
                        ++$acceptedGraded[self::str($flag, 'id')];
                    }
                }
            }
        }
        $out = [];
        foreach (self::FLAG_ORDER as $flag) {
            $maintenance = $flag !== 'vulnerable';
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
     * @param list<array<mixed, mixed>> $findings
     *
     * @return array{total: int, with_replacement: int, with_suggestion: int}
     */
    private static function abandoned(array $findings): array
    {
        $total = 0;
        $withReplacement = 0;
        $withSuggestion = 0;
        foreach ($findings as $finding) {
            if (!\in_array('abandoned', self::countedFlags($finding), true)) {
                continue;
            }
            ++$total;
            if (self::str($finding, 'replacement') !== '') {
                ++$withReplacement;
                continue;
            }
            foreach (self::rows($finding, 'signals') as $signal) {
                if (self::str($signal, 'id') === 'S1' && self::str(self::map($signal, 'data'), 'replacement') !== '') {
                    ++$withSuggestion;
                }
            }
        }

        return ['total' => $total, 'with_replacement' => $withReplacement, 'with_suggestion' => $withSuggestion];
    }

    /**
     * @param list<array<mixed, mixed>> $findings
     *
     * @return array<string, int>
     */
    private static function priorities(array $findings): array
    {
        $counts = array_fill_keys(array_merge(self::GRADES, ['none']), 0);
        foreach ($findings as $finding) {
            ++$counts[self::str($finding, 'priority')];
        }

        return $counts;
    }

    /**
     * The sums over the written two-decimal values, in the written order.
     *
     * @param list<array<mixed, mixed>> $findings
     *
     * @return array{total: ?float, direct_requirements: ?float, packages: int}
     */
    private static function libyears(array $findings): array
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

        return ['total' => $total === null ? null : round($total, 2), 'direct_requirements' => $direct === null ? null : round($direct, 2), 'packages' => \count($findings)];
    }

    /**
     * @param list<array<mixed, mixed>> $findings
     *
     * @return array{reaching: int, failing: int, exempt: array<string, int>}
     */
    private static function gate(array $findings): array
    {
        $reaching = 0;
        $failing = 0;
        $exempt = ['baseline' => 0];
        foreach ($findings as $finding) {
            $standing = self::map($finding, 'gate');
            if (($standing['reaches_fail_on'] ?? false) === true) {
                ++$reaching;
            }
            if (($standing['fails'] ?? false) === true) {
                ++$failing;
            }
            $by = self::str($standing, 'exempt_by');
            if ($by !== '') {
                $exempt[$by] = ($exempt[$by] ?? 0) + 1;
            }
        }

        return ['reaching' => $reaching, 'failing' => $failing, 'exempt' => $exempt];
    }

    /**
     * @param list<array<mixed, mixed>> $findings
     *
     * @return array<string, mixed>
     */
    private static function security(array $findings): array
    {
        $checks = [];
        $packages = ['vulnerable' => 0, 'unchecked' => 0, 'ignored' => 0, 'clear' => 0];
        $advisories = ['counted' => 0, 'ignored' => 0];
        $severities = array_fill_keys(self::SEVERITIES, 0);
        $fixes = array_fill_keys(self::FIX_KINDS, 0);
        $updateNow = [];
        foreach ($findings as $finding) {
            $security = self::map($finding, 'security');
            $status = self::str($security, 'status');
            $check = self::str($security, 'check');
            $checks[$check] = $check;
            $packages[$status] = ($packages[$status] ?? 0) + 1;
            $ignored = \is_int($security['ignored_count'] ?? null) ? $security['ignored_count'] : 0;
            if ($ignored > 0) {
                ++$packages['ignored'];
            }
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

    /** @param list<array<mixed, mixed>> $findings */
    private static function dataDate(array $findings): ?string
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
     * Every rule that a finding's numbers show, keyed as the written block keys them: a rule that
     * the findings cannot show keeps its written value.
     *
     * @param list<array<mixed, mixed>> $findings
     * @param array<mixed, mixed>       $written
     *
     * @return array<mixed, mixed>
     */
    private static function scoreRulesUsed(array $findings, array $written): array
    {
        $used = array_fill_keys(['counted', 'lead-first', 'corroborating-share', 'advisory-points', 'no-reachable-fix-multiplier', 'security-max', 'divide-reach', 'security-exempt-from-reach', 'sum', 'divide-dev', 'floor-once', 'band-floors', 'zero-verdicts'], 0);
        foreach ($findings as $finding) {
            $score = self::map($finding, 'score');
            $accepted = self::rows($score, 'accepted');
            if ($accepted !== []) {
                ++$used['counted'];
            }
            $rerunHalves = static function (string $key, string $value) use ($accepted): bool {
                foreach ($accepted as $row) {
                    if (self::halves(self::rows(self::map($row, 'if_counted'), 'modifiers'), $key, $value)) {
                        return true;
                    }
                }

                return false;
            };
            if (!isset($score['terms'])) {
                $used['divide-reach'] += $rerunHalves('applies_to', 'maintenance') ? 1 : 0;
                $used['divide-dev'] += $rerunHalves('reason', 'dev') ? 1 : 0;
                ++$used['zero-verdicts'];
                continue;
            }
            $terms = self::rows($score, 'terms');
            $modifiers = self::rows($score, 'modifiers');
            $parts = self::map($score, 'parts');
            $security = self::any($terms, 'part', 'security');
            ++$used['band-floors'];
            $used['lead-first'] += self::any($terms, 'role', 'lead') ? 1 : 0;
            $used['corroborating-share'] += self::any($terms, 'role', 'corroborating') ? 1 : 0;
            $used['advisory-points'] += $security ? 1 : 0;
            $used['no-reachable-fix-multiplier'] += self::any($terms, 'multiplier', 2) ? 1 : 0;
            $of = self::map($parts, 'security')['of'] ?? null;
            $used['security-max'] += \is_int($of) && $of >= 2 ? 1 : 0;
            $used['divide-reach'] += self::halves($modifiers, 'applies_to', 'maintenance') || $rerunHalves('applies_to', 'maintenance') ? 1 : 0;
            $used['security-exempt-from-reach'] += $security && ($finding['reach'] ?? null) !== 'direct' ? 1 : 0;
            $used['sum'] += self::str(self::map($parts, 'maintenance'), 'status') === 'counted' && self::str(self::map($parts, 'security'), 'status') === 'counted' ? 1 : 0;
            $used['divide-dev'] += self::any($modifiers, 'reason', 'dev') || $rerunHalves('reason', 'dev') ? 1 : 0;
            $used['floor-once'] += ($score['rounded_down'] ?? false) === true ? 1 : 0;
        }

        return array_merge($written, $used);
    }

    /**
     * @param array<mixed, mixed> $finding
     *
     * @return list<string>
     */
    private static function countedFlags(array $finding): array
    {
        $flags = [];
        foreach (self::rows(self::map($finding, 'score'), 'terms') as $term) {
            $flags[] = self::str($term, 'flag');
        }

        return $flags;
    }

    /**
     * @param list<array<mixed, mixed>> $rows
     * @param mixed                     $value
     */
    private static function any(array $rows, string $key, $value): bool
    {
        foreach ($rows as $row) {
            if (($row[$key] ?? null) === $value) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<mixed, mixed>> $modifiers */
    private static function halves(array $modifiers, string $key, string $value): bool
    {
        foreach ($modifiers as $modifier) {
            if (($modifier[$key] ?? null) === $value && ($modifier['before'] ?? null) !== ($modifier['after'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<mixed, mixed> $row */
    private static function str(array $row, string $key): string
    {
        return \is_string($row[$key] ?? null) ? $row[$key] : '';
    }

    /**
     * @param array<mixed, mixed> $row
     *
     * @return array<mixed, mixed>
     */
    private static function map(array $row, string $key): array
    {
        return \is_array($row[$key] ?? null) ? $row[$key] : [];
    }

    /**
     * @param array<mixed, mixed> $row
     *
     * @return list<array<mixed, mixed>>
     */
    private static function rows(array $row, string $key): array
    {
        return array_values(array_filter(self::map($row, $key), 'is_array'));
    }
}
