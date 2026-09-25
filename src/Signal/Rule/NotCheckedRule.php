<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;

/**
 * S10: a check this package's verdict rests on did not run, and what it could not decide.
 *
 * Every other signal reports something observed. Without this one a package whose repository was
 * never asked about reads exactly like a package that was asked and came back healthy: both say
 * `ok`, and the only trace is a report-wide note counting how many were skipped, which names none
 * of them. Two checks can be missing:
 *
 *  - the repository activity behind S3 (archived) and S4 (no push), skipped by the anonymous
 *    request cap, an exhausted budget, a rate limit, `--offline` or a failed request. S3 alone
 *    decides `abandoned`, so the gap can hide the most serious verdict there is;
 *  - the package's own age behind S2 (no stable release) and S8 (left behind), where the
 *    repository dates its newest tags only by a commit those tags share and no monorepo parent
 *    dates them either ({@see \Lockrot\Data\Repository\PackageMetadata::fromPackages()}).
 *
 * The signal is informational, like S7 and S9: it never decides a verdict, and it is raised only
 * where the missing check could have changed one. A package the repository already marks abandoned
 * has nothing left for the activity round to add, and an allowlisted package answers `finished`
 * whatever its signals say, so {@see \Lockrot\Analyzer\Analyzer} leaves it off those.
 *
 * @internal
 */
final class NotCheckedRule implements SignalRule
{
    /** The run is anonymous on a capped forge and the package was not a candidate for an activity verdict. */
    public const NO_TOKEN = 'no_token';
    /** A candidate the anonymous request budget could not fit. */
    public const RATE_BUDGET = 'anonymous_budget';
    /** The install-time time budget ran out before the activity round. */
    public const BUDGET = 'install_time_budget';
    /** The forge answered "too many requests". */
    public const RATE_LIMIT = 'rate_limit';
    /** The request failed: a timeout, a transport error, a forge that could not be reached. */
    public const FETCH_FAILED = 'fetch_failed';
    /** `--offline`: no forge was asked at all. */
    public const OFFLINE = 'offline';

    /** Short enough for the evidence line a flagged row prints; the reason key carries the rest. */
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
        // fromPackages() hands the newest tag over undated exactly where its date is a commit's,
        // which is the case S2 cannot measure and the branch table marks with `commit …`.
        if ($metadata !== null && $metadata->hasStableRelease() && $metadata->lastStableReleaseAt() === null) {
            // S8 measures the installed version's release branch, and a branch snapshot is on
            // none, so nothing it could have read is missing there. S2 reads the package's own
            // newest release whatever the lock installs, and with S4 it is what would have made
            // the snapshot `silent` rather than `pinned`.
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
