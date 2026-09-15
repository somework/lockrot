<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\GitHub;

use Lockrot\Data\GitHub\GitHubFetchPlanner;
use PHPUnit\Framework\TestCase;

final class GitHubFetchPlannerTest extends TestCase
{
    public function testWithTokenFetchesAllRepos(): void
    {
        $planner = new GitHubFetchPlanner(true);
        $plan = $planner->select(['b/b' => 'o/b', 'a/a' => 'o/a'], ['a/a' => false, 'b/b' => false]);
        self::assertSame(['o/a', 'o/b'], $plan->repos());
        self::assertSame(0, $plan->skippedNoToken());
        self::assertSame(0, $plan->skippedBudget());
    }

    public function testWithoutTokenOnlyCandidatesWithinBudget(): void
    {
        $planner = new GitHubFetchPlanner(false, 1);
        $plan = $planner->select(['a/a' => 'o/a', 'b/b' => 'o/b', 'c/c' => 'o/c'], ['a/a' => true, 'b/b' => true, 'c/c' => false]);
        self::assertSame(['o/a'], $plan->repos());
        self::assertSame(1, $plan->skippedBudget(), 'b/b is a candidate but the budget was spent');
        self::assertSame(1, $plan->skippedNoToken(), 'c/c is not a candidate and there is no token');
        self::assertSame(1, $plan->checkedPackages(), 'only a/a actually receives activity data');
    }

    public function testWithoutTokenAndNoCandidatesFetchesNothingAndCountsEveryPackage(): void
    {
        $planner = new GitHubFetchPlanner(false);
        $plan = $planner->select(['a/a' => 'o/a', 'b/b' => 'o/b'], []);
        self::assertSame([], $plan->repos());
        self::assertSame(2, $plan->skippedNoToken());
        self::assertSame(0, $plan->skippedBudget());
    }

    public function testDeduplicatesRepos(): void
    {
        $planner = new GitHubFetchPlanner(true);
        $plan = $planner->select(['a/a' => 'o/mono', 'a/b' => 'o/mono'], []);
        self::assertSame(['o/mono'], $plan->repos());
        self::assertSame(2, $plan->checkedPackages(), 'both packages receive the shared repository\'s activity data');
    }
}
