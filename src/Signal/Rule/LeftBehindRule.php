<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Lockrot\Clock;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;
use Lockrot\Signal\Thresholds;

/**
 * S8: the package is alive, the installed branch is not. The highest stable release on the
 * installed version's branch ({@see ReleaseBranch}) is older than `release-warn-years`, while
 * some higher branch has released since. The verdict is `left-behind` at either level; the level
 * only records whether the branch has also passed `release-high-years`. S2 cannot see this — it reads the package's newest
 * release, which is exactly the one that is fresh here — and `composer outdated` says a newer
 * major exists without saying that the one installed gets no fixes.
 *
 * "Moved on" means a higher branch released after the installed one's last release *and* within
 * `release-warn-years` of today: a package whose every branch went quiet years ago is not alive,
 * it is S2's case (`stale`, or `silent` with S4), and a 2.0 that was itself abandoned before 1.x
 * stopped says nothing about 1.x. An installed version the repository does not list — above
 * everything it has on that branch, as a lock written against a since-removed tag would be —
 * cannot be measured by that branch's last date, so it carries no S8 either; nor does a branch
 * whose highest tag the repository leaves undated.
 */
final class LeftBehindRule implements SignalRule
{
    private Clock $clock;
    private Thresholds $thresholds;
    private VersionParser $parser;

    public function __construct(Clock $clock, Thresholds $thresholds)
    {
        $this->clock = $clock;
        $this->thresholds = $thresholds;
        $this->parser = new VersionParser();
    }

    public function evaluate(PackageFacts $facts): ?Signal
    {
        $metadata = $facts->metadata();
        if ($metadata === null) {
            return null;
        }
        $branch = ReleaseBranch::of($facts->package()->version());
        $byBranch = $metadata->latestStableByBranch();
        // An undated highest tag means the branch's newest release is one the repository does not
        // date; how much younger than the newest dated release it is cannot be known, so the branch
        // is not measured (a subtree split — illuminate/*, symfony/* — dates tags by the commit they
        // point at, and leaves many with no date at all; a tag sharing its commit with another is
        // handed over undated for the same reason, see PackageMetadata::fromPackages()).
        $own = $branch === null ? null : ($byBranch[$branch] ?? null);
        if ($branch === null || $own === null || $own['at'] === null || $own['highest']['at'] === null || $this->isAhead($facts->package()->version(), $own['highest']['normalized'])) {
            return null;
        }

        /** @var array{branch: string, version: string, at: \DateTimeImmutable}|null $newest */
        $newest = null;
        foreach ($byBranch as $key => $release) {
            if ($release['at'] === null || !ReleaseBranch::isAbove((string) $key, $branch) || $release['at'] <= $own['at']) {
                continue;
            }
            if ($newest === null || $release['at'] > $newest['at']) {
                $newest = ['branch' => (string) $key, 'version' => $release['version'], 'at' => $release['at']];
            }
        }
        if ($newest === null || $this->clock->yearsSince($newest['at']) >= $this->thresholds->releaseWarnYears()) {
            return null;
        }

        $years = $this->clock->yearsSince($own['at']);
        $level = Thresholds::levelFor($years, $this->thresholds->releaseWarnYears(), $this->thresholds->releaseHighYears());
        if ($level === null) {
            return null;
        }

        // The branch is named, not only the release: the branch is what a maintainer moves to, and
        // it is the newest *releasing* higher branch — with a living LTS below the current major
        // that can be the LTS, which is a fact about where fixes land, not a claim about the latest.
        $summary = \sprintf(
            'branch %s last released %s (%.1f years ago); %s released %s (%s)',
            ReleaseBranch::label($branch),
            $own['at']->format('Y-m-d'),
            $years,
            ReleaseBranch::label($newest['branch']),
            $newest['version'],
            $newest['at']->format('Y-m-d')
        );

        return new Signal(Signal::S8, $level, $summary, [
            'branch' => ReleaseBranch::label($branch),
            'branch_last_release' => $own['at']->format(\DATE_ATOM),
            'branch_last_version' => $own['version'],
            'years' => round($years, 1),
            'newest_branch' => ReleaseBranch::label($newest['branch']),
            'newest_version' => $newest['version'],
            'newest_release' => $newest['at']->format(\DATE_ATOM),
        ]);
    }

    /**
     * Whether the installed version is above the highest stable tag the repository lists on its
     * branch — not above its newest release, which a backport on a lower minor can be.
     *
     * @param string $branchHighest already normalized ({@see PackageMetadata::latestStableByBranch()})
     */
    private function isAhead(string $installed, string $branchHighest): bool
    {
        try {
            return Comparator::greaterThan($this->parser->normalize($installed), $branchHighest);
        } catch (\UnexpectedValueException $e) {
            return true;
        }
    }
}
