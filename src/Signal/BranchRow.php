<?php

declare(strict_types=1);

namespace Lockrot\Signal;

use Lockrot\Clock;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Security\BranchFixes;
use Lockrot\Security\PackageFixes;

/**
 * One release branch of a package as the details block shows it (SPEC-0.14 7.6.2): its newest
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
    private ?bool $admitsTargetPhp;
    private ?bool $admitsProjectPhp;
    private ?string $phpBlockedBy;
    private ?string $missesTargetPhp;
    private ?string $missesProjectPhp;
    private ?float $releasedYears;
    private ?BranchFixes $fixes;

    private function __construct()
    {
    }

    /**
     * Highest branch first, the keys ordered as the versions they are (`10` above `9`, `0.3` above
     * `0.0.3`). Empty when the package's metadata was not read.
     *
     * @param ?Signal       $s8    the package's S8: it dates a branch whose highest tag has no trusted date
     * @param ?PackageFixes $fixes the release scan of the package, null when it has no counted advisory
     *
     * @return list<self>
     */
    public static function all(PackageFacts $facts, PhpFloor $floor, Clock $clock, ?Signal $s8 = null, ?PackageFixes $fixes = null): array
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
        $s8Data = $s8 === null ? [] : $s8->data();
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
            $row->admitsTargetPhp = $floor->admitsTarget($row->php);
            $row->admitsProjectPhp = $floor->admitsProject($row->php);
            $row->phpBlockedBy = $floor->blocking($row->php);
            $row->missesTargetPhp = $floor->missesTarget($row->php);
            $row->missesProjectPhp = $floor->missesProject($row->php);
            $released = $row->highestReleased;
            if ($released === null && ($s8Data['newest_branch'] ?? null) === $row->branch && \is_string($s8Data['newest_release'] ?? null)) {
                $released = new \DateTimeImmutable($s8Data['newest_release']);
            }
            $row->releasedYears = $released === null ? null : $clock->tenthsSince($released) / 10;
            $row->fixes = $fixesByBranch[$row->branch] ?? null;
            $rows[] = $row;
        }

        return $rows;
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

    /** The age of the branch's newest release on the run clock, one decimal; null with no date for the branch. */
    public function releasedYears(): ?float
    {
        return $this->releasedYears;
    }

    public function fixes(): ?BranchFixes
    {
        return $this->fixes;
    }
}
