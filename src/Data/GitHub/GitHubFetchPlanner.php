<?php

declare(strict_types=1);

namespace Lockrot\Data\GitHub;

final class GitHubFetchPlanner
{
    public const DEFAULT_NO_TOKEN_BUDGET = 50;

    private bool $hasToken;
    private int $budget;

    public function __construct(bool $hasToken, int $noTokenBudget = self::DEFAULT_NO_TOKEN_BUDGET)
    {
        $this->hasToken = $hasToken;
        $this->budget = $noTokenBudget;
    }

    /**
     * @param array<string, string> $repoByPackage      package name => owner/repo
     * @param array<string, bool>   $candidateByPackage package name => needs activity data to decide a verdict
     */
    public function select(array $repoByPackage, array $candidateByPackage): GitHubFetchPlan
    {
        ksort($repoByPackage);
        $repos = [];
        /** @var array<string, true> $seen */
        $seen = [];
        $checkedPackages = 0;
        $skippedNoToken = 0;
        $skippedBudget = 0;
        foreach ($repoByPackage as $package => $repo) {
            if (!$this->hasToken && ($candidateByPackage[$package] ?? false) !== true) {
                ++$skippedNoToken;
                continue;
            }
            if (isset($seen[$repo])) {
                // A repository already selected by an earlier package: this package receives the
                // same activity data without adding another request.
                ++$checkedPackages;
                continue;
            }
            if (!$this->hasToken && \count($repos) >= $this->budget) {
                ++$skippedBudget;
                continue;
            }
            $seen[$repo] = true;
            $repos[] = $repo;
            ++$checkedPackages;
        }

        return new GitHubFetchPlan($repos, $checkedPackages, $skippedNoToken, $skippedBudget);
    }
}
