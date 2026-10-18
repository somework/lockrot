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
 * reach, and what holds it. A class checks the lock's links on the package, and the holds of each
 * move of the package that Composer's solver refused ({@see RefusedMoves}).
 *
 * @internal
 */
final class FixFinder
{
    private PhpFloor $floor;
    private LinkIndex $links;
    private RefusedMoves $refused;

    public function __construct(PhpFloor $floor, LinkIndex $links)
    {
        $this->floor = $floor;
        $this->links = $links;
        $this->refused = RefusedMoves::none();
    }

    /** The scan of one package, with the moves of it that the solver refused. */
    public function withRefused(RefusedMoves $refused): self
    {
        $clone = clone $this;
        $clone->refused = $refused;

        return $clone;
    }

    /**
     * The facts' advisories are the counted ones: those that affect the installed version.
     *
     * @param list<Advisory> $every every advisory of the package on any version (`AdvisoryBatch::every()`): a
     *                              window starts above each one that the facts do not count
     */
    public function find(PackageFacts $facts, array $every = []): PackageFixes
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
        $own = self::otherVersionRanges($advisories, $every);
        $byBranch = [];
        foreach ($candidates as $candidate) {
            $byBranch[$candidate->branchKey()][] = $candidate;
        }

        $fixes = [];
        foreach ($ranges as $id => $range) {
            $fixes[$id] = $range === null ? Fix::unknown(Fix::AFFECTED_RANGE_UNKNOWN) : self::fixOf($range, $byBranch, $own, $newest, $installedBranch);
        }
        $onInstalled = $installedBranch === null ? [] : ($byBranch[$installedBranch] ?? []);
        $rows = self::branches($newest, $byBranch, $installedBranch, $ranged, $own, \count($advisories));
        $move = self::move($rows, \count($advisories));

        return new PackageFixes(
            $fixes,
            $rows,
            $this->gets($onInstalled, $ranged, \count($advisories)),
            $move,
            self::partial($onInstalled, $ranged, \count($advisories))
        );
    }

    /**
     * The ranges of the advisories that $counted does not hold: they raise every window.
     *
     * @param list<Advisory> $counted
     * @param list<Advisory> $every
     *
     * @return list<ConstraintInterface>
     */
    public static function otherVersionRanges(array $counted, array $every): array
    {
        $ids = array_map(static fn (Advisory $advisory): string => $advisory->id(), $counted);
        $own = [];
        foreach ($every as $advisory) {
            $range = $advisory->affectedRange();
            if ($range !== null && !\in_array($advisory->id(), $ids, true)) {
                $own[] = $range;
            }
        }

        return $own;
    }

    /**
     * Every kept release with its class.
     *
     * @param list<StableRelease> $releases ascending
     *
     * @return list<Candidate> ascending
     */
    private function candidates(string $package, array $releases): array
    {
        $candidates = [];
        foreach ($releases as $release) {
            $candidate = $this->candidate($package, $release);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /** The release with its class: the class reads only PHP, and the links fill the holders. Null off every branch. */
    public function candidate(string $package, StableRelease $release): ?Candidate
    {
        $branch = ReleaseBranch::of($release->normalized());
        if ($branch === null) {
            return null;
        }
        $heldBy = Holder::distinct(array_merge($this->links->excluding($package, $release->normalized()), $this->refused->holding($release->normalized())));
        // A refusal that names no hold leaves no move that lockrot can name: no reachable fix (SPEC §5.3).
        if ($this->floor->admitsTarget($release->php()) === false || ($heldBy === [] && $this->refused->refuses($release->normalized()))) {
            $kind = Fix::BLOCKED;
        } elseif ($this->floor->admitsProject($release->php()) === false) {
            $kind = Fix::RAISE_PHP;
        } else {
            $kind = $heldBy === [] ? Fix::UPDATE : Fix::UPGRADE;
        }

        return new Candidate($release, $branch, $kind, $heldBy);
    }

    /**
     * The easiest release, then the lowest, of every branch's window for the one range.
     *
     * @param array<string, list<Candidate>> $byBranch the lowest branch first, ascending per branch
     * @param list<ConstraintInterface>      $own      the ranges of the advisories the facts do not count
     * @param array<string, string>          $newest   the newest release by branch key
     */
    private static function fixOf(ConstraintInterface $range, array $byBranch, array $own, array $newest, ?string $installedBranch): Fix
    {
        $best = null;
        foreach ($byBranch as $candidates) {
            $candidate = self::easiestIn(self::window($candidates, [$range], $own));
            if ($candidate !== null && ($best === null || $candidate->easeRank() < $best->easeRank())) {
                $best = $candidate;
            }
        }

        return $best === null ? Fix::none() : Fix::to($best, $newest[$best->branchKey()], $installedBranch);
    }

    /**
     * A branch's safe window: every release from its lower bound R up to its newest, where R is the
     * lowest release from which each one lies outside every range. R then rises above each range of
     * $own that leaves the newest release outside. A range that holds the newest release reaches
     * every constraint on the branch, so it does not raise R (SPEC §5.3).
     *
     * @param list<Candidate>            $candidates one branch, ascending
     * @param array<ConstraintInterface> $ranges
     * @param list<ConstraintInterface>  $own
     *
     * @return list<Candidate> ascending, empty when the newest release lies inside a range
     */
    private static function window(array $candidates, array $ranges, array $own): array
    {
        $window = self::outsideFromTop($candidates, $ranges);
        if ($window === []) {
            return [];
        }
        $newest = $window[\count($window) - 1];

        return self::outsideFromTop($window, array_filter($own, static fn (ConstraintInterface $range): bool => self::clearsAll($newest, [$range])));
    }

    /**
     * @param list<Candidate>            $candidates ascending
     * @param array<ConstraintInterface> $ranges
     *
     * @return list<Candidate> the longest tail of $candidates outside every range
     */
    private static function outsideFromTop(array $candidates, array $ranges): array
    {
        $from = \count($candidates);
        while ($from > 0 && self::clearsAll($candidates[$from - 1], $ranges)) {
            --$from;
        }

        return \array_slice($candidates, $from);
    }

    /**
     * The easiest candidate, the lowest at its ease.
     *
     * @param list<Candidate> $candidates ascending
     */
    private static function easiestIn(array $candidates): ?Candidate
    {
        $best = null;
        foreach ($candidates as $candidate) {
            if ($best === null || $candidate->easeRank() < $best->easeRank()) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * The security move: of the rows that fix every counted range, the easiest candidate, then the
     * lowest release. A constraint from it admits no release up to the branch's newest that a counted
     * range holds. Null with no range or no such row.
     *
     * @param list<BranchFixes> $rows highest branch first
     * @param int               $of   the counted advisories: a row with no range for one of them fixes not all
     */
    private static function move(array $rows, int $of): ?Candidate
    {
        $best = null;
        foreach (array_reverse($rows) as $row) {
            $candidate = $row->candidate();
            if ($candidate !== null && $row->fixed() === $of && ($best === null || $candidate->easeRank() < $best->easeRank())) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * One row per branch the package has, highest first. A row fixes the ranges that its newest
     * release lies outside. Its candidate is the easiest release of its window for those ranges
     * ({@see window()}), then the lowest, and the row's class is that release's class.
     *
     * @param array<string, string>               $newest
     * @param array<string, list<Candidate>>      $byBranch ascending per branch
     * @param array<string, ConstraintInterface>  $ranged
     * @param list<ConstraintInterface>           $own
     *
     * @return list<BranchFixes>
     */
    private static function branches(array $newest, array $byBranch, ?string $installedBranch, array $ranged, array $own, int $of): array
    {
        $keys = array_map('strval', array_keys($newest));
        usort($keys, static fn (string $a, string $b): int => version_compare($b, $a));
        $rows = [];
        foreach ($keys as $key) {
            $candidates = $byBranch[$key] ?? [];
            $top = $candidates === [] ? null : $candidates[\count($candidates) - 1];
            $cleared = $top === null ? [] : self::cleared($top, $ranged);
            $candidate = $cleared === [] ? null : self::easiestIn(self::window($candidates, array_intersect_key($ranged, array_flip($cleared)), $own));
            $rows[] = new BranchFixes(ReleaseBranch::label($key), $key === $installedBranch, $of - \count($ranged), $of, $candidate, $newest[$key], $cleared);
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
    private static function partial(array $onInstalled, array $ranged, int $of): ?Partial
    {
        if ($onInstalled === []) {
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
