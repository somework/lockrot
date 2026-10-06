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
 * {@see \Lockrot\Analyzer\Analyzer}, not this rule, keeps S10 off an allowlisted package and off
 * one that the repository marks abandoned.
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

    public function evaluate(PackageFacts $facts): ?Signal
    {
        $unchecked = [];
        $blocks = [];
        $summaries = [];

        $reason = $facts->activityNotChecked();
        if ($reason !== null && isset(self::ACTIVITY_WORDS[$reason])) {
            $unchecked[] = ['check' => 'repository_activity', 'reason' => $reason, 'blocks' => [Signal::S3, Signal::S4]];
            $blocks[] = Signal::S3;
            $blocks[] = Signal::S4;
            $summaries[] = 'repository activity not checked ('.self::ACTIVITY_WORDS[$reason].'), so S3 and S4 could not be read';
        }

        $metadata = $facts->metadata();
        // PackageMetadata::fromPackages() leaves the newest tag undated where a shared commit
        // dates it: S2 cannot measure that.
        if ($metadata !== null && $metadata->hasStableRelease() && $metadata->lastStableReleaseAt() === null) {
            // A branch snapshot is on no release branch, so S8 misses nothing there. S2 reads the
            // package's newest release whatever the lock installs, and with S4 it makes
            // the snapshot `silent`, not `pinned`.
            $ageBlocks = ReleaseBranch::of($facts->package()->version()) === null ? [Signal::S2] : [Signal::S2, Signal::S8];
            $unchecked[] = ['check' => 'release_dates', 'reason' => 'undated_releases', 'blocks' => $ageBlocks];
            foreach ($ageBlocks as $id) {
                $blocks[] = $id;
            }
            $summaries[] = 'the age of the package was not read (its newest releases are dated by a commit their tags share), so '.implode(' and ', $ageBlocks).' could not measure it';
        }

        if ($unchecked === []) {
            return null;
        }

        return new Signal(Signal::S10, Signal::LEVEL_INFO, implode('; ', $summaries), ['unchecked' => $unchecked, 'blocks' => $blocks]);
    }
}
