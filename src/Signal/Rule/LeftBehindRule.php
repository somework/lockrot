<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Composer\Package\Package;
use Composer\Package\Version\VersionSelector;
use Composer\Repository\RepositorySet;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Lockrot\Clock;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
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
 *
 * The newest branch proves the move; the branch the signal tells the project to follow is the newest
 * releasing one *within reach* — one whose php requirement the project's own `require.php` and the
 * target PHP admit ({@see PhpFloor}). Matomo supports php >=7.2.5 and locks monolog 1.x: 3.x (php
 * >=8.1) is the proof, 2.x (php >=7.2, released last month) is the branch to require. When no
 * releasing branch is within reach the signal says so and suggests nothing: the way forward is a
 * PHP upgrade, which is not a `composer.json` line.
 *
 * @internal
 */
final class LeftBehindRule implements SignalRule
{
    private Clock $clock;
    private Thresholds $thresholds;
    private PhpFloor $floor;
    private VersionParser $parser;

    public function __construct(Clock $clock, Thresholds $thresholds, ?PhpFloor $floor = null)
    {
        $this->clock = $clock;
        $this->thresholds = $thresholds;
        $this->floor = $floor ?? new PhpFloor(null);
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

        /** @var array{branch: string, version: string, at: \DateTimeImmutable, php: ?string}|null $newest */
        $newest = null;
        /** @var array{branch: string, version: string, at: \DateTimeImmutable, php: ?string}|null $reachable */
        $reachable = null;
        foreach ($byBranch as $key => $release) {
            if ($release['at'] === null || !ReleaseBranch::isAbove((string) $key, $branch) || $release['at'] <= $own['at']) {
                continue;
            }
            $candidate = ['branch' => (string) $key, 'version' => $release['version'], 'at' => $release['at'], 'php' => $release['php'] ?? null];
            if ($newest === null || $release['at'] > $newest['at']) {
                $newest = $candidate;
            }
            if ($this->floor->blocking($candidate['php']) === null && ($reachable === null || $release['at'] > $reachable['at'])) {
                $reachable = $candidate;
            }
        }
        if ($newest === null || !$this->isAlive($newest['at'])) {
            return null;
        }
        // The branch to follow has to be releasing too: one within reach that went quiet is S2's
        // case for whoever installs it, not a place to move to.
        if ($reachable !== null && !$this->isAlive($reachable['at'])) {
            $reachable = null;
        }
        $blocking = $this->floor->blocking($newest['php']);

        $years = $this->clock->yearsSince($own['at']);
        $level = Thresholds::levelFor($years, $this->thresholds->releaseWarnYears(), $this->thresholds->releaseHighYears());
        if ($level === null) {
            return null;
        }

        // The branch is named, not only the release: the branch is what a maintainer moves to, and
        // it is the newest *releasing* higher branch — with a living LTS below the current major
        // that can be the LTS, which is a fact about where fixes land, not a claim about the latest.
        // A branch dated by the monorepo says so: the date is laravel/framework's release, read
        // through `replace`, not one this package's own tags carry ({@see PackageMetadata::datedBy()}).
        $datedBy = $own['dated_by'] ?? null;
        $summary = \sprintf(
            'branch %s last released %s (%.1f years ago%s); %s released %s (%s)',
            ReleaseBranch::label($branch),
            $own['at']->format('Y-m-d'),
            $years,
            $datedBy === null ? '' : ', dated by '.$datedBy,
            ReleaseBranch::label($newest['branch']),
            $newest['version'],
            $newest['at']->format('Y-m-d')
        );
        // Out of reach: say what holds it back, then the branch that is not — or that there is none.
        if ($blocking !== null) {
            $summary .= \sprintf(', needs php %s above %s', (string) $newest['php'], $this->floor->describe($blocking));
            $summary .= $reachable === null
                ? '; no releasing branch within reach'
                : \sprintf('; %s released %s (%s)', ReleaseBranch::label($reachable['branch']), $reachable['version'], $reachable['at']->format('Y-m-d'));
        }

        return new Signal(Signal::S8, $level, $summary, [
            'branch' => ReleaseBranch::label($branch),
            'branch_last_release' => $own['at']->format(\DATE_ATOM),
            'branch_last_version' => $own['version'],
            'years' => round($years, 1),
            'newest_branch' => ReleaseBranch::label($newest['branch']),
            'newest_version' => $newest['version'],
            'newest_release' => $newest['at']->format(\DATE_ATOM),
            'newest_php' => $newest['php'],
            'newest_within_reach' => $blocking === null,
            'floor_php' => $blocking === null ? null : $this->floor->php($blocking),
            'floor_source' => $blocking,
            'reachable_branch' => $reachable === null ? null : ReleaseBranch::label($reachable['branch']),
            'reachable_version' => $reachable === null ? null : $reachable['version'],
            'reachable_release' => $reachable === null ? null : $reachable['at']->format(\DATE_ATOM),
            'suggested_constraint' => $reachable === null ? null : $this->suggestedConstraint($facts->package()->name(), $reachable['version']),
            'dated_by' => $datedBy,
        ]);
    }

    /** Released within `release-warn-years` of today: the branch is still where fixes land. */
    private function isAlive(\DateTimeImmutable $at): bool
    {
        return $this->clock->yearsSince($at) < $this->thresholds->releaseWarnYears();
    }

    /**
     * The constraint that follows the upstream onto the newest releasing branch within reach —
     * `^8.2` for 8.2.0, `^0.4.3` below 1.0 — written the way `composer require` would write it
     * ({@see VersionSelector::findRecommendedRequireVersion()}), so it can be pasted into
     * composer.json or handed to a bot; null when the repository's version string cannot be parsed.
     */
    private function suggestedConstraint(string $package, string $newestVersion): ?string
    {
        try {
            $normalized = $this->parser->normalize($newestVersion);
        } catch (\UnexpectedValueException $e) {
            return null;
        }

        return (new VersionSelector(new RepositorySet()))->findRecommendedRequireVersion(new Package($package, $normalized, $newestVersion));
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
