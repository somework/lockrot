<?php

declare(strict_types=1);

namespace Lockrot\Data\GitHub;

/**
 * What GitHubFetchPlanner decided to fetch, and what it left out and why.
 *
 * The two skip counts are kept apart because they have different remedies: packages skipped for
 * lack of a token were never candidates for an activity-based verdict, while budget drops are
 * candidates that the no-token request cap could not fit. The report adds them together, but the
 * split keeps the planner honest and testable.
 */
final class GitHubFetchPlan
{
    /** @var list<string> */
    private array $repos;
    private int $checkedPackages;
    private int $skippedNoToken;
    private int $skippedBudget;

    /** @param list<string> $repos unique repos, ordered by package name */
    public function __construct(array $repos, int $checkedPackages, int $skippedNoToken, int $skippedBudget)
    {
        $this->repos = $repos;
        $this->checkedPackages = $checkedPackages;
        $this->skippedNoToken = $skippedNoToken;
        $this->skippedBudget = $skippedBudget;
    }

    /** @return list<string> */
    public function repos(): array
    {
        return $this->repos;
    }

    /**
     * Packages that will receive activity data: every package whose repository was selected, plus
     * every package that shares a repository already selected by an earlier package. Two packages
     * from the same repository both count here even though repos() lists that repository once.
     */
    public function checkedPackages(): int
    {
        return $this->checkedPackages;
    }

    /** Packages skipped because no token is set and they are not candidates for an activity verdict. */
    public function skippedNoToken(): int
    {
        return $this->skippedNoToken;
    }

    /** Candidate packages dropped because the no-token request budget was already spent. */
    public function skippedBudget(): int
    {
        return $this->skippedBudget;
    }
}
