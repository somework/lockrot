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
    private int $skippedNoToken;
    private int $skippedBudget;

    /** @param list<string> $repos unique repos, ordered by package name */
    public function __construct(array $repos, int $skippedNoToken, int $skippedBudget)
    {
        $this->repos = $repos;
        $this->skippedNoToken = $skippedNoToken;
        $this->skippedBudget = $skippedBudget;
    }

    /** @return list<string> */
    public function repos(): array
    {
        return $this->repos;
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
