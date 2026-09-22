<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

/**
 * What {@see ActivityFetchPlanner} decided to fetch, and what it left out and why, per forge.
 *
 * The two skip counts are kept apart because they have different remedies: packages skipped for
 * lack of credentials were never candidates for an activity-based verdict, while budget drops are
 * candidates that the anonymous request cap could not fit. The report adds them together, but the
 * split keeps the planner honest and testable.
 */
final class ActivityFetchPlan
{
    /** The run is anonymous on a capped forge and the package is not a candidate for an activity verdict. */
    public const NO_TOKEN = 'no_token';
    /** A candidate the anonymous request budget could not fit. */
    public const BUDGET = 'budget';

    /** @var list<RepoRef> */
    private array $repos;
    /** @var array<string, int> */
    private array $checkedPackages;
    /** @var array<string, int> */
    private array $skippedNoToken;
    /** @var array<string, int> */
    private array $skippedBudget;
    /** @var list<string> */
    private array $cappedForges;
    /** @var array<string, string> */
    private array $skippedPackages;

    /**
     * @param list<RepoRef>      $repos           unique repositories, ordered by package name
     * @param array<string, int> $checkedPackages forge => packages that will receive activity data
     * @param array<string, int> $skippedNoToken  forge => packages skipped as non-candidates under the anonymous cap
     * @param array<string, int> $skippedBudget   forge => candidates the anonymous budget could not fit
     * @param list<string>       $cappedForges    forges that were planned anonymously under a cap and had at least one repository
     * @param array<string, string> $skippedPackages package name => why its repository was not asked about ({@see self::NO_TOKEN}, {@see self::BUDGET})
     */
    public function __construct(array $repos, array $checkedPackages, array $skippedNoToken, array $skippedBudget, array $cappedForges, array $skippedPackages = [])
    {
        $this->repos = $repos;
        $this->checkedPackages = $checkedPackages;
        $this->skippedNoToken = $skippedNoToken;
        $this->skippedBudget = $skippedBudget;
        $this->cappedForges = $cappedForges;
        $this->skippedPackages = $skippedPackages;
    }

    /**
     * Why each package's repository was not asked about, by package name — the counts above say how
     * many, this says which, so a finding can record that the check behind it never ran
     * ({@see \Lockrot\Signal\Rule\NotCheckedRule}).
     *
     * @return array<string, string>
     */
    public function skippedPackages(): array
    {
        return $this->skippedPackages;
    }

    /** @return list<RepoRef> */
    public function repos(): array
    {
        return $this->repos;
    }

    /**
     * Packages on $forge that will receive activity data: every package whose repository was
     * selected, plus every package that shares a repository already selected by an earlier package.
     * Two packages from the same repository both count here even though repos() lists it once.
     */
    public function checkedPackages(string $forge): int
    {
        return $this->checkedPackages[$forge] ?? 0;
    }

    /** Packages on $forge skipped because the run is anonymous there and they are not candidates for an activity verdict. */
    public function skippedNoToken(string $forge): int
    {
        return $this->skippedNoToken[$forge] ?? 0;
    }

    /** Candidate packages on $forge dropped because the anonymous request budget was already spent. */
    public function skippedBudget(string $forge): int
    {
        return $this->skippedBudget[$forge] ?? 0;
    }

    /**
     * The forges whose anonymous cap shaped this plan — each had at least one repository and no
     * credentials — in {@see RepoRef::FORGES} order. The report states the cap for each.
     *
     * @return list<string>
     */
    public function cappedForges(): array
    {
        return $this->cappedForges;
    }
}
