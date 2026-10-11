<?php

declare(strict_types=1);

namespace Lockrot\Signal\Data;

/**
 * The installed branch, the newest higher branch and the branch within reach
 * (docs/verdicts.md#left-behind). Each fact of the branch within reach is null when no branch is
 * within reach.
 *
 * @internal
 */
final class LeftBehindData implements SignalData
{
    private string $branch;
    private string $branchLastRelease;
    private ?string $branchLastVersion;
    private ?float $years;
    private string $newestBranch;
    private string $newestVersion;
    private string $newestRelease;
    private ?string $newestPhp;
    private bool $newestWithinReach;
    private ?string $floorPhp;
    private ?string $floorSource;
    private ?string $reachableBranch;
    private ?string $reachableVersion;
    private ?string $reachableRelease;
    private ?string $suggestedConstraint;
    private ?string $datedBy;
    private ?string $reachablePhp;
    /** @var array{project: ?bool, target: ?bool}|null */
    private ?array $reachableAdmits;
    private float $newestYears;
    private ?float $reachableYears;

    /**
     * Each date is an ISO 8601 date and time, and each age is in years on the run clock.
     *
     * @param ?string                                   $floorSource     `project` or `target`: the floor that the newest branch's php is above, null when it is within reach
     * @param array{project: ?bool, target: ?bool}|null $reachableAdmits whether each floor admits the reachable branch's php, null for a floor lockrot could not compare
     */
    public function __construct(
        string $branch,
        string $branchLastRelease,
        ?string $branchLastVersion,
        ?float $years,
        string $newestBranch,
        string $newestVersion,
        string $newestRelease,
        ?string $newestPhp,
        bool $newestWithinReach,
        ?string $floorPhp,
        ?string $floorSource,
        ?string $reachableBranch,
        ?string $reachableVersion,
        ?string $reachableRelease,
        ?string $suggestedConstraint,
        ?string $datedBy,
        ?string $reachablePhp,
        ?array $reachableAdmits,
        float $newestYears,
        ?float $reachableYears
    ) {
        $this->branch = $branch;
        $this->branchLastRelease = $branchLastRelease;
        $this->branchLastVersion = $branchLastVersion;
        $this->years = $years;
        $this->newestBranch = $newestBranch;
        $this->newestVersion = $newestVersion;
        $this->newestRelease = $newestRelease;
        $this->newestPhp = $newestPhp;
        $this->newestWithinReach = $newestWithinReach;
        $this->floorPhp = $floorPhp;
        $this->floorSource = $floorSource;
        $this->reachableBranch = $reachableBranch;
        $this->reachableVersion = $reachableVersion;
        $this->reachableRelease = $reachableRelease;
        $this->suggestedConstraint = $suggestedConstraint;
        $this->datedBy = $datedBy;
        $this->reachablePhp = $reachablePhp;
        $this->reachableAdmits = $reachableAdmits;
        $this->newestYears = $newestYears;
        $this->reachableYears = $reachableYears;
    }

    public function branch(): string
    {
        return $this->branch;
    }

    public function branchLastRelease(): string
    {
        return $this->branchLastRelease;
    }

    public function years(): ?float
    {
        return $this->years;
    }

    public function newestBranch(): string
    {
        return $this->newestBranch;
    }

    public function newestVersion(): string
    {
        return $this->newestVersion;
    }

    public function newestRelease(): string
    {
        return $this->newestRelease;
    }

    public function newestPhp(): ?string
    {
        return $this->newestPhp;
    }

    public function newestWithinReach(): bool
    {
        return $this->newestWithinReach;
    }

    public function floorPhp(): ?string
    {
        return $this->floorPhp;
    }

    public function floorSource(): ?string
    {
        return $this->floorSource;
    }

    public function reachableBranch(): ?string
    {
        return $this->reachableBranch;
    }

    public function reachableVersion(): ?string
    {
        return $this->reachableVersion;
    }

    public function reachableRelease(): ?string
    {
        return $this->reachableRelease;
    }

    public function suggestedConstraint(): ?string
    {
        return $this->suggestedConstraint;
    }

    public function datedBy(): ?string
    {
        return $this->datedBy;
    }

    public function reachablePhp(): ?string
    {
        return $this->reachablePhp;
    }

    /** @return array{project: ?bool, target: ?bool}|null */
    public function reachableAdmits(): ?array
    {
        return $this->reachableAdmits;
    }

    public function newestYears(): float
    {
        return $this->newestYears;
    }

    public function reachableYears(): ?float
    {
        return $this->reachableYears;
    }

    /** @return array{branch: string, branch_last_release: string, branch_last_version: ?string, years: ?float, newest_branch: string, newest_version: string, newest_release: string, newest_php: ?string, newest_within_reach: bool, floor_php: ?string, floor_source: ?string, reachable_branch: ?string, reachable_version: ?string, reachable_release: ?string, suggested_constraint: ?string, dated_by: ?string, reachable_php: ?string, reachable_admits: array{project: ?bool, target: ?bool}|null, newest_years: float, reachable_years: ?float} */
    public function jsonSerialize(): array
    {
        return [
            'branch' => $this->branch,
            'branch_last_release' => $this->branchLastRelease,
            'branch_last_version' => $this->branchLastVersion,
            'years' => $this->years,
            'newest_branch' => $this->newestBranch,
            'newest_version' => $this->newestVersion,
            'newest_release' => $this->newestRelease,
            'newest_php' => $this->newestPhp,
            'newest_within_reach' => $this->newestWithinReach,
            'floor_php' => $this->floorPhp,
            'floor_source' => $this->floorSource,
            'reachable_branch' => $this->reachableBranch,
            'reachable_version' => $this->reachableVersion,
            'reachable_release' => $this->reachableRelease,
            'suggested_constraint' => $this->suggestedConstraint,
            'dated_by' => $this->datedBy,
            'reachable_php' => $this->reachablePhp,
            'reachable_admits' => $this->reachableAdmits,
            'newest_years' => $this->newestYears,
            'reachable_years' => $this->reachableYears,
        ];
    }
}
