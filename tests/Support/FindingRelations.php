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
        $run = \is_array($document['run'] ?? null) ? $document['run'] : [];
        $gates = array_column(\is_array($run['gates'] ?? null) ? $run['gates'] : [], 'value');
        $modelId = $run['score_model']['id'] ?? null;
        $repositories = array_column(\is_array($run['advisory_lookup']['repositories'] ?? null) ? $run['advisory_lookup']['repositories'] : [], 'composer_repository');
        $findings = \is_array($document['findings'] ?? null) ? $document['findings'] : [];
        foreach ($findings as $i => $finding) {
            if (!\is_array($finding)) {
                continue;
            }
            foreach (self::finding($finding, $gates, $modelId, $repositories) as $broken) {
                $out[] = 'findings['.$i.'] '.$finding['package'].': '.$broken;
            }
        }
        foreach (self::root($document, $findings, $gates) as $broken) {
            $out[] = $broken;
        }

        return $out;
    }

    /**
     * @param array<mixed, mixed> $f
     * @param list<mixed>         $gates
     * @param mixed               $modelId
     * @param list<mixed>         $repositories
     *
     * @return list<string>
     */
    private static function finding(array $f, array $gates, $modelId, array $repositories): array
    {
        $out = [];
        $ids = array_column($f['signals'], 'id');
        $security = $f['security'];
        $flagIds = array_column($f['flags'], 'id');
        $counted = array_filter($f['flags'], static fn (array $flag): bool => $flag['id'] === 'vulnerable' && $flag['role'] !== 'accepted');
        if (\in_array('S9', $ids, true) !== ($counted !== [])) {
            $out[] = 'S9 is present exactly when a counted advisory is';
        }
        if ($security['ignored_count'] !== \count($security['ignored'])) {
            $out[] = 'security.ignored_count is the length of security.ignored';
        }
        if ($security['status'] === 'clear' && $security['complete'] !== true) {
            $out[] = 'security.status clear only on a complete lookup';
        }
        $s9ids = [];
        foreach ($f['signals'] as $signal) {
            if ($signal['id'] === 'S9') {
                $s9ids = array_column($signal['data']['advisories'], 'id');
            }
        }
        if (array_intersect($s9ids, array_column($security['ignored'], 'id')) !== []) {
            $out[] = 'security.ignored is disjoint from the S9 rows';
        }
        foreach (array_column($security['feeds'], 'composer_repository') as $feed) {
            if ($repositories !== [] && !\in_array($feed, $repositories, true)) {
                $out[] = 'each security.feeds[] repository is one of run.advisory_lookup.repositories[]';
            }
        }
        $branchFixes = $security['installed_branch_fixes'] ?? null;
        if ($security['status'] === 'vulnerable' && ($branchFixes === null) !== ($f['branch'] === null)) {
            $out[] = 'security.installed_branch_fixes is null exactly when branch is';
        }
        if (\is_array($branchFixes) && ($f['score']['parts']['security']['of'] ?? null) !== null && $branchFixes['of'] !== $f['score']['parts']['security']['of']) {
            $out[] = "installed_branch_fixes.of is the security part's of";
        }
        foreach ($f['signals'] as $signal) {
            if ($signal['id'] === 'S8' && ($signal['data']['branch'] ?? null) !== $f['branch']) {
                $out[] = "branch is S8's branch";
            }
        }
        $replacement = $f['next_step']['replacement']['package'] ?? null;
        if (\is_array($f['next_step'] ?? null) && \is_array($f['next_step']['replacement'] ?? null) && $replacement !== $f['replacement']) {
            $out[] = 'replacement is next_step.replacement.package';
        }
        $score = $f['score'];
        if (isset($score['terms'])) {
            $sum = 0.0;
            foreach ($score['terms'] as $term) {
                $sum += $term['contribution'];
            }
            if (abs($sum - $score['exact']) > 1e-9) {
                $out[] = 'the term contributions add up to score.exact';
            }
        }
        if ($modelId !== null && ($score['model'] ?? $modelId) !== $modelId) {
            $out[] = 'score.model is run.score_model.id';
        }
        $cause = $f['libyears_unmeasured'] ?? null;
        if (\in_array($cause, ['shared_commit', 'undated'], true) && ($f['age']['installed_unmeasured'] ?? null) !== $cause) {
            $out[] = 'a libyears_unmeasured of shared_commit or undated is age.installed_unmeasured';
        }
        if (\count($flagIds) !== \count(array_unique($flagIds))) {
            $out[] = 'each flag once';
        }
        $gate = $f['gate'];
        foreach ($gate['by'] as $by) {
            if (!\in_array($by['value'], $gates, true)) {
                $out[] = 'each gate.by[].value is a run.gates[] value';
            }
        }
        $basis = $gate['basis'] ?? null;
        if (\is_array($basis) && (($basis['verdict'] === null) !== ($basis['score'] === 0))) {
            $out[] = 'gate.basis.verdict is null exactly when the gate score is 0';
        }

        return $out;
    }

    /**
     * @param array<mixed, mixed> $document
     * @param array<mixed, mixed> $findings
     * @param list<mixed>         $gates
     *
     * @return list<string>
     */
    private static function root(array $document, array $findings, array $gates): array
    {
        $out = [];
        $gate = $document['gate'];
        $failingBy = [];
        foreach ($gates as $value) {
            $failing = 0;
            foreach ($findings as $finding) {
                $failing += \in_array($value, array_column($finding['gate']['by'], 'value'), true) ? 1 : 0;
            }
            $failingBy[] = ['value' => $value, 'failing' => $failing];
        }
        if ($gate['failing_by'] !== $failingBy) {
            $out[] = 'gate.failing_by: one entry per run.gates value, in its order, each counting the findings whose gate.by names it';
        }
        $entries = $gate['not_applied'];
        $keys = array_map(static fn (array $entry): string => $entry['check'].'/'.$entry['reason'], $entries);
        if (\count($keys) !== \count(array_unique($keys))) {
            $out[] = 'gate.not_applied: one entry per (check, reason) (I34)';
        }
        $sorted = $entries;
        usort($sorted, static fn (array $a, array $b): int => [$b['packages'], $a['reason']] <=> [$a['packages'], $b['reason']]);
        if ($sorted !== $entries) {
            $out[] = 'gate.not_applied: by packages descending, then by reason';
        }
        foreach ($entries as $entry) {
            $packages = 0;
            foreach ($findings as $finding) {
                $packages += $finding['security']['unchecked_reason'] === $entry['reason'] && $finding['security']['status'] === 'unchecked' ? 1 : 0;
            }
            if ($entry['check'] === 'advisories' && $entry['packages'] > $packages) {
                $out[] = 'gate.not_applied: an entry counts the findings with its reason (I34)';
            }
        }
        $cached = [];
        foreach ($findings as $finding) {
            $at = $finding['activity']['cached_at'] ?? null;
            if (\is_string($at)) {
                $cached[] = strtotime($at);
            }
        }
        $oldest = $document['activity_cache_oldest_at'] ?? null;
        if ($cached === [] ? $oldest !== null : ($oldest === null || strtotime($oldest) !== min($cached))) {
            $out[] = 'activity_cache_oldest_at is the oldest findings[].activity.cached_at (A9)';
        }
        $hours = $document['activity_cache_age_hours'] ?? null;
        if (($hours === null) !== ($oldest === null) || ($oldest !== null && abs($hours - (strtotime($document['generated_at']) - strtotime($oldest)) / 3600) >= 0.1)) {
            $out[] = 'activity_cache_age_hours is the hours from activity_cache_oldest_at to generated_at, to one decimal (A9)';
        }
        if (array_column($document['note_details'], 'text') !== $document['notes']) {
            $out[] = 'notes[i] is note_details[i].text';
        }

        return $out;
    }
}
