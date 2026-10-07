<?php

declare(strict_types=1);

namespace Lockrot\Security;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Intervals;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Data\Repository\StableRelease;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;

/**
 * The release scan: which release fixes each counted advisory, how hard it is to
 * reach, and what holds it. lockrot reads the lock as it is and does not solve dependencies: a
 * class checks the lock's links on the package, not the fixing release's own requirements.
 *
 * @internal
 */
final class FixFinder
{
    private PhpFloor $floor;
    private LinkIndex $links;

    public function __construct(PhpFloor $floor, LinkIndex $links)
    {
        $this->floor = $floor;
        $this->links = $links;
    }

    /** The facts' advisories are the counted ones: those that affect the installed version. */
    public function find(PackageFacts $facts): PackageFixes
    {
        $advisories = $facts->advisories();
        if ($advisories === []) {
            return new PackageFixes([], [], null, null, null);
        }
        $package = $facts->package();
        $metadata = $facts->metadata();
        $releases = $metadata === null ? null : $metadata->releasesAbove();
        if ($metadata === null || $releases === null) {
            $unknown = Fix::unknown($package->isFromComposerRepository() ? Fix::RELEASES_UNKNOWN : Fix::NOT_FROM_COMPOSER_REPOSITORY);
            $fixes = [];
            foreach ($advisories as $advisory) {
                $fixes[$advisory->id()] = $unknown;
            }

            return new PackageFixes($fixes, [], null, null, null);
        }

        $installedBranch = ReleaseBranch::of($package->version());
        $newest = [];
        foreach ($metadata->latestStableByBranch() as $key => $branch) {
            $newest[(string) $key] = $branch['highest']['pretty'];
        }
        $candidates = $this->candidates($package->name(), $releases);
        $ranges = [];
        foreach ($advisories as $advisory) {
            $ranges[$advisory->id()] = $advisory->affectedRange();
        }
        /** @var array<string, ConstraintInterface> $ranged */
        $ranged = array_filter($ranges, static fn (?ConstraintInterface $range): bool => $range !== null);

        $fixes = [];
        foreach ($ranges as $id => $range) {
            $fixes[$id] = $this->fixOf($range, $candidates, $newest, $installedBranch);
        }
        $byBranch = [];
        foreach ($candidates as $candidate) {
            $byBranch[$candidate->branchKey()][] = $candidate;
        }
        $onInstalled = $installedBranch === null ? [] : ($byBranch[$installedBranch] ?? []);
        $rows = self::branches($newest, $byBranch, $installedBranch, $ranged, \count($advisories));
        $move = self::move($rows, \count($ranged));

        return new PackageFixes(
            $fixes,
            $rows,
            $this->gets($onInstalled, $ranged, \count($advisories)),
            $move,
            self::partial($move, $onInstalled, $ranged, \count($advisories))
        );
    }

    /**
     * Every kept release with its class: the class reads only PHP, and the links fill the holders.
     *
     * @param list<StableRelease> $releases ascending
     *
     * @return list<Candidate> ascending
     */
    private function candidates(string $package, array $releases): array
    {
        $candidates = [];
        foreach ($releases as $release) {
            $branch = ReleaseBranch::of($release->normalized());
            if ($branch === null) {
                continue;
            }
            $heldBy = $this->links->excluding($package, $release->normalized());
            if ($this->floor->admitsTarget($release->php()) === false) {
                $kind = Fix::BLOCKED;
            } elseif ($this->floor->admitsProject($release->php()) === false) {
                $kind = Fix::RAISE_PHP;
            } else {
                $kind = $heldBy === [] ? Fix::UPDATE : Fix::UPGRADE;
            }
            $candidates[] = new Candidate($release, $branch, $kind, $heldBy);
        }

        return $candidates;
    }

    /**
     * @param list<Candidate>       $candidates
     * @param array<string, string> $newest     the newest release by branch key
     */
    private function fixOf(?ConstraintInterface $range, array $candidates, array $newest, ?string $installedBranch): Fix
    {
        if ($range === null) {
            return Fix::unknown(Fix::AFFECTED_RANGE_UNKNOWN);
        }
        $best = self::easiest($candidates, [$range]);

        return $best === null ? Fix::none() : Fix::to($best, $newest[$best->branchKey()], $installedBranch);
    }

    /**
     * The easiest candidate outside every range, the lowest release at its ease. Null with no range
     * or no such candidate.
     *
     * @param list<Candidate>           $candidates ascending
     * @param array<ConstraintInterface> $ranges
     */
    private static function easiest(array $candidates, array $ranges): ?Candidate
    {
        if ($ranges === []) {
            return null;
        }
        $best = null;
        foreach ($candidates as $candidate) {
            if (($best === null || $candidate->easeRank() < $best->easeRank()) && self::clearsAll($candidate, $ranges)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * The security move: of the branches whose lower bound clears every counted range, the easiest
     * lower bound, then the lowest release. A constraint from that bound admits no release up to the
     * branch's newest that a counted range holds. Null with no range or no such branch.
     *
     * @param list<BranchFixes> $rows highest branch first
     */
    private static function move(array $rows, int $ranged): ?Candidate
    {
        $best = null;
        foreach (array_reverse($rows) as $row) {
            $candidate = $row->candidate();
            if ($candidate !== null && $row->fixed() === $ranged && ($best === null || $candidate->easeRank() < $best->easeRank())) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * One row per branch the package has, highest first. A row fixes the ranges that its newest
     * release lies outside. Its lower bound is the lowest release from which every release up to the
     * newest lies outside them, and the row's class is that release's class.
     *
     * @param array<string, string>               $newest
     * @param array<string, list<Candidate>>      $byBranch ascending per branch
     * @param array<string, ConstraintInterface>  $ranged
     *
     * @return list<BranchFixes>
     */
    private static function branches(array $newest, array $byBranch, ?string $installedBranch, array $ranged, int $of): array
    {
        $keys = array_map('strval', array_keys($newest));
        usort($keys, static fn (string $a, string $b): int => version_compare($b, $a));
        $rows = [];
        foreach ($keys as $key) {
            $candidates = $byBranch[$key] ?? [];
            $top = $candidates === [] ? null : $candidates[\count($candidates) - 1];
            $cleared = $top === null ? [] : self::cleared($top, $ranged);
            $lowest = null;
            if ($cleared !== []) {
                $kept = array_intersect_key($ranged, array_flip($cleared));
                foreach (array_reverse($candidates) as $candidate) {
                    if (!self::clearsAll($candidate, $kept)) {
                        break;
                    }
                    $lowest = $candidate;
                }
            }
            $rows[] = new BranchFixes(ReleaseBranch::label($key), $key === $installedBranch, \count($cleared), $of - \count($ranged), $of, $lowest, $newest[$key], $cleared);
        }

        return $rows;
    }

    /**
     * `composer update <package>` installs the highest release on the installed branch that the
     * lock's links allow and the target runs, whatever `require.php` says. With none that the target
     * runs, the highest one that the links allow says why.
     *
     * @param list<Candidate>                    $onInstalled ascending
     * @param array<string, ConstraintInterface> $ranged
     */
    private function gets(array $onInstalled, array $ranged, int $of): ?Gets
    {
        $release = null;
        $blocked = null;
        foreach ($onInstalled as $candidate) {
            if ($candidate->heldBy() !== []) {
                continue;
            }
            if ($candidate->kind() === Fix::BLOCKED) {
                $blocked = $candidate;
            } else {
                $release = $candidate;
            }
        }
        $release ??= $blocked;
        if ($release === null) {
            return null;
        }
        $check = PhpCheck::of($release->php(), $this->floor);
        if ($check->targetRuns() === false) {
            return new Gets(null, $check, [], false);
        }
        $clears = self::cleared($release, $ranged);

        return new Gets($release->release()->pretty(), $check, $clears, \count($clears) === $of);
    }

    /**
     * @param list<Candidate>                    $onInstalled ascending
     * @param array<string, ConstraintInterface> $ranged
     */
    private static function partial(?Candidate $move, array $onInstalled, array $ranged, int $of): ?Partial
    {
        if ($onInstalled === [] || ($move !== null && $move->kind() === Fix::UPDATE)) {
            return null;
        }
        $newest = $onInstalled[\count($onInstalled) - 1];
        if ($newest->kind() !== Fix::UPDATE) {
            return null;
        }
        $clears = self::cleared($newest, $ranged);

        return $clears === [] || \count($clears) === $of ? null : new Partial($newest, $clears);
    }

    /**
     * @param array<string, ConstraintInterface> $ranged
     *
     * @return list<string> the ids whose range the candidate lies outside of
     */
    private static function cleared(Candidate $candidate, array $ranged): array
    {
        $cleared = [];
        foreach ($ranged as $id => $range) {
            if (self::clearsAll($candidate, [$range])) {
                $cleared[] = $id;
            }
        }

        return $cleared;
    }

    /** @param array<ConstraintInterface> $ranges */
    private static function clearsAll(Candidate $candidate, array $ranges): bool
    {
        $release = new Constraint('==', $candidate->release()->normalized());
        foreach ($ranges as $range) {
            if (Intervals::haveIntersections($range, $release)) {
                return false;
            }
        }

        return true;
    }
}
