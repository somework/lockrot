<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Baseline\BaselineComparison;
use Lockrot\Data\Forge\ActivityLookup;
use Lockrot\Signal\Signal;

/**
 * The closed and open sets docs/compatibility.md names, read off the code in one place for the
 * tests that hold the schemas and the pages to them. Verdict::all() and Priority013::all() are lists
 * already. The signal levels, the baseline standings and the signal ids are constants, not lists.
 */
final class ClosedSets
{
    /** @return list<string> lowest first */
    public static function levels(): array
    {
        return [Signal::LEVEL_INFO, Signal::LEVEL_WARN, Signal::LEVEL_HIGH];
    }

    /** @return list<string> the order report-2's schema lists them in */
    public static function activityStatuses(): array
    {
        return [ActivityLookup::READ, ActivityLookup::NOT_FOUND, ActivityLookup::NOT_READ];
    }

    /** @return list<string> */
    public static function standings(): array
    {
        return [BaselineComparison::KNOWN, BaselineComparison::NEW_FINDING, BaselineComparison::WORSENED];
    }

    /**
     * Every `S<n>` constant on Signal, in declaration order. A constant that does not hold its own
     * name (`S11 = 'S12'`) is refused rather than returned, since the name is what the code reads.
     *
     * @return list<string>
     */
    public static function signalIds(): array
    {
        $ids = [];
        foreach ((new \ReflectionClass(Signal::class))->getConstants() as $name => $value) {
            if (preg_match('/^S\d+$/', $name) !== 1) {
                continue;
            }
            if ($value !== $name) {
                throw new \RuntimeException('Signal::'.$name.' holds '.var_export($value, true).', not its own id');
            }
            $ids[] = $name;
        }

        return $ids;
    }
}
