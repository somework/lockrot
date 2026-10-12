<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Lockrot\Data\Advisory\AdvisoryCoverage;
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

    /**
     * The advisories reasons that are gaps: the lookup did not run, or not in full. A package outside
     * the lookup's scope and a version Composer cannot parse are skips, never S10.
     */
    private const ADVISORY_CAUSES = [
        self::OFFLINE => 'the run is offline',
        AdvisoryCoverage::COMPOSER_TOO_OLD => 'Composer is older than 2.4',
        self::BUDGET => 'the install-time budget ran out',
        AdvisoryCoverage::LOOKUP_FAILED => 'an advisory repository failed',
        AdvisoryCoverage::NO_FEED => 'no configured repository publishes advisories',
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

        $coverage = $facts->advisoryCoverage();
        $advisories = $coverage === null ? null : $coverage->reason();
        if ($advisories !== null && isset(self::ADVISORY_CAUSES[$advisories])) {
            $unchecked[] = new Check('advisories', $advisories, [Signal::S9]);
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
            $entries = array_values(array_filter($data->checks(), static fn (Check $entry): bool => !\in_array($entry->check(), self::MAINTENANCE_CHECKS, true)));
            $rebuilt = self::signalOf($entries, array_map(static fn (Check $entry): string => self::summaryOf($entry), $entries));
            if ($rebuilt !== null) {
                $kept[] = $rebuilt;
            }
        }

        return $kept;
    }

    /**
     * The signals with S10 narrowed to $kept, its summary worded again. S10 goes when $kept is empty.
     *
     * @param list<Signal> $signals
     * @param list<Check>  $kept    entries of the S10 of $signals
     * @param ?string      $host    the host that lockrot cannot ask
     * @param ?string      $origin  the package's `origin.kind`
     *
     * @return list<Signal>
     */
    public static function keeping(array $signals, array $kept, ?string $host, ?string $origin): array
    {
        $out = [];
        foreach ($signals as $signal) {
            if (!$signal->data() instanceof NotCheckedData) {
                $out[] = $signal;
                continue;
            }
            $rebuilt = self::signalOf($kept, array_map(static fn (Check $entry): string => self::summaryOf($entry, $host, $origin), $kept));
            if ($rebuilt !== null) {
                $out[] = $rebuilt;
            }
        }

        return $out;
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
        if ($entry->check() === 'advisories') {
            return 'security advisories not checked ('.(self::ADVISORY_CAUSES[$entry->reason()] ?? $entry->reason()).'), so S9 could not be read';
        }
        if ($entry->check() === 'releases') {
            return self::RELEASES_WORDS;
        }
        if ($entry->check() === 'release_dates') {
            return 'the age of the package was not read (its newest releases are dated by a commit their tags share), so '.implode(' and ', $entry->blocks()).' could not measure it';
        }

        throw new \LogicException('S10 has no words for the check '.$entry->check().'.');
    }
}
