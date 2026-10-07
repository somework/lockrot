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
 * The release scan of SPEC-0.14 5.3: which release fixes each counted advisory, how hard it is to
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
        $move = self::easiest($candidates, $ranged);
        $byBranch = [];
        foreach ($candidates as $candidate) {
            $byBranch[$candidate->branchKey()][] = $candidate;
        }
        $onInstalled = $installedBranch === null ? [] : ($byBranch[$installedBranch] ?? []);

        return new PackageFixes(
            $fixes,
            $this->branches($newest, $byBranch, $installedBranch, $ranged, \count($advisories)),
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
     * One row per branch the package has, highest first (P2). A branch's lower bound is the lowest
     * release from which every release up to its newest lies outside every range its newest clears.
     *
     * @param array<string, string>               $newest
     * @param array<string, list<Candidate>>      $byBranch ascending per branch
     * @param array<string, ConstraintInterface>  $ranged
     *
     * @return list<BranchFixes>
     */
    private function branches(array $newest, array $byBranch, ?string $installedBranch, array $ranged, int $of): array
    {
        $keys = array_map('strval', array_keys($newest));
        usort($keys, static fn (string $a, string $b): int => version_compare($b, $a));
        $rows = [];
        foreach ($keys as $key) {
            $candidates = $byBranch[$key] ?? [];
            $top = $candidates === [] ? null : $candidates[\count($candidates) - 1];
            $cleared = $top === null ? [] : self::cleared($top, $ranged);
            $lowest = null;
            $fixKind = null;
            if ($top !== null && $cleared !== []) {
                $fixKind = $this->branchClass($top->php());
                $kept = array_intersect_key($ranged, array_flip($cleared));
                foreach (array_reverse($candidates) as $candidate) {
                    if (!self::clearsAll($candidate, $kept)) {
                        break;
                    }
                    $lowest = $candidate;
                }
            }
            $rows[] = new BranchFixes(ReleaseBranch::label($key), $key === $installedBranch, \count($cleared), $of - \count($ranged), $of, $fixKind, $lowest, $newest[$key], $cleared);
        }

        return $rows;
    }

    /** @return Fix::UPDATE|Fix::RAISE_PHP|Fix::BLOCKED */
    private function branchClass(?string $php): string
    {
        if ($this->floor->admitsTarget($php) === false) {
            return Fix::BLOCKED;
        }

        return $this->floor->admitsProject($php) === false ? Fix::RAISE_PHP : Fix::UPDATE;
    }

    /**
     * `composer update <package>` installs the highest release on the installed branch that the
     * lock's links allow, whatever `require.php` says.
     *
     * @param list<Candidate>                    $onInstalled ascending
     * @param array<string, ConstraintInterface> $ranged
     */
    private function gets(array $onInstalled, array $ranged, int $of): ?Gets
    {
        $release = null;
        foreach ($onInstalled as $candidate) {
            if ($candidate->heldBy() === []) {
                $release = $candidate;
            }
        }
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
