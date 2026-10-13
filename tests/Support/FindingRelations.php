<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

/**
 * The relations of a report-2 document that draft-04 cannot say (SPEC-0.14.md §8.5.7, the
 * engine-only list): arithmetic between fields, equality of two lists, a value that another object
 * holds. {@see violations()} names each relation that a document breaks, so a test reads them
 * strictly over every fixture document and every report that lockrot writes.
 */
final class FindingRelations
{
    /**
     * Each broken relation, as `<path>: <relation>`. An empty list means the document holds them all.
     *
     * @param array<mixed, mixed> $document a decoded report-2 document
     *
     * @return list<string>
     */
    public static function violations(array $document): array
    {
        $out = [];
        $gates = JsonPath::column($document, ['run', 'gates'], 'value');
        $modelId = JsonPath::has($document, ['run', 'score_model', 'id']) ? JsonPath::intAt($document, ['run', 'score_model', 'id']) : null;
        $repositories = JsonPath::has($document, ['run', 'advisory_lookup', 'repositories']) ? JsonPath::column($document, ['run', 'advisory_lookup', 'repositories'], 'composer_repository') : [];
        $findings = self::rows($document, ['findings']);
        foreach ($findings as $i => $finding) {
            foreach (self::finding($finding, $gates, $modelId, $repositories) as $broken) {
                $out[] = 'findings['.$i.'] '.JsonPath::stringAt($finding, ['package']).': '.$broken;
            }
        }

        return array_merge($out, self::root($document, $findings, $gates));
    }

    /**
     * @param array<mixed, mixed> $f
     * @param list<mixed>         $gates
     * @param list<mixed>         $repositories
     *
     * @return list<string>
     */
    private static function finding(array $f, array $gates, ?int $modelId, array $repositories): array
    {
        $out = [];
        $ids = JsonPath::column($f, ['signals'], 'id');
        $flags = self::rows($f, ['flags']);
        $counted = array_filter($flags, static fn (array $flag): bool => ($flag['id'] ?? null) === 'vulnerable' && ($flag['role'] ?? null) !== 'accepted');
        if (\in_array('S9', $ids, true) !== ($counted !== [])) {
            $out[] = 'S9 is present exactly when a counted advisory is';
        }
        $status = JsonPath::stringAt($f, ['security', 'status']);
        if (JsonPath::intAt($f, ['security', 'ignored_count']) !== \count(JsonPath::arrayAt($f, ['security', 'ignored']))) {
            $out[] = 'security.ignored_count is the length of security.ignored';
        }
        if ($status === 'clear' && !JsonPath::boolAt($f, ['security', 'complete'])) {
            $out[] = 'security.status clear only on a complete lookup';
        }
        $s9ids = [];
        foreach (self::rows($f, ['signals']) as $signal) {
            if (($signal['id'] ?? null) === 'S9') {
                $s9ids = JsonPath::column($signal, ['data', 'advisories'], 'id');
            }
        }
        $ignoredIds = JsonPath::column($f, ['security', 'ignored'], 'id');
        if (array_filter($s9ids, static fn ($id): bool => \in_array($id, $ignoredIds, true)) !== []) {
            $out[] = 'security.ignored is disjoint from the S9 rows';
        }
        foreach (JsonPath::column($f, ['security', 'feeds'], 'composer_repository') as $feed) {
            if ($repositories !== [] && !\in_array($feed, $repositories, true)) {
                $out[] = 'each security.feeds[] repository is one of run.advisory_lookup.repositories[]';
            }
        }
        $branch = self::stringOrNull($f, ['branch']);
        $branchFixes = self::arrayOrNull($f, ['security', 'installed_branch_fixes']);
        if ($status === 'vulnerable' && ($branchFixes === null) !== ($branch === null)) {
            $out[] = 'security.installed_branch_fixes is null exactly when branch is';
        }
        $securityOf = self::number($f, ['score', 'parts', 'security', 'of']);
        if ($branchFixes !== null && $securityOf !== null && self::number($branchFixes, ['of']) !== $securityOf) {
            $out[] = "installed_branch_fixes.of is the security part's of";
        }
        foreach (self::rows($f, ['signals']) as $signal) {
            if (($signal['id'] ?? null) === 'S8' && JsonPath::has($signal, ['data', 'branch']) && self::stringOrNull($signal, ['data', 'branch']) !== $branch) {
                $out[] = "branch is S8's branch";
            }
        }
        $replacement = self::arrayOrNull($f, ['next_step', 'replacement']);
        if ($replacement !== null && ($replacement['package'] ?? null) !== ($f['replacement'] ?? null)) {
            $out[] = 'replacement is next_step.replacement.package';
        }
        if (JsonPath::has($f, ['score', 'terms'])) {
            $sum = 0.0;
            foreach (self::rows($f, ['score', 'terms']) as $term) {
                $sum += self::number($term, ['contribution']) ?? 0.0;
            }
            $exact = self::number($f, ['score', 'exact']);
            if ($exact === null || abs($sum - $exact) > 1e-9) {
                $out[] = 'the term contributions add up to score.exact';
            }
        }
        if ($modelId !== null && JsonPath::has($f, ['score', 'model']) && JsonPath::intAt($f, ['score', 'model']) !== $modelId) {
            $out[] = 'score.model is run.score_model.id';
        }
        $cause = self::stringOrNull($f, ['libyears_unmeasured']);
        if (\in_array($cause, ['shared_commit', 'undated'], true) && self::stringOrNull($f, ['age', 'installed_unmeasured']) !== $cause) {
            $out[] = 'a libyears_unmeasured of shared_commit or undated is age.installed_unmeasured';
        }
        $flagIds = JsonPath::column($f, ['flags'], 'id');
        if (\count($flagIds) !== \count(array_unique($flagIds, \SORT_REGULAR))) {
            $out[] = 'each flag once';
        }
        foreach (JsonPath::column($f, ['gate', 'by'], 'value') as $value) {
            if (!\in_array($value, $gates, true)) {
                $out[] = 'each gate.by[].value is a run.gates[] value';
            }
        }
        $basis = self::arrayOrNull($f, ['gate', 'basis']);
        if ($basis !== null && (($basis['verdict'] ?? null) === null) !== (($basis['score'] ?? null) === 0)) {
            $out[] = 'gate.basis.verdict is null exactly when the gate score is 0';
        }

        return $out;
    }

    /**
     * @param array<mixed, mixed>       $document
     * @param list<array<mixed, mixed>> $findings
     * @param list<mixed>               $gates
     *
     * @return list<string>
     */
    private static function root(array $document, array $findings, array $gates): array
    {
        $out = [];
        $failingBy = [];
        foreach ($gates as $value) {
            $failing = 0;
            foreach ($findings as $finding) {
                $failing += \in_array($value, JsonPath::column($finding, ['gate', 'by'], 'value'), true) ? 1 : 0;
            }
            $failingBy[] = ['value' => $value, 'failing' => $failing];
        }
        if (JsonPath::arrayAt($document, ['gate', 'failing_by']) !== $failingBy) {
            $out[] = 'gate.failing_by: one entry per run.gates value, in its order, each counting the findings whose gate.by names it';
        }
        $entries = [];
        foreach (self::rows($document, ['gate', 'not_applied']) as $entry) {
            $entries[] = [JsonPath::stringAt($entry, ['check']), JsonPath::intAt($entry, ['packages']), JsonPath::stringAt($entry, ['reason'])];
        }
        $keys = array_map(static fn (array $entry): string => $entry[0].'/'.$entry[2], $entries);
        if (\count($keys) !== \count(array_unique($keys))) {
            $out[] = 'gate.not_applied: one entry per (check, reason) (I34)';
        }
        $sorted = $entries;
        usort($sorted, static fn (array $a, array $b): int => [$b[1], $a[2]] <=> [$a[1], $b[2]]);
        if ($sorted !== $entries) {
            $out[] = 'gate.not_applied: by packages descending, then by reason';
        }
        foreach ($entries as [$check, $packages, $reason]) {
            $unchecked = 0;
            foreach ($findings as $finding) {
                $unchecked += self::stringOrNull($finding, ['security', 'unchecked_reason']) === $reason && self::stringOrNull($finding, ['security', 'status']) === 'unchecked' ? 1 : 0;
            }
            if ($check === 'advisories' && $packages > $unchecked) {
                $out[] = 'gate.not_applied: an entry counts the findings with its reason (I34)';
            }
        }
        $cached = [];
        foreach ($findings as $finding) {
            $at = self::stringOrNull($finding, ['activity', 'cached_at']);
            if ($at !== null) {
                $cached[] = (int) strtotime($at);
            }
        }
        $oldest = self::stringOrNull($document, ['activity_cache_oldest_at']);
        if ($cached === [] ? $oldest !== null : ($oldest === null || strtotime($oldest) !== min($cached))) {
            $out[] = 'activity_cache_oldest_at is the oldest findings[].activity.cached_at (A9)';
        }
        $hours = self::number($document, ['activity_cache_age_hours']);
        $age = $oldest === null ? null : ((int) strtotime(JsonPath::stringAt($document, ['generated_at'])) - (int) strtotime($oldest)) / 3600;
        if (($hours === null) !== ($age === null) || ($hours !== null && $age !== null && abs($hours - $age) >= 0.1)) {
            $out[] = 'activity_cache_age_hours is the hours from activity_cache_oldest_at to generated_at, to one decimal (A9)';
        }
        if (JsonPath::column($document, ['note_details'], 'text') !== JsonPath::arrayAt($document, ['notes'])) {
            $out[] = 'notes[i] is note_details[i].text';
        }

        return $out;
    }

    /**
     * The objects of the list at $path.
     *
     * @param array<mixed, mixed> $data
     * @param list<string>        $path
     *
     * @return list<array<mixed, mixed>>
     */
    private static function rows(array $data, array $path): array
    {
        $rows = [];
        foreach (JsonPath::arrayAt($data, $path) as $row) {
            if (\is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<mixed, mixed> $data
     * @param list<string>        $path
     */
    private static function stringOrNull(array $data, array $path): ?string
    {
        return JsonPath::has($data, $path) && \is_string(self::at($data, $path)) ? JsonPath::stringAt($data, $path) : null;
    }

    /**
     * @param array<mixed, mixed> $data
     * @param list<string>        $path
     *
     * @return ?array<mixed, mixed>
     */
    private static function arrayOrNull(array $data, array $path): ?array
    {
        return JsonPath::has($data, $path) && \is_array(self::at($data, $path)) ? JsonPath::arrayAt($data, $path) : null;
    }

    /**
     * @param array<mixed, mixed> $data
     * @param list<string>        $path
     */
    private static function number(array $data, array $path): ?float
    {
        $value = JsonPath::has($data, $path) ? self::at($data, $path) : null;

        return \is_int($value) || \is_float($value) ? (float) $value : null;
    }

    /**
     * @param array<mixed, mixed> $data
     * @param list<string>        $path
     *
     * @return mixed
     */
    private static function at(array $data, array $path)
    {
        $value = $data;
        foreach ($path as $key) {
            $value = \is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }
}
