<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

/**
 * The invariant that an S10 entry names in `blocks` only a signal that did not fire on the same
 * finding. An entry of a check that S9 reads in part (`advisories`, `releases`) is exempt: S9 fires
 * from the advisories that it read, and the entry says that its reading is incomplete.
 */
final class FiredSignalsUnblocked
{
    private const S9_CHECKS = ['advisories', 'releases'];

    /**
     * Each finding of the document whose S10 blocks a signal that fired, as `<package>: <check> blocks <id>`.
     *
     * @param array<mixed, mixed> $document a decoded report-2, explain-2 or baseline document
     *
     * @return list<string>
     */
    public static function violations(array $document): array
    {
        $out = [];
        foreach (self::findings($document) as $finding) {
            $signals = array_filter($finding['signals'], 'is_array');
            $fired = self::strings(array_column($signals, 'id'));
            foreach ($signals as $signal) {
                if (($signal['id'] ?? null) !== 'S10') {
                    continue;
                }
                foreach (array_filter(JsonPath::arrayAt($signal, ['data', 'unchecked']), 'is_array') as $entry) {
                    $check = \is_string($entry['check'] ?? null) ? $entry['check'] : '?';
                    if (\in_array($check, self::S9_CHECKS, true)) {
                        continue;
                    }
                    foreach (array_intersect(self::strings(\is_array($entry['blocks'] ?? null) ? $entry['blocks'] : []), $fired) as $id) {
                        $out[] = \sprintf('%s: %s blocks %s, which fired', $finding['package'], $check, $id);
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_filter($values, 'is_string'));
    }

    /**
     * Every object with a string `package` and a `signals` list, at any depth.
     *
     * @param array<mixed, mixed> $node
     *
     * @return list<array{package: string, signals: array<mixed>}>
     */
    private static function findings(array $node): array
    {
        if (\is_string($node['package'] ?? null) && \is_array($node['signals'] ?? null)) {
            return [['package' => $node['package'], 'signals' => $node['signals']]];
        }
        $out = [];
        foreach ($node as $child) {
            if (\is_array($child)) {
                $out = array_merge($out, self::findings($child));
            }
        }

        return $out;
    }
}
