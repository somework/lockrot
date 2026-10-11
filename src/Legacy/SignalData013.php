<?php

declare(strict_types=1);

namespace Lockrot\Legacy;

use Lockrot\Signal\Data\ExposureData;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\FlagSet;

/**
 * A signal's data as report-1 wrote it, S9 apart: without the keys report-2 adds, and with S7's
 * packages named by their report-1 word. S9's report-1 rows are {@see AdvisoryFacts013}. The
 * `--explain` text prints this view until it reads report-2's.
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
    ];

    /** report-1's S9 level is `warn` whatever the severity. */
    public static function level(Signal $signal): string
    {
        return $signal->id() === Signal::S9 ? Signal::LEVEL_WARN : $signal->level();
    }

    /** @return array<string, mixed> */
    public static function of(Signal $signal): array
    {
        $data = $signal->data();
        if ($data instanceof ExposureData) {
            $packages = [];
            foreach ($data->packages() as $package) {
                $packages[] = ['package' => $package->package(), 'verdict' => $package->lead() ?? FlagSet::VULNERABLE, 'chain' => $package->chain()];
            }

            return ['flagged' => \count($packages), 'packages' => $packages];
        }

        return $data === null ? [] : array_diff_key($data->jsonSerialize(), array_flip(self::ADDED[$signal->id()] ?? []));
    }
}
