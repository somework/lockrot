<?php

declare(strict_types=1);

namespace Lockrot\Signal;

use Lockrot\Clock;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Data\Repository\StableRelease;
use Lockrot\Security\BranchFixes;
use Lockrot\Security\PackageFixes;
use Lockrot\Signal\Data\LeftBehindData;

/**
 * One release branch of a package as the details block shows it: its newest
 * releases, its php with each floor's answer, the age of its newest release and what a move onto
 * it fixes. The floor answers are {@see PhpFloor}'s, so S8 and the rows read one point.
 *
 * @internal
 */
final class BranchRow
{
    private string $branch;
    private bool $installed;
    private string $highest;
    private ?\DateTimeImmutable $highestReleased;
    private ?\DateTimeImmutable $highestCommitDate;
    private string $newestDated;
    private ?\DateTimeImmutable $newestDatedReleased;
    private ?string $datedBy;
    private ?string $php;
    private ?string $highestPhp;
    private ?bool $admitsTargetPhp;
    private ?bool $admitsProjectPhp;
    private ?string $phpBlockedBy;
    private ?string $missesTargetPhp;
    private ?string $missesProjectPhp;
    private ?bool $highestAdmitsTargetPhp;
    private ?bool $highestAdmitsProjectPhp;
    private ?string $highestMissesProjectPhp;
    private ?float $releasedYears;
    private ?BranchFixes $fixes;

    private function __construct()
    {
    }

    /**
     * Highest branch first, the keys ordered as the versions they are (`10` above `9`, `0.3` above
     * `0.0.3`). Empty when lockrot did not read the package's metadata.
     *
     * @param ?LeftBehindData $s8 the package's S8: it dates a branch whose highest tag has no trusted date
     * @param ?PackageFixes $fixes the release scan of the package, null when it has no counted advisory
     *
     * @return list<self>
     */
    public static function all(PackageFacts $facts, PhpFloor $floor, Clock $clock, ?LeftBehindData $s8 = null, ?PackageFixes $fixes = null): array
    {
        $metadata = $facts->metadata();
        if ($metadata === null) {
            return [];
        }
        $installed = ReleaseBranch::of($facts->package()->version());
        $byBranch = $metadata->latestStableByBranch();
        $keys = array_map('strval', array_keys($byBranch));
        usort($keys, static fn (string $a, string $b): int => version_compare($b, $a));
        $fixesByBranch = [];
        foreach ($fixes === null ? [] : $fixes->branches() as $branchFixes) {
            $fixesByBranch[$branchFixes->branch()] = $branchFixes;
        }
        $rows = [];
        foreach ($keys as $key) {
            $release = $byBranch[$key];
            $row = new self();
            $row->branch = ReleaseBranch::label($key);
            $row->installed = $key === $installed;
            $row->highest = $release['highest']['pretty'];
            $row->highestReleased = $release['highest']['at'];
            $sharedCommit = $release['highest']['at'] === null && $release['version'] === $release['highest']['pretty'];
            $row->highestCommitDate = $sharedCommit ? $release['at'] : null;
            $row->newestDated = $release['version'];
            $row->newestDatedReleased = $release['at'];
            $row->datedBy = $release['dated_by'] ?? null;
            $row->php = $release['php'] ?? null;
            $row->highestPhp = $release['highest']['php'];
            $row->admitsTargetPhp = $floor->admitsTarget($row->php);
            $row->admitsProjectPhp = $floor->admitsProject($row->php);
            $row->phpBlockedBy = $floor->blocking($row->php);
            $row->missesTargetPhp = $floor->missesTarget($row->php);
            $row->missesProjectPhp = $floor->missesProject($row->php);
            $row->highestAdmitsTargetPhp = $floor->admitsTarget($row->highestPhp);
            $row->highestAdmitsProjectPhp = $floor->admitsProject($row->highestPhp);
            $row->highestMissesProjectPhp = $floor->missesProject($row->highestPhp);
            $released = $row->highestReleased;
            if ($released === null && $s8 !== null && $s8->newestBranch() === $row->branch) {
                $released = new \DateTimeImmutable($s8->newestRelease());
            }
            $row->releasedYears = $released === null ? null : $clock->tenthsSince($released) / 10;
            $row->fixes = $fixesByBranch[$row->branch] ?? null;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The row of one release, as its branch's newest: what a move to S11's `admits_newest` reads.
     * It has no age and no fixes.
     */
    public static function ofRelease(StableRelease $release, bool $installed, PhpFloor $floor): ?self
    {
        $key = ReleaseBranch::of($release->normalized());
        if ($key === null) {
            return null;
        }
        $php = $release->php();
        $row = new self();
        $row->branch = ReleaseBranch::label($key);
        $row->installed = $installed;
        $row->highest = $release->pretty();
        $row->highestReleased = $release->at();
        $row->highestCommitDate = null;
        $row->newestDated = $release->pretty();
        $row->newestDatedReleased = $release->at();
        $row->datedBy = null;
        $row->php = $php;
        $row->highestPhp = $php;
        $row->admitsTargetPhp = $floor->admitsTarget($php);
        $row->admitsProjectPhp = $floor->admitsProject($php);
        $row->phpBlockedBy = $floor->blocking($php);
        $row->missesTargetPhp = $floor->missesTarget($php);
        $row->missesProjectPhp = $floor->missesProject($php);
        $row->highestAdmitsTargetPhp = $row->admitsTargetPhp;
        $row->highestAdmitsProjectPhp = $row->admitsProjectPhp;
        $row->highestMissesProjectPhp = $row->missesProjectPhp;
        $row->releasedYears = null;
        $row->fixes = null;

        return $row;
    }

    /** `ReleaseBranch::label()`, `2.x`. */
    public function branch(): string
    {
        return $this->branch;
    }

    public function installed(): bool
    {
        return $this->installed;
    }

    public function highest(): string
    {
        return $this->highest;
    }

    public function highestReleased(): ?\DateTimeImmutable
    {
        return $this->highestReleased;
    }

    /** The commit date of a highest tag that shares its commit with other tags, else null: "no date" apart from "a date that is not the release's". */
    public function highestCommitDate(): ?\DateTimeImmutable
    {
        return $this->highestCommitDate;
    }

    public function newestDated(): string
    {
        return $this->newestDated;
    }

    public function newestDatedReleased(): ?\DateTimeImmutable
    {
        return $this->newestDatedReleased;
    }

    public function datedBy(): ?string
    {
        return $this->datedBy;
    }

    /** The php of the branch's highest tag, null when it declares none. */
    public function highestPhp(): ?string
    {
        return $this->highestPhp;
    }

    /** The php of the branch's newest dated release, null when it declares none. */
    public function php(): ?string
    {
        return $this->php;
    }

    public function admitsTargetPhp(): ?bool
    {
        return $this->admitsTargetPhp;
    }

    public function admitsProjectPhp(): ?bool
    {
        return $this->admitsProjectPhp;
    }

    public function phpBlockedBy(): ?string
    {
        return $this->phpBlockedBy;
    }

    public function missesTargetPhp(): ?string
    {
        return $this->missesTargetPhp;
    }

    public function missesProjectPhp(): ?string
    {
        return $this->missesProjectPhp;
    }

    /** The floor answers on {@see self::highestPhp()}, which the Release branches line prints. */
    public function highestAdmitsTargetPhp(): ?bool
    {
        return $this->highestAdmitsTargetPhp;
    }

    public function highestAdmitsProjectPhp(): ?bool
    {
        return $this->highestAdmitsProjectPhp;
    }

    public function highestMissesProjectPhp(): ?string
    {
        return $this->highestMissesProjectPhp;
    }

    /** The age of the branch's newest release on the run clock, to one decimal. Null with no date for the branch. */
    public function releasedYears(): ?float
    {
        return $this->releasedYears;
    }

    public function fixes(): ?BranchFixes
    {
        return $this->fixes;
    }
}
