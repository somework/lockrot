<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Lockrot\Clock;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;
use Lockrot\Signal\Thresholds;

/**
 * S8: the package is alive, the installed branch is not. The highest stable release on the
 * installed version's branch ({@see ReleaseBranch}) is older than the release thresholds, while
 * some higher branch has released since. S2 cannot see this — it reads the package's newest
 * release, which is exactly the one that is fresh here — and `composer outdated` says a newer
 * major exists without saying that the one installed gets no fixes.
 *
 * A higher branch that stopped releasing *before* the installed one did says nothing (a
 * pre-release major that was abandoned, say), so it does not count as moving on. When every
 * branch is old, S2 already speaks.
 */
final class LeftBehindRule implements SignalRule
{
    private Clock $clock;
    private Thresholds $thresholds;

    public function __construct(Clock $clock, Thresholds $thresholds)
    {
        $this->clock = $clock;
        $this->thresholds = $thresholds;
    }

    public function evaluate(PackageFacts $facts): ?Signal
    {
        $metadata = $facts->metadata();
        if ($metadata === null) {
            return null;
        }
        $branch = ReleaseBranch::of($facts->package()->version());
        if ($branch === null) {
            return null;
        }
        $byBranch = $metadata->latestStableByBranch();
        $own = $byBranch[$branch] ?? null;
        if ($own === null || $own['at'] === null) {
            return null;
        }

        $newest = null;
        foreach ($byBranch as $key => $release) {
            if ($release['at'] === null || !ReleaseBranch::isAbove((string) $key, $branch) || $release['at'] <= $own['at']) {
                continue;
            }
            if ($newest === null || $release['at'] > $newest['at']) {
                $newest = $release;
            }
        }
        if ($newest === null) {
            return null;
        }

        $years = $this->clock->yearsSince($own['at']);
        $level = Thresholds::levelFor($years, $this->thresholds->releaseWarnYears(), $this->thresholds->releaseHighYears());
        if ($level === null) {
            return null;
        }

        $summary = \sprintf(
            'branch %s last released %s (%.1f years ago); upstream moved on to %s (%s)',
            ReleaseBranch::label($branch),
            $own['at']->format('Y-m-d'),
            $years,
            $newest['version'],
            $newest['at']->format('Y-m-d')
        );

        return new Signal(Signal::S8, $level, $summary, [
            'branch' => ReleaseBranch::label($branch),
            'branch_last_release' => $own['at']->format(\DATE_ATOM),
            'branch_last_version' => $own['version'],
            'years' => round($years, 1),
            'newest_version' => $newest['version'],
            'newest_release' => $newest['at']->format(\DATE_ATOM),
        ]);
    }
}
