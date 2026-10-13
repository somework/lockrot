<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support\Contract;

use Lockrot\Tests\Support\JsonPath;

/**
 * The coverage floors of a baselined scenario (items C78, R7): states that the recorded documents
 * must carry at least once. A floor is a presence, never a count, so a re-recording that shifts
 * counts keeps it while a lost state fails.
 */
final class BaselinedFloors
{
    /**
     * The floors each scenario must meet. `limited_by: equal` is the estimate's for wallabag-2.6, and
     * the recorded data does not reach it, so no floor asks for it.
     */
    /** The floors that only an advisory lookup can meet. */
    public const ADVISORY_FLOORS = ['worsened by a new advisory on a recorded lead', 'advisory known'];

    public const FLOORS = [
        'wallabag-main' => ['status known', 'status new', 'status worsened', 'worsened by a new flag', 'flag covered', 'stale entries', 'limited_by cap', 'limited_by score', 'a failing finding', 'a finding exempt by the baseline', 'a known dev finding', 'exits 1 then 0'],
        'wallabag-2.6' => ['status known', 'status new', 'status worsened', 'worsened by a new advisory on a recorded lead', 'advisory known', 'limited_by cap', 'limited_by score', 'a failing finding', 'a finding exempt by the baseline', 'exits 1 then 0', 'note baseline_target_differs'],
    ];

    /**
     * The floors of $scenario that the files miss.
     *
     * @param array<string, string> $files the scenario's files by their path below its directory
     *
     * @return list<string>
     */
    public static function missed(string $scenario, array $files): array
    {
        $met = self::met($files);

        return array_values(array_diff(self::FLOORS[$scenario], $met));
    }

    /**
     * @param array<string, string> $files
     *
     * @return list<string>
     */
    private static function met(array $files): array
    {
        $report = self::decode($files['check/report.json'] ?? '{}');
        $met = [];
        foreach (JsonPath::has($report, ['findings']) ? JsonPath::arrayAt($report, ['findings']) : [] as $finding) {
            if (!\is_array($finding)) {
                continue;
            }
            $baseline = \is_array($finding['baseline'] ?? null) ? $finding['baseline'] : [];
            $met[] = 'status '.(\is_string($baseline['status'] ?? null) ? $baseline['status'] : 'none');
            $lead = \is_array($baseline['recorded'] ?? null) && \is_string($baseline['recorded']['lead'] ?? null);
            foreach (\is_array($baseline['worsened_by'] ?? null) ? $baseline['worsened_by'] : [] as $fact) {
                if (\is_array($fact) && ($fact['why'] ?? null) === 'new') {
                    $met[] = ($fact['kind'] ?? null) === 'flag' ? 'worsened by a new flag' : ($lead ? 'worsened by a new advisory on a recorded lead' : 'worsened by a new advisory');
                }
            }
            foreach (\is_array($finding['flags'] ?? null) ? $finding['flags'] : [] as $flag) {
                $state = \is_array($flag) && \is_array($flag['baseline'] ?? null) ? ($flag['baseline']['state'] ?? null) : null;
                $met[] = 'flag '.(\is_string($state) ? $state : '');
            }
            foreach (\is_array($finding['signals'] ?? null) ? $finding['signals'] : [] as $signal) {
                foreach (\is_array($signal) && ($signal['id'] ?? null) === 'S9' ? JsonPath::arrayAt($signal, ['data', 'advisories']) : [] as $row) {
                    $state = \is_array($row) && \is_array($row['baseline'] ?? null) ? ($row['baseline']['state'] ?? null) : null;
                    $met[] = 'advisory '.(\is_string($state) ? $state : '');
                }
            }
            $gate = \is_array($finding['gate'] ?? null) ? $finding['gate'] : [];
            if (\is_array($gate['basis'] ?? null)) {
                $met[] = 'limited_by '.(\is_string($gate['basis']['limited_by'] ?? null) ? $gate['basis']['limited_by'] : '');
            }
            if (($gate['fails'] ?? false) === true) {
                $met[] = 'a failing finding';
            }
            if (($gate['exempt_by'] ?? null) === 'baseline') {
                $met[] = 'a finding exempt by the baseline';
            }
            if (($finding['dev'] ?? false) === true && ($baseline['status'] ?? null) === 'known') {
                $met[] = 'a known dev finding';
            }
        }
        if (\is_array($report['baseline'] ?? null) && ($report['baseline']['stale'] ?? []) !== []) {
            $met[] = 'stale entries';
        }
        $runs = JsonPath::has(self::decode($files['provenance.json'] ?? '{}'), ['runs']) ? JsonPath::arrayAt(self::decode($files['provenance.json']), ['runs']) : [];
        $exits = [];
        foreach ($runs as $run) {
            if (\is_array($run) && \in_array($run['step'] ?? null, ['check', 'recheck'], true)) {
                $exits[$run['step']] ??= $run['exit'];
            }
        }
        if ($exits === ['check' => 1, 'recheck' => 0]) {
            $met[] = 'exits 1 then 0';
        }
        foreach ($files as $path => $contents) {
            if (strpos($path, 'check/target-') === 0 && strpos($contents, '"code": "baseline_target_differs"') !== false) {
                $met[] = 'note baseline_target_differs';
            }
        }

        return array_values(array_unique($met));
    }

    /** @return array<mixed, mixed> */
    private static function decode(string $json): array
    {
        $data = json_decode($json, true);

        return \is_array($data) ? $data : [];
    }
}
