<?php

declare(strict_types=1);

namespace Lockrot\Signal\Data;

use Lockrot\Signal\Signal;

/**
 * The installed branch, the newest higher branch and the branch within reach
 * (docs/verdicts.md#left-behind).
 *
 * @internal
 */
final class LeftBehindData implements SignalData
{
    private string $branch;
    private string $branchLastRelease;
    private ?string $branchLastVersion;
    private ?float $years;
    private ?string $datedBy;
    private string $newestBranch;
    private string $newestVersion;
    private string $newestRelease;
    private ?string $newestPhp;
    private float $newestYears;
    private bool $newestWithinReach;
    private ?string $floorPhp;
    private ?string $floorSource;
    private ?ReachableBranch $reachable;
    private bool $newestAtLeast;
    private ?string $newestMisses;

    /**
     * Each date is an ISO 8601 date and time, and each age is in years on the run clock.
     *
     * @param ?string $floorSource   `project` or `target`: the floor that the newest branch's php is above, null when it is within reach
     * @param bool    $newestAtLeast $newestVersion is the branch's highest tag, dated only by a commit its tags share: $newestRelease is a lower bound, $newestYears an upper one
     * @param ?string $newestMisses  how $newestPhp misses the floor at the PHP that S8 judged, a PhpFloor `misses_*` value. Not in the JSON: the floor label cannot rebuild a solve PHP
     */
    public function __construct(
        string $branch,
        string $branchLastRelease,
        ?string $branchLastVersion,
        ?float $years,
        ?string $datedBy,
        string $newestBranch,
        string $newestVersion,
        string $newestRelease,
        ?string $newestPhp,
        float $newestYears,
        bool $newestWithinReach,
        ?string $floorPhp,
        ?string $floorSource,
        ?ReachableBranch $reachable,
        bool $newestAtLeast = false,
        ?string $newestMisses = null
    ) {
        $this->branch = $branch;
        $this->branchLastRelease = $branchLastRelease;
        $this->branchLastVersion = $branchLastVersion;
        $this->years = $years;
        $this->datedBy = $datedBy;
        $this->newestBranch = $newestBranch;
        $this->newestVersion = $newestVersion;
        $this->newestRelease = $newestRelease;
        $this->newestPhp = $newestPhp;
        $this->newestYears = $newestYears;
        $this->newestWithinReach = $newestWithinReach;
        $this->floorPhp = $floorPhp;
        $this->floorSource = $floorSource;
        $this->reachable = $reachable;
        $this->newestAtLeast = $newestAtLeast;
        $this->newestMisses = $newestMisses;
    }

    public function signalId(): string
    {
        return Signal::S8;
    }

    public function branch(): string
    {
        return $this->branch;
    }

    public function branchLastRelease(): string
    {
        return $this->branchLastRelease;
    }

    public function branchLastVersion(): ?string
    {
        return $this->branchLastVersion;
    }

    public function years(): ?float
    {
        return $this->years;
    }

    public function datedBy(): ?string
    {
        return $this->datedBy;
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

    public function newestYears(): float
    {
        return $this->newestYears;
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

    /** Null when no branch is within reach. */
    public function reachable(): ?ReachableBranch
    {
        return $this->reachable;
    }

    /**
     * S8's words for a branch's release. A lower bound says so, because a commit that the tags
     * share dates it.
     *
     * @param string $date `Y-m-d`
     */
    public static function releasedWords(string $branch, string $version, string $date, bool $atLeast): string
    {
        return \sprintf('%s released %s (%s)', $branch, $version, $atLeast ? 'on or after '.$date.', the date of a commit its tags share' : $date);
    }

    public function isNewestAtLeast(): bool
    {
        return $this->newestAtLeast;
    }

    public function newestMisses(): ?string
    {
        return $this->newestMisses;
    }

    /** @return array{branch: string, branch_last_release: string, branch_last_version: ?string, years: ?float, newest_branch: string, newest_version: string, newest_release: string, newest_php: ?string, newest_within_reach: bool, floor_php: ?string, floor_source: ?string, reachable_branch: ?string, reachable_version: ?string, reachable_release: ?string, suggested_constraint: ?string, dated_by: ?string, reachable_php: ?string, reachable_admits: array{project: ?bool, target: ?bool}|null, newest_years: float, reachable_years: ?float, newest_release_at_least: bool, reachable_release_at_least: ?bool} */
    public function jsonSerialize(): array
    {
        $reachable = $this->reachable;

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
            'reachable_branch' => $reachable === null ? null : $reachable->branch(),
            'reachable_version' => $reachable === null ? null : $reachable->version(),
            'reachable_release' => $reachable === null ? null : $reachable->release(),
            'suggested_constraint' => $reachable === null ? null : $reachable->suggestedConstraint(),
            'dated_by' => $this->datedBy,
            'reachable_php' => $reachable === null ? null : $reachable->php(),
            'reachable_admits' => $reachable === null ? null : $reachable->admits(),
            'newest_years' => $this->newestYears,
            'reachable_years' => $reachable === null ? null : $reachable->years(),
            'newest_release_at_least' => $this->newestAtLeast,
            'reachable_release_at_least' => $reachable === null ? null : $reachable->isAtLeast(),
        ];
    }
}
