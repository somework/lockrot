<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Lockrot\Data\Forge\ActivityLookup;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Lock\PackageOrigin;
use Lockrot\Signal\Data\Check;
use Lockrot\Signal\Data\NotCheckedData;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;

/**
 * S10: a check that this package's verdict rests on did not run, and what it could not decide
 * (docs/verdicts.md#what-was-not-checked). The signal never decides a verdict.
 * {@see \Lockrot\Analyzer\Analyzer}, not this rule, drops the maintenance entries
 * ({@see withoutMaintenanceGaps()}).
 *
 * @internal
 */
final class NotCheckedRule implements SignalRule
{
    /** The `reason` values: docs/verdicts.md#what-was-not-checked. */
    public const NO_TOKEN = 'no_token';
    public const RATE_BUDGET = 'anonymous_budget';
    public const BUDGET = 'install_time_budget';
    public const RATE_LIMIT = 'rate_limit';
    public const FETCH_FAILED = 'fetch_failed';
    public const OFFLINE = 'offline';
    public const HOST_NOT_SUPPORTED = ActivityLookup::HOST_NOT_SUPPORTED;
    public const NOT_FROM_COMPOSER_REPOSITORY = ActivityLookup::NOT_FROM_COMPOSER_REPOSITORY;

    /** Short enough for the evidence line of a flagged row. The reason key carries the rest. */
    private const ACTIVITY_WORDS = [
        self::NO_TOKEN => 'no token for the repository host',
        self::RATE_BUDGET => 'the anonymous request budget was spent',
        self::BUDGET => 'the install-time budget ran out',
        self::RATE_LIMIT => 'the host answered "too many requests"',
        self::FETCH_FAILED => 'the request failed',
        self::OFFLINE => 'the run is offline',
    ];

    /** The words of the S10 entry for release data a counted advisory needed. */
    public const RELEASES_WORDS = 'the releases were not read, so S9 could not judge a fix';

    /**
     * The origins whose releases and activity lockrot does not read, though the code is a third
     * party's: a path origin is the project's own code, and an artifact or package origin keeps
     * its skips (docs/verdicts.md#what-was-not-checked).
     */
    private const ORIGIN_GAPS = [PackageOrigin::VCS, PackageOrigin::UNKNOWN];

    /** An unknown origin can be a Composer repository that leaves no notification-url, so it gets words of its own. */
    private const ORIGIN_CAUSE = [PackageOrigin::UNKNOWN => 'lockrot cannot tell which repository serves it'];

    /** The S10 checks about the package's upkeep, which a raised S1 makes moot. */
    private const MAINTENANCE_CHECKS = ['repository_activity', 'release_dates', 'release_metadata'];

    public function evaluate(PackageFacts $facts): ?Signal
    {
        $unchecked = [];
        $reason = $facts->activityNotChecked();
        $origin = $facts->package()->origin()->kind();
        if ($reason === self::NOT_FROM_COMPOSER_REPOSITORY && \in_array($origin, self::ORIGIN_GAPS, true)) {
            $unchecked[] = new Check('release_metadata', $reason, [Signal::S2, Signal::S8]);
            $unchecked[] = new Check('repository_activity', $reason, [Signal::S3, Signal::S4]);
        } elseif ($reason === self::HOST_NOT_SUPPORTED || isset(self::ACTIVITY_WORDS[(string) $reason])) {
            $unchecked[] = new Check('repository_activity', (string) $reason, [Signal::S3, Signal::S4]);
        }

        $metadata = $facts->metadata();
        // PackageMetadata::fromPackages() leaves the newest tag undated where a shared commit
        // dates it: S2 cannot measure that.
        if ($metadata !== null && $metadata->hasStableRelease() && $metadata->lastStableReleaseAt() === null) {
            // A branch snapshot is on no release branch, so S8 misses nothing there. S2 reads the
            // package's newest release whatever the lock installs, and with S4 it can make
            // the snapshot `silent`, not `pinned`.
            $ageBlocks = ReleaseBranch::of($facts->package()->version()) === null ? [Signal::S2] : [Signal::S2, Signal::S8];
            $unchecked[] = new Check('release_dates', 'undated_releases', $ageBlocks);
        }

        $host = $reason === self::HOST_NOT_SUPPORTED ? $facts->activityLookup()->where()->host() : null;

        return self::signalOf($unchecked, array_map(static fn (Check $entry): string => self::summaryOf($entry, $host, $origin), $unchecked));
    }

    /**
     * The S10 entry for release data that a repository listing the package did not serve, beside a
     * counted advisory: every fix is unknown then. report-1's S10 has no `releases`
     * check, so {@see evaluate()} leaves it out and report-2's finding adds it.
     */
    public static function releasesUnchecked(PackageFacts $facts): ?Check
    {
        if ($facts->advisories() === [] || $facts->metadataStatus() !== PackageFacts::METADATA_UNAVAILABLE) {
            return null;
        }

        return new Check('releases', 'releases_unknown', [Signal::S9]);
    }

    /**
     * The signals with S10's maintenance entries dropped, and S10 with them when nothing is left.
     * An S10 entry for any other check stays: a raised S1 decides nothing about it
     * (docs/verdicts.md#what-was-not-checked).
     *
     * @param list<Signal> $signals
     *
     * @return list<Signal>
     */
    public static function withoutMaintenanceGaps(array $signals): array
    {
        $kept = [];
        foreach ($signals as $signal) {
            $data = $signal->data();
            if (!$data instanceof NotCheckedData) {
                $kept[] = $signal;
                continue;
            }
            // signalOf() joins one summary per entry, in entry order.
            $summaries = explode('; ', $signal->summary());
            $entries = [];
            $entrySummaries = [];
            foreach ($data->checks() as $index => $entry) {
                if (!\in_array($entry->check(), self::MAINTENANCE_CHECKS, true)) {
                    $entries[] = $entry;
                    $entrySummaries[] = $summaries[$index] ?? self::summaryOf($entry);
                }
            }
            $rebuilt = self::signalOf($entries, $entrySummaries);
            if ($rebuilt !== null) {
                $kept[] = $rebuilt;
            }
        }

        return $kept;
    }

    /**
     * @param list<Check>  $unchecked
     * @param list<string> $summaries one per entry
     */
    private static function signalOf(array $unchecked, array $summaries): ?Signal
    {
        if ($unchecked === []) {
            return null;
        }

        return new Signal(Signal::LEVEL_INFO, implode('; ', $summaries), new NotCheckedData($unchecked));
    }

    /**
     * @param ?string $host   the host that lockrot cannot ask
     * @param ?string $origin the package's `origin.kind`
     */
    private static function summaryOf(Check $entry, ?string $host = null, ?string $origin = null): string
    {
        $cause = self::ACTIVITY_WORDS[$entry->reason()] ?? $entry->reason();
        if ($entry->reason() === self::HOST_NOT_SUPPORTED) {
            $cause = 'lockrot cannot ask '.($host ?? 'its host');
        } elseif ($entry->reason() === self::NOT_FROM_COMPOSER_REPOSITORY) {
            $cause = self::ORIGIN_CAUSE[(string) $origin] ?? 'not installed from a Composer repository';
        }
        if ($entry->check() === 'release_metadata') {
            return 'release metadata not checked ('.$cause.'), so S2 and S8 could not be read';
        }
        if ($entry->check() === 'repository_activity') {
            return 'repository activity not checked ('.$cause.'), so S3 and S4 could not be read';
        }
        if ($entry->check() === 'release_dates') {
            return 'the age of the package was not read (its newest releases are dated by a commit their tags share), so '.implode(' and ', $entry->blocks()).' could not measure it';
        }

        throw new \LogicException('S10 has no words for the check '.$entry->check().'.');
    }
}
