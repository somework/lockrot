<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

/**
 * Decides which repositories to ask about.
 *
 * With credentials every repository is fetched. Anonymously, GitHub allows 60 requests an hour and
 * Bitbucket 60, so on those forges an anonymous run asks only about candidate packages — the ones
 * that already look stale on release age, where activity data can still change the verdict — and
 * no more than the budget. GitLab's anonymous limit is 500 a minute, so it is never capped.
 */
final class ActivityFetchPlanner
{
    public const DEFAULT_ANONYMOUS_BUDGET = 50;

    private const CAPPED_WHEN_ANONYMOUS = [RepoRef::GITHUB => true, RepoRef::GITLAB => false, RepoRef::BITBUCKET => true];

    private ForgeAuth $auth;
    private int $budget;

    public function __construct(ForgeAuth $auth, int $anonymousBudget = self::DEFAULT_ANONYMOUS_BUDGET)
    {
        $this->auth = $auth;
        $this->budget = $anonymousBudget;
    }

    /**
     * @param array<string, RepoRef> $repoByPackage      package name => repository
     * @param array<string, bool>    $candidateByPackage package name => needs activity data to decide a verdict
     */
    public function select(array $repoByPackage, array $candidateByPackage): ActivityFetchPlan
    {
        ksort($repoByPackage);
        $repos = [];
        /** @var array<string, true> $seen */
        $seen = [];
        /** @var array<string, int> $selected */
        $selected = [];
        $checkedPackages = [];
        $skippedNoToken = [];
        $skippedBudget = [];
        /** @var array<string, true> $capped */
        $capped = [];
        foreach ($repoByPackage as $package => $repo) {
            $forge = $repo->forge();
            $isCapped = self::CAPPED_WHEN_ANONYMOUS[$forge] && !$this->auth->isAuthenticated($repo);
            if ($isCapped) {
                $capped[$forge] = true;
            }
            if ($isCapped && ($candidateByPackage[$package] ?? false) !== true) {
                $skippedNoToken[$forge] = ($skippedNoToken[$forge] ?? 0) + 1;
                continue;
            }
            if (isset($seen[$repo->key()])) {
                // A repository already selected by an earlier package: this package receives the
                // same activity data without adding another request.
                $checkedPackages[$forge] = ($checkedPackages[$forge] ?? 0) + 1;
                continue;
            }
            if ($isCapped && ($selected[$forge] ?? 0) >= $this->budget) {
                $skippedBudget[$forge] = ($skippedBudget[$forge] ?? 0) + 1;
                continue;
            }
            $seen[$repo->key()] = true;
            $selected[$forge] = ($selected[$forge] ?? 0) + 1;
            $repos[] = $repo;
            $checkedPackages[$forge] = ($checkedPackages[$forge] ?? 0) + 1;
        }

        return new ActivityFetchPlan(
            $repos,
            $checkedPackages,
            $skippedNoToken,
            $skippedBudget,
            array_values(array_filter(RepoRef::FORGES, static fn (string $forge): bool => isset($capped[$forge])))
        );
    }
}
