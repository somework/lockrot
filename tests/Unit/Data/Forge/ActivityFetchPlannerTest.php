<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Forge;

use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Data\Forge\Tokens;
use PHPUnit\Framework\TestCase;

final class ActivityFetchPlannerTest extends TestCase
{
    private static function gh(string $path): RepoRef
    {
        return new RepoRef(RepoRef::GITHUB, 'github.com', $path);
    }

    private static function gl(string $path): RepoRef
    {
        return new RepoRef(RepoRef::GITLAB, 'gitlab.com', $path);
    }

    private static function bb(string $path): RepoRef
    {
        return new RepoRef(RepoRef::BITBUCKET, 'bitbucket.org', $path);
    }

    /**
     * @param list<RepoRef> $repos
     *
     * @return list<string>
     */
    private static function keys(array $repos): array
    {
        return array_map(static fn (RepoRef $repo): string => $repo->key(), $repos);
    }

    public function testWithATokenEveryGithubRepositoryIsFetchedInPackageOrder(): void
    {
        $planner = new ActivityFetchPlanner(ForgeAuth::withTokens(new Tokens('t', null)));
        $plan = $planner->select(['b/b' => self::gh('o/b'), 'a/a' => self::gh('o/a')], ['a/a' => false, 'b/b' => false]);
        self::assertSame(['github.com/o/a', 'github.com/o/b'], self::keys($plan->repos()));
        self::assertSame(0, $plan->skippedNoToken(RepoRef::GITHUB));
        self::assertSame(0, $plan->skippedBudget(RepoRef::GITHUB));
        self::assertSame(2, $plan->checkedPackages(RepoRef::GITHUB));
        self::assertSame([], $plan->cappedForges());
    }

    public function testWithoutATokenGithubFetchesOnlyCandidatesWithinTheBudget(): void
    {
        $planner = new ActivityFetchPlanner(ForgeAuth::anonymous(), 1);
        $plan = $planner->select(['a/a' => self::gh('o/a'), 'b/b' => self::gh('o/b'), 'c/c' => self::gh('o/c')], ['a/a' => true, 'b/b' => true, 'c/c' => false]);
        self::assertSame(['github.com/o/a'], self::keys($plan->repos()));
        self::assertSame(1, $plan->skippedBudget(RepoRef::GITHUB), 'b/b is a candidate but the budget was spent');
        self::assertSame(1, $plan->skippedNoToken(RepoRef::GITHUB), 'c/c is not a candidate and there is no token');
        self::assertSame(1, $plan->checkedPackages(RepoRef::GITHUB), 'only a/a actually receives activity data');
        self::assertSame([RepoRef::GITHUB], $plan->cappedForges());
    }

    public function testWithoutATokenAndNoCandidatesNothingIsFetchedAndEveryPackageIsCounted(): void
    {
        $plan = (new ActivityFetchPlanner(ForgeAuth::anonymous()))->select(['a/a' => self::gh('o/a'), 'b/b' => self::gh('o/b')], []);
        self::assertSame([], $plan->repos());
        self::assertSame(2, $plan->skippedNoToken(RepoRef::GITHUB));
        self::assertSame(0, $plan->skippedBudget(RepoRef::GITHUB));
        self::assertSame([RepoRef::GITHUB], $plan->cappedForges(), 'the cap shaped the plan even though nothing was a candidate');
    }

    public function testDeduplicatesRepositoriesAndCountsEveryPackageThatSharesOne(): void
    {
        $plan = (new ActivityFetchPlanner(ForgeAuth::withTokens(new Tokens('t', null))))->select(['a/a' => self::gh('o/mono'), 'a/b' => self::gh('o/mono')], []);
        self::assertSame(['github.com/o/mono'], self::keys($plan->repos()));
        self::assertSame(2, $plan->checkedPackages(RepoRef::GITHUB), 'both packages receive the shared repository\'s activity data');
    }

    /** GitLab's anonymous limit (500 a minute) needs no cap: everything is fetched, candidate or not. */
    public function testGitlabIsNeverCappedAnonymously(): void
    {
        $repos = [];
        $candidates = [];
        for ($i = 0; $i < 60; ++$i) {
            $repos['v/p'.$i] = self::gl('g/p'.$i);
            $candidates['v/p'.$i] = false;
        }
        $plan = (new ActivityFetchPlanner(ForgeAuth::anonymous(), 1))->select($repos, $candidates);
        self::assertCount(60, $plan->repos());
        self::assertSame(60, $plan->checkedPackages(RepoRef::GITLAB));
        self::assertSame(0, $plan->skippedNoToken(RepoRef::GITLAB));
        self::assertSame(0, $plan->skippedBudget(RepoRef::GITLAB));
        self::assertSame([], $plan->cappedForges());
    }

    /** Bitbucket allows 60 anonymous requests an hour, so it is capped like GitHub until Composer has credentials for it. */
    public function testBitbucketIsCappedAnonymouslyAndFreeWithComposerCredentials(): void
    {
        $repos = ['a/a' => self::bb('w/a'), 'b/b' => self::bb('w/b'), 'c/c' => self::bb('w/c')];
        $candidates = ['a/a' => true, 'b/b' => true, 'c/c' => false];

        $plan = (new ActivityFetchPlanner(ForgeAuth::anonymous(), 1))->select($repos, $candidates);
        self::assertSame(['bitbucket.org/w/a'], self::keys($plan->repos()));
        self::assertSame(1, $plan->skippedBudget(RepoRef::BITBUCKET));
        self::assertSame(1, $plan->skippedNoToken(RepoRef::BITBUCKET));
        self::assertSame([RepoRef::BITBUCKET], $plan->cappedForges());

        $withCredentials = new ForgeAuth(Tokens::none(), static fn (string $host): bool => $host === 'bitbucket.org');
        $plan = (new ActivityFetchPlanner($withCredentials, 1))->select($repos, $candidates);
        self::assertCount(3, $plan->repos());
        self::assertSame([], $plan->cappedForges());
    }

    /** Each forge keeps its own budget and its own counts; the capped list follows the forge order. */
    public function testBudgetsAndCountsAreKeptPerForge(): void
    {
        $plan = (new ActivityFetchPlanner(ForgeAuth::anonymous(), 1))->select(
            ['a/bb' => self::bb('w/a'), 'b/gh' => self::gh('o/b'), 'c/gl' => self::gl('g/c'), 'd/gh' => self::gh('o/d'), 'e/bb' => self::bb('w/e')],
            ['a/bb' => true, 'b/gh' => true, 'c/gl' => false, 'd/gh' => true, 'e/bb' => true]
        );
        self::assertSame(['bitbucket.org/w/a', 'github.com/o/b', 'gitlab.com/g/c'], self::keys($plan->repos()));
        self::assertSame(1, $plan->skippedBudget(RepoRef::GITHUB));
        self::assertSame(1, $plan->skippedBudget(RepoRef::BITBUCKET));
        self::assertSame(0, $plan->skippedBudget(RepoRef::GITLAB));
        self::assertSame([RepoRef::GITHUB, RepoRef::BITBUCKET], $plan->cappedForges());
        self::assertSame(0, $plan->checkedPackages('nothing'), 'an unknown forge simply counts zero');
    }
}
