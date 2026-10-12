<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineEntry;
use Lockrot\Baseline\RecordedAdvisory;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Verdict\ScoreModel;

/**
 * Builds the baseline-2 image of a 0.13 baseline entry: the recorded lead is the 0.13 verdict, and
 * every maintenance flag at or after it in flag order is known since the entry's date. Coverage is
 * 0.13's rule written over flags, so the image keeps the standing that 0.13 gave.
 */
final class BaselineEntries
{
    /**
     * @param list<array{string, string, string}> $advisories id, severity bucket, fix kind: known since $firstSeen
     *
     * @throws \InvalidArgumentException for a verdict that is not a maintenance flag
     */
    public static function of(string $package, string $version, string $verdict, string $firstSeen, array $advisories = []): BaselineEntry
    {
        $order = array_keys(ScoreModel::POINTS);
        $at = array_search($verdict, $order, true);
        if ($at === false) {
            throw new \InvalidArgumentException('a 0.13 baseline entry holds a maintenance flag, not '.$verdict);
        }
        $flags = array_fill_keys(\array_slice($order, $at), $firstSeen);

        return new BaselineEntry($package, $version, $firstSeen, $flags, array_map(static fn (array $a): RecordedAdvisory => new RecordedAdvisory($a[0], $firstSeen, $a[1], $a[2]), $advisories));
    }

    /**
     * A baseline written for the PHP that runs the test, so a run without settings compares fixes.
     *
     * @param list<BaselineEntry> $entries
     */
    public static function baseline(array $entries, string $generatedAt = '2026-09-14T00:00:00+00:00'): Baseline
    {
        return Baseline::of($entries, $generatedAt, PhpReleaseDates::minorOf(\PHP_VERSION), 'runtime');
    }
}
