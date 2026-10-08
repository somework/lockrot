<?php

declare(strict_types=1);

namespace Lockrot\Legacy;

use Lockrot\Signal\Signal;
use Lockrot\Verdict\FlagSet;

/**
 * A signal's data as report-1 wrote it: without the keys report-2 adds, S7's packages named by
 * their report-1 word and S9's rows from {@see AdvisoryFacts013}. The `--explain` text prints this view
 * until it reads report-2's.
 *
 * @internal
 */
final class SignalData013
{
    /** The data keys report-2 adds to report-1's signals. */
    private const ADDED = [
        Signal::S1 => ['marked_by', 'replacement_url'],
        Signal::S3 => ['forge'],
        Signal::S4 => ['forge', 'activity'],
        Signal::S6 => ['tag_relation'],
        Signal::S8 => ['reachable_php', 'reachable_admits', 'newest_years', 'reachable_years'],
        Signal::S9 => ['complete'],
    ];

    /** report-1's S9 level is `warn` whatever the severity. */
    public static function level(Signal $signal): string
    {
        return $signal->id() === Signal::S9 ? Signal::LEVEL_WARN : $signal->level();
    }

    /** @return array<string, mixed> */
    public static function of(Signal $signal, AdvisoryFacts013 $advisories): array
    {
        $data = $signal->data();
        $rows = $data['advisories'] ?? null;
        if ($signal->id() === Signal::S9 && \is_array($rows) && \is_array($rows[0] ?? null) && \array_key_exists('fix', $rows[0])) {
            $data = ['advisories' => $advisories->rows()] + $data;
        }
        if ($signal->id() === Signal::S7) {
            $packages = [];
            foreach (\is_array($data['packages'] ?? null) ? $data['packages'] : [] as $package) {
                if (\is_array($package)) {
                    $packages[] = ['package' => $package['package'] ?? null, 'verdict' => $package['lead'] ?? FlagSet::VULNERABLE, 'chain' => $package['chain'] ?? []];
                }
            }

            return ['flagged' => $data['flagged'] ?? \count($packages), 'packages' => $packages];
        }
        return array_diff_key($data, array_flip(self::ADDED[$signal->id()] ?? []));
    }
}
