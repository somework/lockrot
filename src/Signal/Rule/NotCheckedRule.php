<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Lockrot\Data\Repository\ReleaseBranch;
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

    /** Short enough for the evidence line of a flagged row. The reason key carries the rest. */
    private const ACTIVITY_WORDS = [
        self::NO_TOKEN => 'no token for the repository host',
        self::RATE_BUDGET => 'the anonymous request budget was spent',
        self::BUDGET => 'the install-time budget ran out',
        self::RATE_LIMIT => 'the host answered "too many requests"',
        self::FETCH_FAILED => 'the request failed',
        self::OFFLINE => 'the run is offline',
    ];

    /** The S10 checks about the package's upkeep, which a raised S1 makes moot. */
    private const MAINTENANCE_CHECKS = ['repository_activity', 'release_dates'];

    public function evaluate(PackageFacts $facts): ?Signal
    {
        $unchecked = [];
        $reason = $facts->activityNotChecked();
        if ($reason !== null && isset(self::ACTIVITY_WORDS[$reason])) {
            $unchecked[] = ['check' => 'repository_activity', 'reason' => $reason, 'blocks' => [Signal::S3, Signal::S4]];
        }

        $metadata = $facts->metadata();
        // PackageMetadata::fromPackages() leaves the newest tag undated where a shared commit
        // dates it: S2 cannot measure that.
        if ($metadata !== null && $metadata->hasStableRelease() && $metadata->lastStableReleaseAt() === null) {
            // A branch snapshot is on no release branch, so S8 misses nothing there. S2 reads the
            // package's newest release whatever the lock installs, and with S4 it can make
            // the snapshot `silent`, not `pinned`.
            $ageBlocks = ReleaseBranch::of($facts->package()->version()) === null ? [Signal::S2] : [Signal::S2, Signal::S8];
            $unchecked[] = ['check' => 'release_dates', 'reason' => 'undated_releases', 'blocks' => $ageBlocks];
        }

        return self::signalOf($unchecked);
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
            if ($signal->id() !== Signal::S10) {
                $kept[] = $signal;
                continue;
            }
            $entries = [];
            foreach (self::entries($signal) as $entry) {
                if (!\in_array($entry['check'], self::MAINTENANCE_CHECKS, true)) {
                    $entries[] = $entry;
                }
            }
            $rebuilt = self::signalOf($entries);
            if ($rebuilt !== null) {
                $kept[] = $rebuilt;
            }
        }

        return $kept;
    }

    /** @return list<array{check: string, reason: string, blocks: list<string>}> */
    private static function entries(Signal $signal): array
    {
        $entries = [];
        $unchecked = $signal->data()['unchecked'] ?? [];
        foreach (\is_array($unchecked) ? $unchecked : [] as $entry) {
            if (\is_array($entry) && \is_string($entry['check'] ?? null) && \is_string($entry['reason'] ?? null) && \is_array($entry['blocks'] ?? null)) {
                $entries[] = ['check' => $entry['check'], 'reason' => $entry['reason'], 'blocks' => array_values(array_filter($entry['blocks'], 'is_string'))];
            }
        }

        return $entries;
    }

    /** @param list<array{check: string, reason: string, blocks: list<string>}> $unchecked */
    private static function signalOf(array $unchecked): ?Signal
    {
        if ($unchecked === []) {
            return null;
        }
        $blocks = [];
        $summaries = [];
        foreach ($unchecked as $entry) {
            foreach ($entry['blocks'] as $id) {
                $blocks[] = $id;
            }
            $summaries[] = self::summaryOf($entry);
        }

        return new Signal(Signal::S10, Signal::LEVEL_INFO, implode('; ', $summaries), ['unchecked' => $unchecked, 'blocks' => $blocks]);
    }

    /** @param array{check: string, reason: string, blocks: list<string>} $entry */
    private static function summaryOf(array $entry): string
    {
        if ($entry['check'] === 'repository_activity') {
            return 'repository activity not checked ('.(self::ACTIVITY_WORDS[$entry['reason']] ?? $entry['reason']).'), so S3 and S4 could not be read';
        }
        if ($entry['check'] === 'release_dates') {
            return 'the age of the package was not read (its newest releases are dated by a commit their tags share), so '.implode(' and ', $entry['blocks']).' could not measure it';
        }

        throw new \LogicException('S10 has no words for the check '.$entry['check'].'.');
    }
}
