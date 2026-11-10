<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Forge\ActivityLookup;
use Lockrot\Data\Repository\InstalledRelease;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\PackageOrigin;
use Lockrot\Signal\Data\Check;
use Lockrot\Signal\Data\NotCheckedData;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;
use Lockrot\Verdict\FindingDetails;
use Lockrot\Verdict\ListWords;

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
        self::BUDGET => 'the install-time budget ran out',
        AdvisoryCoverage::LOOKUP_FAILED => 'an advisory repository failed',
        AdvisoryCoverage::NO_FEED => 'no configured repository publishes advisories',
        AdvisoryCoverage::COMPOSER_JSON_INVALID => 'Composer cannot load composer.json',
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

    /** The causes of a `release_dates` entry ({@see Check::causes()}): the installed tag's lock date is a commit that other tags share. */
    public const CAUSE_INSTALLED_SHARED_COMMIT = 'installed_shared_commit';
    /** The installed tag has its own commit, but a parent dates the split's newest release (ruling R13-31). */
    public const CAUSE_INSTALLED_PARENT_SPLIT = 'installed_parent_split';
    /** The lock entry of the installed release has no `time`. */
    public const CAUSE_LOCK_UNDATED = 'lock_undated';
    /** A highest tag that the signal reads has no `time`. */
    public const CAUSE_TAG_UNDATED = 'tag_undated';
    /** A highest tag that the signal reads is on a commit that other tags share. */
    public const CAUSE_SHARED_COMMIT = 'shared_commit';

    /** The cause of an `advisories` entry for a partial lookup ({@see FindingDetails::isPartialLookup()}, {@see Check::causes()}). */
    private const CAUSE_PARTIAL = 'partial';

    /** The words of a partial lookup, for the reasons that can be partial: the offline run never is. */
    private const PARTIAL_ADVISORY_CAUSES = [
        AdvisoryCoverage::LOOKUP_FAILED => 'a Composer repository did not return them',
        self::BUDGET => 'the install-time budget ran out',
    ];

    /** The words of each cause of a `release_dates` entry, one clause per cause. */
    private const DATE_CAUSES = [
        self::CAUSE_LOCK_UNDATED => 'the lock gives the installed release no date',
        self::CAUSE_INSTALLED_SHARED_COMMIT => 'the installed release has no release date (its tag shares a commit with other tags, and no parent dates it)',
        self::CAUSE_INSTALLED_PARENT_SPLIT => 'the installed release has no release date (a parent dates the newest release of this split, so the lock time is not a release date)',
        self::CAUSE_SHARED_COMMIT => 'the age of the package was not read (a release it reads is dated by a commit its tags share)',
        self::CAUSE_TAG_UNDATED => 'the age of the package was not read (a release it reads carries no date)',
    ];

    /** The `release_dates` cause of each InstalledRelease kind with a lock date that lockrot does not trust. */
    private const INSTALLED_CAUSES = [
        InstalledRelease::SHARED_COMMIT => self::CAUSE_INSTALLED_SHARED_COMMIT,
        InstalledRelease::PARENT_SPLIT => self::CAUSE_INSTALLED_PARENT_SPLIT,
    ];

    /** The S10 checks about the package's upkeep, which a raised S1 makes moot. */
    private const MAINTENANCE_CHECKS = ['repository_activity', 'release_dates', 'release_metadata'];

    private LeftBehindRule $leftBehind;
    private ?CappedRule $capped;
    private ?OldPromiseRule $oldPromise;

    /** @param ?CappedRule $capped S11 fires without release metadata, so an unread one blocks only its `admits_newest` */
    public function __construct(LeftBehindRule $leftBehind, ?CappedRule $capped = null, ?OldPromiseRule $oldPromise = null)
    {
        $this->leftBehind = $leftBehind;
        $this->capped = $capped;
        $this->oldPromise = $oldPromise;
    }

    public function evaluate(PackageFacts $facts): ?Signal
    {
        $unchecked = [];
        $reason = $facts->activityNotChecked();
        $origin = $facts->package()->origin()->kind();
        if ($reason === self::NOT_FROM_COMPOSER_REPOSITORY && \in_array($origin, self::ORIGIN_GAPS, true)) {
            $blocks = $this->metadataBlocks($facts, $this->capped !== null && $this->capped->evaluate($facts) !== null);
            $unchecked[] = new Check('release_metadata', $reason, $blocks);
            $unchecked[] = new Check('repository_activity', $reason, [Signal::S3, Signal::S4]);
        } elseif ($reason === self::HOST_NOT_SUPPORTED || isset(self::ACTIVITY_WORDS[$reason ?? ''])) {
            $unchecked[] = new Check('repository_activity', (string) $reason, [Signal::S3, Signal::S4]);
        }

        $metadata = $facts->metadata();
        $dates = $metadata === null ? null : $this->releaseDates($facts, $metadata);
        if ($dates !== null) {
            $unchecked[] = $dates;
        }

        $coverage = $facts->advisoryCoverage();
        $advisories = $coverage === null ? null : $coverage->reason();
        if ($advisories !== null && isset(self::ADVISORY_CAUSES[$advisories])) {
            $partial = isset(self::PARTIAL_ADVISORY_CAUSES[$advisories]) && FindingDetails::isPartialLookup($facts);
            $unchecked[] = new Check('advisories', $advisories, [Signal::S9], $partial ? [self::CAUSE_PARTIAL => [Signal::S9]] : []);
        }

        $host = $reason === self::HOST_NOT_SUPPORTED ? $facts->activityLookup()->where()->host() : null;

        return self::signalOf($unchecked, array_map(static fn (Check $entry): string => self::summaryOf($entry, $host, $origin), $unchecked));
    }

    /**
     * The signals that unread release metadata blocks, in signal order. S5 joins S2 and S8 when it
     * has no date to judge from ({@see OldPromiseRule::missesATrustedDate()}). The S10 entry and the
     * skipped check of a finding both read this list.
     *
     * @param bool $capped whether S11 fires, which unread metadata leaves only its `admits_newest` unread
     *
     * @return list<string>
     */
    public function metadataBlocks(PackageFacts $facts, bool $capped): array
    {
        $blocks = [Signal::S2];
        if ($this->oldPromise !== null && $this->oldPromise->missesATrustedDate($facts)) {
            $blocks[] = Signal::S5;
        }
        $blocks[] = Signal::S8;
        if ($capped) {
            $blocks[] = Signal::S11;
        }

        return $blocks;
    }

    /**
     * The `release_dates` entry, null when every age signal has its date. `blocks` names only the
     * signals that lost their input, and the causes say why each one did.
     */
    private function releaseDates(PackageFacts $facts, PackageMetadata $metadata): ?Check
    {
        $blocked = [];
        if ($metadata->hasTag() && $metadata->releaseReadingAt() === null) {
            $blocked[] = [$metadata->lastStableSharesItsCommit() ? self::CAUSE_SHARED_COMMIT : self::CAUSE_TAG_UNDATED, Signal::S2];
        }
        if ($this->oldPromise !== null && $this->oldPromise->missesATrustedDate($facts)) {
            $blocked[] = [self::INSTALLED_CAUSES[InstalledRelease::of($facts->package(), $metadata)->kind()] ?? self::CAUSE_LOCK_UNDATED, Signal::S5];
        }
        foreach (array_unique(array_map(static fn (string $tag): string => self::tagCause($metadata, $tag), $this->leftBehind->undatedReleases($facts))) as $cause) {
            $blocked[] = [$cause, Signal::S8];
        }
        if ($blocked === []) {
            return null;
        }
        $causes = [];
        $blocks = [];
        foreach ($blocked as [$cause, $signal]) {
            $causes[$cause] = [...$causes[$cause] ?? [], $signal];
            if (!\in_array($signal, $blocks, true)) {
                $blocks[] = $signal;
            }
        }

        return new Check('release_dates', 'undated_releases', $blocks, $causes);
    }

    private static function tagCause(PackageMetadata $metadata, string $tag): string
    {
        return $metadata->sharesItsCommit($tag) ? self::CAUSE_SHARED_COMMIT : self::CAUSE_TAG_UNDATED;
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
     * The `release_dates` entry of S10 in $signals, as a skip: what {@see withoutMaintenanceGaps()}
     * drops and no other skip restates. Null when S10 has no such entry.
     *
     * @param list<Signal> $signals
     */
    public static function releaseDatesSkip(array $signals): ?Check
    {
        foreach ($signals as $signal) {
            $data = $signal->data();
            foreach ($data instanceof NotCheckedData ? $data->checks() : [] as $entry) {
                if ($entry->check() === 'release_dates') {
                    return new Check($entry->check(), $entry->reason(), $entry->blocks());
                }
            }
        }

        return null;
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
            $cause = self::ORIGIN_CAUSE[$origin ?? ''] ?? 'not installed from a Composer repository';
        }
        if ($entry->check() === 'release_metadata') {
            return 'release metadata not checked ('.$cause.'), so '.ListWords::of(array_values(array_diff($entry->blocks(), [Signal::S11]))).' could not be read';
        }
        if ($entry->check() === 'repository_activity') {
            return 'repository activity not checked ('.$cause.'), so S3 and S4 could not be read';
        }
        if ($entry->check() === 'advisories' && isset($entry->causes()[self::CAUSE_PARTIAL])) {
            return 'security advisories not checked in full ('.(self::PARTIAL_ADVISORY_CAUSES[$entry->reason()] ?? $entry->reason()).'), so S9 may be incomplete';
        }
        if ($entry->check() === 'advisories') {
            return 'security advisories not checked ('.(self::ADVISORY_CAUSES[$entry->reason()] ?? $entry->reason()).'), so S9 could not be read';
        }
        if ($entry->check() === 'release_dates') {
            if ($entry->causes() === []) {
                throw new \LogicException('S10 has no cause for the release_dates entry.');
            }

            return implode('; ', array_map(static fn (string $cause, array $blocks): string => self::DATE_CAUSES[$cause].', so '.ListWords::of($blocks).(\in_array($cause, [self::CAUSE_LOCK_UNDATED, self::CAUSE_INSTALLED_SHARED_COMMIT, self::CAUSE_INSTALLED_PARENT_SPLIT], true) ? ' could not judge it' : ' could not measure it'), array_keys($entry->causes()), $entry->causes()));
        }

        throw new \LogicException('S10 has no words for the check '.$entry->check().'.');
    }
}
