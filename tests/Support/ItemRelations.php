<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

/**
 * The engine-only relations of the 0.14 items that draft-04 cannot say: C73 A1 to A10 (A9 in
 * {@see FindingRelations}), C75 R1 to R8, C77 pairing and status, W12 reach, I25 S7 coverage and I28
 * (SPEC-0.14.md §3). The predicates follow the model's `check_014` and `validate_root`.
 * I28 includes C72's headline and quiet_years relations. C69 (I30) is held by the report-2
 * schema relation and its negatives.
 */
final class ItemRelations
{
    private const GRADES = ['critical', 'high', 'medium', 'low'];
    private const SECONDS_PER_YEAR = 31557600;
    private const AGES = ['installed', 'release', 'branch_release', 'push'];

    /**
     * Each broken relation, as `<path>: <relation>`.
     *
     * @param array<mixed, mixed> $d       a decoded report-2 document
     * @param bool                $exposed false for a document that holds part of a report: I25 reads every finding
     *
     * @return list<string>
     */
    public static function violations(array $d, bool $exposed = true): array
    {
        $out = [];
        $findings = self::rows($d, 'findings');
        foreach ($findings as $i => $f) {
            foreach (self::finding($d, $f) as $broken) {
                $out[] = 'findings['.$i.'] '.self::text($f, 'package').': '.$broken;
            }
        }
        $out = array_merge($out, self::activityRoot($d, $findings), self::lookupRoot($d, $findings), self::rotDepth($d, $findings), self::libyears($d, $findings));

        return $exposed ? array_merge($out, self::exposure($d, $findings)) : $out;
    }

    /**
     * @param array<mixed, mixed> $d
     * @param array<mixed, mixed> $f
     *
     * @return list<string>
     */
    private static function finding(array $d, array $f): array
    {
        $out = [];
        $a = self::map($f, 'activity');
        $status = $a['status'] ?? null;
        $reason = $a['reason'] ?? null;
        $signals = [];
        foreach (self::rows($f, 'signals') as $signal) {
            $signals[self::text($signal, 'id')] = $signal;
        }
        $missing = array_merge(self::rows($f, 'checks_missing'), self::rows($f, 'checks_skipped'));
        $age = self::map($f, 'age');

        if (($status === 'read') !== ($reason === null) || ($status === 'read' && (($a['event'] ?? null) === null || ($a['fetched_at'] ?? null) === null))) {
            $out[] = 'C73 A1: activity read exactly without a reason, with an event and a fetch time';
        }
        if (($status === 'not_found') !== ($reason === 'repository_not_found') || ($status === 'not_found' && (($a['forge'] ?? null) === null || ($a['host'] ?? null) === null || ($a['repo'] ?? null) === null))) {
            $out[] = 'C73 A2: not_found exactly for repository_not_found, with forge, host and repo';
        }
        if ($status !== 'read' && (($a['event'] ?? null) !== null || ($a['archived'] ?? null) !== null || ($a['fetched_at'] ?? null) !== null || ($a['cached_at'] ?? null) !== null)) {
            $out[] = 'C73 A3: an activity not read has no event, archived, fetched_at or cached_at';
        }
        if ((($a['forge'] ?? null) === null) !== (($a['repo'] ?? null) === null) || (($a['forge'] ?? null) !== null && ($a['host'] ?? null) === null)
            || ($reason === 'host_not_supported' && (($a['host'] ?? null) === null || ($a['forge'] ?? null) !== null))) {
            $out[] = 'C73 A4: forge and repo together, a forge with a host, an unsupported host without a forge';
        }
        $entries = array_values(array_filter($missing, static fn (array $c): bool => ($c['check'] ?? null) === 'repository_activity'));
        $entriesHold = \count($entries) <= 1 && ($status !== 'read') === (\count($entries) === 1);
        foreach ($entries as $c) {
            $entriesHold = $entriesHold && self::entryReason($f, $c) === $reason && ($c['blocks'] ?? null) === ['S3', 'S4'];
        }
        if (!$entriesHold) {
            $out[] = 'C73 A5: one repository_activity check exactly when the activity is not read, with its reason';
        }
        $archived = array_values(array_filter(self::rows($f, 'checks_skipped'), static fn (array $c): bool => ($c['check'] ?? null) === 'repository_archived'));
        $archivedHold = \count($archived) <= 1 && ($status === 'read' && ($a['archived'] ?? null) === null) === (\count($archived) === 1);
        foreach ($archived as $c) {
            $archivedHold = $archivedHold && \in_array($c['reason'] ?? null, ['no_token', 'fetch_failed'], true) && ($c['blocks'] ?? null) === ['S3'];
        }
        if (!$archivedHold) {
            $out[] = 'C73 A6: one repository_archived skip exactly when a read activity has no archived bit';
        }
        $s3 = isset($signals['S3']) ? self::map($signals['S3'], 'data') : null;
        if ($s3 === null ? ($a['archived'] ?? null) === true : ($status !== 'read' || ($a['archived'] ?? null) !== true || self::pick($s3, 'forge', 'host', 'repo') !== self::pick($a, 'forge', 'host', 'repo'))) {
            $out[] = 'C73 A7: S3 exactly when the read activity is archived, on its repository';
        }
        if (isset($signals['S4'])) {
            $s4 = self::map($signals['S4'], 'data');
            if ($status !== 'read' || self::pick($s4, 'forge', 'host', 'repo', 'event') !== self::pick($a, 'forge', 'host', 'repo', 'event') || ($s4['last_push'] ?? null) !== (self::map($age, 'push')['at'] ?? null)) {
                $out[] = 'C73 A8: S4 quotes the read activity and age.push';
            }
        }

        $out = array_merge($out, self::security($d, $f, $signals, $missing));

        foreach (self::AGES as $k) {
            if ((($age[$k] ?? null) === null) === (($age[$k.'_unmeasured'] ?? null) === null)) {
                $out[] = 'C77 pairing: age.'.$k.' is null exactly when age.'.$k.'_unmeasured is set (I28)';
            }
        }
        if ((($f['libyears'] ?? null) === null) === (($f['libyears_unmeasured'] ?? null) === null) || (($f['depth'] ?? null) === null) === (($f['depth_unmeasured'] ?? null) === null)) {
            $out[] = 'C77 pairing: libyears and depth are null exactly when their reason is set';
        }
        if (($f['libyears_at_least'] ?? null) === true && ($f['libyears'] ?? null) === null) {
            $out[] = 'C77 pairing: libyears_at_least only beside libyears';
        }
        $metadata = self::map($f, 'metadata')['status'] ?? null;
        if ($metadata !== 'read' && (($f['libyears_unmeasured'] ?? null) !== $metadata || ($age['release_unmeasured'] ?? null) !== $metadata || ($age['branch_release_unmeasured'] ?? null) !== $metadata)) {
            $out[] = 'C77 status: metadata not read is the reason of libyears, age.release and age.branch_release';
        }
        if ($metadata === 'read' && \in_array($f['libyears_unmeasured'] ?? null, ['not_from_composer_repository', 'unavailable', 'not_found'], true)) {
            $out[] = 'C77 status: read metadata gives no metadata reason to libyears';
        }
        if ($status !== 'read' && ($age['push_unmeasured'] ?? null) !== $reason) {
            $out[] = 'C77 status: an activity not read is the reason of age.push';
        }

        return array_merge($out, self::reach($d, $f), self::ages($d, $f, $signals, $missing, $reason));
    }

    /**
     * C75 R1 to R3, R6 and R7.
     *
     * @param array<mixed, mixed>                $d
     * @param array<mixed, mixed>                $f
     * @param array<string, array<mixed, mixed>> $signals
     * @param list<array<mixed, mixed>>          $missing
     *
     * @return list<string>
     */
    private static function security(array $d, array $f, array $signals, array $missing): array
    {
        $out = [];
        $s = self::map($f, 'security');
        $repositories = self::rows(self::map(self::map($d, 'run'), 'advisory_lookup'), 'repositories');
        $names = [];
        foreach ($repositories as $r) {
            if (($r['outcome'] ?? null) !== 'no_feed') {
                $names[] = $r['composer_repository'] ?? null;
            }
        }
        $feeds = self::rows($s, 'feeds');
        $feedNames = array_map(static fn (array $feed) => $feed['composer_repository'] ?? null, $feeds);
        $at = 0;
        $inOrder = \count(array_unique($feedNames, \SORT_REGULAR)) === \count($feedNames);
        foreach ($feedNames as $name) {
            while ($at < \count($names) && $names[$at] !== $name) {
                ++$at;
            }
            $inOrder = $inOrder && $at < \count($names);
            ++$at;
        }
        if (!$inOrder) {
            $out[] = 'C75 R1: security.feeds name the asked repositories once each, in their order';
        }
        $answered = [];
        foreach ($feeds as $feed) {
            if (($feed['answer'] ?? null) === 'answered' && \is_int($feed['records'] ?? null)) {
                $answered[] = $feed['records'];
            }
        }
        $records = $s['records'] ?? null;
        if (($records === null) !== ($answered === []) || ($answered !== [] && (!\is_int($records) || $records < max($answered) || $records > array_sum($answered)))) {
            $out[] = 'C75 R2: security.records lies between the largest and the sum of the answered feeds';
        }
        if (($s['check'] ?? null) === 'partial') {
            $failed = array_filter($feeds, static fn (array $feed): bool => ($feed['answer'] ?? null) === 'failed') !== [];
            $budget = array_filter($repositories, static fn (array $r): bool => ($r['outcome'] ?? null) === 'not_asked' && ($r['reason'] ?? null) === 'install_time_budget') !== [];
            if (!$failed && !$budget) {
                $out[] = 'C75 R3: a partial lookup has a failed feed or a repository the budget left';
            }
        }
        $rows = isset($signals['S9']) ? \count(self::rows(self::map($signals['S9'], 'data'), 'advisories')) : 0;
        if (\is_int($records) && $records < $rows + (\is_int($s['ignored_count'] ?? null) ? $s['ignored_count'] : 0)) {
            $out[] = 'C75 R6: security.records holds the S9 rows and the ignored ones';
        }
        foreach ($missing as $c) {
            if (($c['check'] ?? null) === 'advisories') {
                if (($s['unchecked_reason'] ?? null) !== self::entryReason($f, $c)) {
                    $out[] = "C75 R7: security.unchecked_reason is the advisories check's reason";
                }
                break;
            }
        }

        return $out;
    }

    /**
     * W12: depth, reach and named_in of one finding.
     *
     * @param array<mixed, mixed> $d
     * @param array<mixed, mixed> $f
     *
     * @return list<string>
     */
    private static function reach(array $d, array $f): array
    {
        $out = [];
        $chain = \is_array($f['chain'] ?? null) ? $f['chain'] : [];
        $depth = $f['depth'] ?? null;
        $reach = $f['reach'] ?? null;
        $namedIn = \is_array($f['named_in'] ?? null) ? $f['named_in'] : [];
        if ($depth !== ($chain === [] ? null : \count($chain) - 1)) {
            $out[] = 'W12: depth is the chain length less one';
        }
        if (($reach === 'direct') !== ($depth === 0) || ($reach === 'unreached') !== ($depth === null)) {
            $out[] = 'W12: reach direct at depth 0, unreached without a depth';
        }
        $includeDev = self::map($d, 'run')['include_dev'] ?? null;
        if (($depth === 0 && $namedIn === []) || (\in_array('require', $namedIn, true) && $reach !== 'direct')
            || $namedIn !== array_values(array_filter(['require', 'require-dev'], static fn (string $part): bool => \in_array($part, $namedIn, true)))
            || (\is_int($depth) && $depth > 0 && $namedIn !== [] && ($namedIn !== ['require-dev'] || $includeDev !== false))) {
            $out[] = 'W12: named_in, in its order, names a direct finding, and a deeper one only from an unwalked require-dev';
        }
        $why = $f['depth_unmeasured'] ?? null;
        if (($why === 'unreached' && $reach !== 'unreached') || ($reach === 'unreached' && !\in_array($why, ['unreached', 'no_composer_json'], true))) {
            $out[] = 'W12: depth_unmeasured unreached exactly for an unreached finding';
        }

        return $out;
    }

    /**
     * I28 (SPEC-0.14.md §3): each age reading in tenths of a year, the quotes of S2, S4 and S8, the
     * headline years, the quiet years and the own line of a pinned move.
     *
     * @param array<mixed, mixed>                $d
     * @param array<mixed, mixed>                $f
     * @param array<string, array<mixed, mixed>> $signals
     * @param list<array<mixed, mixed>>          $missing
     * @param mixed                              $reason  the activity reason
     *
     * @return list<string>
     */
    private static function ages(array $d, array $f, array $signals, array $missing, $reason): array
    {
        $out = [];
        $age = self::map($f, 'age');
        $now = self::text($d, 'generated_at');
        foreach (self::AGES as $k) {
            $reading = $age[$k] ?? null;
            if (\is_array($reading) && $reading['years'] !== self::tenths($now, self::text($reading, 'at'))) {
                $out[] = 'I28: age.'.$k.'.years is the tenths of a year from its date to generated_at';
            }
        }
        $quotes = [
            'S2' => ['release', true, ['last_version' => 'version', 'last_release' => 'at', 'years' => 'years', 'dated_by' => 'dated_by']],
            'S4' => ['push', true, ['last_push' => 'at', 'years' => 'years']],
            'S8' => ['branch_release', false, ['branch_last_version' => 'version', 'branch_last_release' => 'at', 'years' => 'years', 'dated_by' => 'dated_by']],
        ];
        foreach ($quotes as $id => [$k, $only, $keys]) {
            $reading = \is_array($age[$k] ?? null) ? $age[$k] : null;
            if (isset($signals[$id])) {
                $data = self::map($signals[$id], 'data');
                $same = $reading !== null && ($reading['level'] ?? null) === ($signals[$id]['level'] ?? null);
                foreach ($keys as $quote => $field) {
                    $same = $same && ($data[$quote] ?? null) === ($reading[$field] ?? null);
                }
                if (!$same) {
                    $out[] = 'I28: '.$id.' quotes age.'.$k;
                }
            } elseif ($only && ($f['verdict'] ?? null) !== 'finished' && ($reading['level'] ?? null) !== null) {
                $out[] = 'I28: age.'.$k.' has a level only beside '.$id;
            }
        }
        foreach (self::rows($f, 'flags') as $flag) {
            $headline = self::map($flag, 'headline');
            if (($headline['unit'] ?? null) === 'years' && \is_string($headline['source'] ?? null)) {
                $reading = self::map($age, ['release' => 'release', 'push' => 'push', 'branch' => 'branch_release'][$headline['source']] ?? '');
                if (($reading['years'] ?? null) !== ($headline['value'] ?? null)) {
                    $out[] = 'I28: a years headline is its age reading';
                }
            }
        }
        $step = \is_array($f['next_step'] ?? null) ? $f['next_step'] : null;
        foreach ($step === null ? [] : array_merge([$step], self::rows($step, 'also')) as $move) {
            if (($move['quiet_years'] ?? null) !== null && ($move['quiet_years'] ?? null) !== (self::map($age, 'release')['years'] ?? null)) {
                $out[] = 'I28: quiet_years is age.release.years';
            }
            $latest = \is_array($move['latest'] ?? null) ? $move['latest'] : null;
            if (($move['source'] ?? null) === 'pinned' && ($move['crosses_major'] ?? null) === false && $latest !== null) {
                $branch = self::map($age, 'branch_release');
                if (!\in_array($age['branch_release_unmeasured'] ?? null, [null, 'undated_releases'], true)
                    || ($branch !== [] && ($branch['version'] ?? null) === ($latest['version'] ?? null) && ($branch['at'] ?? null) !== ($latest['released'] ?? null))) {
                    $out[] = "I28: a pinned move on its own line reads age.branch_release";
                }
            }
        }
        foreach ($missing as $c) {
            if (($c['check'] ?? null) === 'repository_activity' && \in_array('S4', \is_array($c['blocks'] ?? null) ? $c['blocks'] : [], true)
                && (($age['push'] ?? null) !== null || ($age['push_unmeasured'] ?? null) !== $reason)) {
                $out[] = 'I28: a blocked S4 leaves age.push to the activity reason';
            }
        }

        return $out;
    }

    /**
     * C73 A10.
     *
     * @param array<mixed, mixed>       $d
     * @param list<array<mixed, mixed>> $findings
     *
     * @return list<string>
     */
    private static function activityRoot(array $d, array $findings): array
    {
        $hosts = [];
        foreach ($findings as $f) {
            $a = self::map($f, 'activity');
            if (($a['reason'] ?? null) === 'host_not_supported') {
                $host = self::text($a, 'host');
                $hosts[$host] = ($hosts[$host] ?? 0) + 1;
            }
        }
        $want = [];
        foreach ($hosts as $host => $packages) {
            $want[] = ['host' => (string) $host, 'packages' => $packages];
        }
        usort($want, static fn (array $x, array $y): int => [$y['packages'], $x['host']] <=> [$x['packages'], $y['host']]);
        $notes = array_values(array_filter(self::rows($d, 'note_details'), static fn (array $n): bool => ($n['code'] ?? null) === 'repository_activity_host_not_supported'));
        $got = [];
        foreach ($notes === [] ? [] : self::rows(self::map($notes[0], 'data'), 'hosts') as $row) {
            $got[] = ['host' => $row['host'] ?? null, 'packages' => $row['packages'] ?? null];
        }
        if (\count($notes) !== ($want === [] ? 0 : 1) || $got !== $want) {
            return ['C73 A10: one host_not_supported note listing each host by its packages'];
        }

        return [];
    }

    /**
     * C75 R4, R5 and R8.
     *
     * @param array<mixed, mixed>       $d
     * @param list<array<mixed, mixed>> $findings
     *
     * @return list<string>
     */
    private static function lookupRoot(array $d, array $findings): array
    {
        $out = [];
        $lookup = self::map(self::map($d, 'run'), 'advisory_lookup');
        $notAsked = 0;
        foreach ($findings as $f) {
            $asked = array_filter(self::rows(self::map($f, 'security'), 'feeds'), static fn (array $feed): bool => \in_array($feed['answer'] ?? null, ['answered', 'failed'], true));
            $notAsked += $asked === [] ? 1 : 0;
        }
        if (!\is_int($lookup['packages_asked'] ?? null) || !\is_int($lookup['packages_not_asked'] ?? null)
            || $lookup['packages_asked'] + $lookup['packages_not_asked'] !== ($d['packages_checked'] ?? null) || $lookup['packages_not_asked'] !== $notAsked) {
            $out[] = 'C75 R4: packages_asked and packages_not_asked add up to packages_checked';
        }
        foreach (self::rows($lookup, 'repositories') as $r) {
            if (($r['outcome'] ?? null) !== 'answered') {
                continue;
            }
            $with = 0;
            foreach ($findings as $f) {
                foreach (self::rows(self::map($f, 'security'), 'feeds') as $feed) {
                    $with += ($feed['composer_repository'] ?? null) === ($r['composer_repository'] ?? null) && ($feed['answer'] ?? null) === 'answered' && ($feed['records'] ?? 0) > 0 ? 1 : 0;
                }
            }
            if (($r['packages_with_records'] ?? null) !== $with) {
                $out[] = 'C75 R5: packages_with_records counts the findings the repository answered with records';
            }
        }
        $transport = array_filter(self::rows($lookup, 'repositories'), static fn (array $r): bool => ($r['outcome'] ?? null) === 'failed' && ($r['reason'] ?? null) === 'transport');
        if ($transport !== [] && ($d['network_failures'] ?? null) !== true) {
            $out[] = 'C75 R8: a repository that failed in transport sets network_failures';
        }

        return $out;
    }

    /**
     * W12 at the root: rot_depth from the graded findings.
     *
     * @param array<mixed, mixed>       $d
     * @param list<array<mixed, mixed>> $findings
     *
     * @return list<string>
     */
    private static function rotDepth(array $d, array $findings): array
    {
        $graded = array_values(array_filter($findings, static fn (array $f): bool => \in_array($f['verdict'] ?? null, self::GRADES, true)));
        $depths = [];
        $byReach = ['direct' => 0, 'transitive' => 0, 'unreached' => 0];
        $unmeasured = ['unreached' => 0, 'no_composer_json' => 0];
        $notInComposerJson = 0;
        $devNotWalked = 0;
        $development = 0;
        $includeDev = self::map($d, 'run')['include_dev'] ?? null;
        foreach ($graded as $f) {
            if (\is_int($f['depth'] ?? null)) {
                $depths[] = $f['depth'];
            }
            $reach = self::text($f, 'reach');
            $byReach[$reach] = ($byReach[$reach] ?? 0) + 1;
            $why = $f['depth_unmeasured'] ?? null;
            if (\is_string($why)) {
                $unmeasured[$why] = ($unmeasured[$why] ?? 0) + 1;
            }
            $namedIn = $f['named_in'] ?? null;
            $notInComposerJson += $namedIn === [] && $why !== 'no_composer_json' ? 1 : 0;
            $devNotWalked += $includeDev !== true && $namedIn === ['require-dev'] && ($f['depth'] ?? null) !== 0 ? 1 : 0;
            $development += ($f['dev'] ?? null) === true ? 1 : 0;
        }
        $max = $depths === [] ? null : max($depths);
        $byDepth = [];
        for ($i = 0; $max !== null && $i <= $max; ++$i) {
            $byDepth[] = ['depth' => $i, 'flagged' => \count(array_keys($depths, $i, true))];
        }
        $deepest = [];
        foreach ($graded as $f) {
            if ($max !== null && $max > 0 && ($f['depth'] ?? null) === $max) {
                $deepest[] = ['package' => self::text($f, 'package'), 'chain' => $f['chain'] ?? null];
            }
        }
        usort($deepest, static fn (array $x, array $y): int => strcmp($x['package'], $y['package']));
        $rd = self::map($d, 'rot_depth');
        $want = [
            'flagged' => \count($graded), 'measured' => \count($depths), 'unmeasured' => $unmeasured, 'by_reach' => $byReach, 'not_in_composer_json' => $notInComposerJson,
            'require_dev_not_walked' => $devNotWalked, 'production' => \count($graded) - $development, 'development' => $development, 'by_depth' => $byDepth, 'max_depth' => $max, 'deepest' => $deepest,
        ];
        $out = [];
        foreach ($want as $key => $value) {
            if (($rd[$key] ?? null) !== $value) {
                $out[] = 'W12: rot_depth.'.$key.' counts the graded findings';
            }
        }
        if (($d['packages_flagged'] ?? null) !== \count($graded)) {
            $out[] = 'W12: rot_depth.flagged is packages_flagged';
        }
        if ($byReach['direct'] + $devNotWalked + $notInComposerJson + $unmeasured['no_composer_json'] !== \count($graded)) {
            $out[] = 'W12: rot_depth partitions the graded findings';
        }
        $noComposerJson = array_unique(array_map(static fn (array $f): bool => ($f['depth_unmeasured'] ?? null) === 'no_composer_json', $findings));
        if (\count($noComposerJson) > 1) {
            $out[] = 'W12: no_composer_json holds on every finding or on none';
        }

        return $out;
    }

    /**
     * C77 at the root: libyears from the findings.
     *
     * @param array<mixed, mixed>       $d
     * @param list<array<mixed, mixed>> $findings
     *
     * @return list<string>
     */
    private static function libyears(array $d, array $findings): array
    {
        $l = self::map($d, 'libyears');
        $unmeasured = self::map($l, 'unmeasured');
        $measured = 0;
        $atLeast = 0;
        $byReason = array_fill_keys(array_keys($unmeasured), 0);
        $byPackage = [];
        foreach ($findings as $f) {
            $measured += ($f['libyears'] ?? null) !== null ? 1 : 0;
            $atLeast += ($f['libyears_at_least'] ?? null) === true ? 1 : 0;
            $why = $f['libyears_unmeasured'] ?? null;
            if (\is_string($why)) {
                $byReason[$why] = ($byReason[$why] ?? 0) + 1;
            }
            $byPackage[self::text($f, 'package')] = $f;
        }
        $out = [];
        if (($l['measured'] ?? null) !== $measured || $unmeasured !== $byReason || ($l['at_least_count'] ?? null) !== $atLeast
            || !\is_int($l['packages'] ?? null) || $measured + (int) array_sum($byReason) !== $l['packages']) {
            $out[] = 'C77: libyears.measured, unmeasured and at_least_count count the findings';
        }
        $furthest = \is_array($l['furthest_behind'] ?? null) ? $l['furthest_behind'] : null;
        if ($furthest !== null && ($furthest['at_least'] ?? null) !== (self::map($byPackage, self::text($furthest, 'package'))['libyears_at_least'] ?? null)) {
            $out[] = "C77: libyears.furthest_behind.at_least is its finding's";
        }

        return $out;
    }

    /**
     * I25: each graded transitive finding is named by the S7 of each direct dependent, or is
     * unattributed past the fan-in cap, and `exposure[]` repeats the S7 counts.
     *
     * @param array<mixed, mixed>       $d
     * @param list<array<mixed, mixed>> $findings
     *
     * @return list<string>
     */
    private static function exposure(array $d, array $findings): array
    {
        $out = [];
        $cap = self::map($d, 'exposure_rule')['max_fan_in'] ?? null;
        $s7 = [];
        foreach ($findings as $f) {
            foreach (self::rows($f, 'signals') as $signal) {
                if (($signal['id'] ?? null) === 'S7') {
                    $s7[self::text($f, 'package')] = self::map($signal, 'data');
                }
            }
        }
        $unattributed = [];
        foreach (self::rows($d, 'unattributed') as $row) {
            $unattributed[self::text($row, 'package')] = $row['fan_in'] ?? null;
        }
        $names = array_map(static fn (array $f): string => self::text($f, 'package'), $findings);
        foreach ($findings as $f) {
            $dependents = \is_array($f['direct_dependents'] ?? null) ? $f['direct_dependents'] : [];
            if (!\in_array($f['verdict'] ?? null, self::GRADES, true) || ($f['direct'] ?? null) !== false || $dependents === []) {
                continue;
            }
            $package = self::text($f, 'package');
            if (\is_int($cap) && \count($dependents) > $cap) {
                if (($unattributed[$package] ?? null) !== \count($dependents)) {
                    $out[] = 'I25: '.$package.' past the fan-in cap is unattributed with its fan-in';
                }
                continue;
            }
            foreach ($dependents as $parent) {
                $parent = \is_string($parent) ? $parent : '';
                if (!\in_array($package, self::packagesOf(self::map($s7, $parent)), true)) {
                    $out[] = 'I25: the S7 of '.$parent.' names '.$package;
                }
            }
        }
        $pairs = [];
        foreach ($s7 as $parent => $data) {
            $rows = self::packagesOf($data);
            if (($data['flagged'] ?? null) !== \count($rows) || array_filter($rows, static fn ($row): bool => !\in_array($row, $names, true)) !== []) {
                $out[] = 'I25: the S7 of '.$parent.' counts its rows, each a finding';
            }
            $pairs[] = [(string) $parent, $data['flagged'] ?? null];
        }
        $exposure = array_map(static fn (array $row): array => [$row['package'] ?? null, $row['flagged'] ?? null], self::rows($d, 'exposure'));
        sort($pairs);
        sort($exposure);
        if ($pairs !== $exposure) {
            $out[] = 'I25: exposure[] repeats each S7 parent and its count';
        }

        return $out;
    }

    /**
     * The check's reason, or for an entry that accept-unchecked accepted, the reason of that acceptance.
     *
     * @param array<mixed, mixed> $f
     * @param array<mixed, mixed> $c
     *
     * @return mixed
     */
    private static function entryReason(array $f, array $c)
    {
        if (($c['reason'] ?? null) !== 'accepted_by_config') {
            return $c['reason'] ?? null;
        }
        foreach (self::rows($f, 'unchecked_accepted') as $u) {
            if (($u['check'] ?? null) === ($c['check'] ?? null)) {
                return $u['reason'] ?? null;
            }
        }

        return null;
    }

    /**
     * @param array<mixed, mixed> $s7 an S7's data
     *
     * @return list<mixed> the package of each row
     */
    private static function packagesOf(array $s7): array
    {
        return array_map(static fn (array $row) => $row['package'] ?? null, self::rows($s7, 'packages'));
    }

    /** @return float|int the years from $at to $now in tenths, rounded half up, whole as an integer */
    private static function tenths(string $now, string $at)
    {
        $seconds = max(0, (new \DateTimeImmutable($now))->getTimestamp() - (new \DateTimeImmutable($at))->getTimestamp());
        $tenths = intdiv(10 * $seconds + intdiv(self::SECONDS_PER_YEAR, 2), self::SECONDS_PER_YEAR);

        return $tenths % 10 === 0 ? intdiv($tenths, 10) : $tenths / 10;
    }

    /**
     * @param array<mixed, mixed> $data
     *
     * @return list<mixed>
     */
    private static function pick(array $data, string ...$keys): array
    {
        return array_values(array_map(static fn (string $key) => $data[$key] ?? null, $keys));
    }

    /**
     * @param array<mixed, mixed> $data
     *
     * @return array<mixed, mixed> the object at $key, empty when there is none
     */
    private static function map(array $data, string $key): array
    {
        return \is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * @param array<mixed, mixed> $data
     *
     * @return list<array<mixed, mixed>>
     */
    private static function rows(array $data, string $key): array
    {
        $rows = [];
        foreach (self::map($data, $key) as $row) {
            if (\is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @param array<mixed, mixed> $data */
    private static function text(array $data, string $key): string
    {
        return \is_string($data[$key] ?? null) ? $data[$key] : '';
    }
}
