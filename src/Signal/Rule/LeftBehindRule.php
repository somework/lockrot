<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Composer\Package\Package;
use Composer\Package\Version\VersionSelector;
use Composer\Repository\RepositorySet;
use Composer\Semver\VersionParser;
use Lockrot\Clock;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Signal\AgeMeasure;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;
use Lockrot\Signal\Thresholds;

/**
 * S8: the installed release branch has gone quiet while a higher branch kept releasing
 * (docs/verdicts.md#left-behind). The verdict is `left-behind` at either level, and the level only
 * records whether the branch also passed `release-high-years`.
 *
 * The newest higher branch proves the move. The branch to follow is the newest releasing one
 * within reach of the project's `require.php` and the target PHP ({@see PhpFloor},
 * docs/verdicts.md#within-reach).
 *
 * @internal
 */
final class LeftBehindRule implements SignalRule
{
    private Clock $clock;
    private Thresholds $thresholds;
    private AgeMeasure $age;
    private PhpFloor $floor;
    private VersionParser $parser;

    public function __construct(Clock $clock, Thresholds $thresholds, ?PhpFloor $floor = null)
    {
        $this->clock = $clock;
        $this->thresholds = $thresholds;
        $this->age = new AgeMeasure($clock, $thresholds);
        $this->floor = $floor ?? new PhpFloor(null);
        $this->parser = new VersionParser();
    }

    public function evaluate(PackageFacts $facts): ?Signal
    {
        $metadata = $facts->metadata();
        $branch = ReleaseBranch::of($facts->package()->version());
        if ($metadata === null || $branch === null) {
            return null;
        }
        $byBranch = $metadata->latestStableByBranch();
        // Only a branch past `release-warn-years` has a level, and S8 quotes the reading it judges
        // ({@see AgeMeasure::branchRelease()}).
        $reading = $this->age->branchRelease($facts);
        $level = $reading->level();
        if ($level === null) {
            return null;
        }
        $ownAt = $reading->measuredAt();

        /** @var array{branch: string, version: string, at: \DateTimeImmutable, php: ?string}|null $newest */
        $newest = null;
        /** @var array{branch: string, version: string, at: \DateTimeImmutable, php: ?string}|null $reachable */
        $reachable = null;
        foreach ($byBranch as $key => $release) {
            if ($release['at'] === null || !ReleaseBranch::isAbove((string) $key, $branch) || $release['at'] <= $ownAt) {
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
        // The branch to follow must release too: a quiet branch within reach is S2's case, not a
        // place to move to.
        if ($reachable !== null && !$this->isAlive($reachable['at'])) {
            $reachable = null;
        }
        $blocking = $this->floor->blocking($newest['php']);

        // The summary names the branch, not only the release: a maintainer moves to a branch. It
        // is the newest releasing higher branch, which can be an LTS below the current major. A
        // date from a monorepo parent says so ({@see PackageMetadata::datedBy()}).
        $datedBy = $reading->datedBy();
        $summary = \sprintf(
            'branch %s last released %s (%.1f years ago%s); %s released %s (%s)',
            ReleaseBranch::label($branch),
            $ownAt->format('Y-m-d'),
            $reading->years(),
            $datedBy === null ? '' : ', dated by '.$datedBy,
            ReleaseBranch::label($newest['branch']),
            $newest['version'],
            $newest['at']->format('Y-m-d')
        );
        if ($blocking !== null) {
            $summary .= \sprintf(', needs php %s above %s', (string) $newest['php'], $this->floor->describe($blocking));
            $summary .= $reachable === null
                ? '; no releasing branch within reach'
                : \sprintf('; %s released %s (%s)', ReleaseBranch::label($reachable['branch']), $reachable['version'], $reachable['at']->format('Y-m-d'));
        }

        return new Signal(Signal::S8, $level, $summary, [
            'branch' => ReleaseBranch::label($branch),
            'branch_last_release' => $ownAt->format(\DATE_ATOM),
            'branch_last_version' => $reading->version(),
            'years' => $reading->years(),
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
     * The constraint that `composer require` writes for the version
     * ({@see VersionSelector::findRecommendedRequireVersion()}), null when the repository's
     * version string cannot be parsed.
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
}
