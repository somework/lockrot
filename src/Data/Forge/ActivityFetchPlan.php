<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

/** @internal */
final class ActivityFetchPlan
{
    /** Anonymous run on a capped repository host, and no candidate for an activity verdict. */
    public const NO_TOKEN = 'no_token';
    /** A candidate that the anonymous request budget could not fit. */
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
     * @param array<string, int> $checkedPackages host => packages that will receive activity data
     * @param array<string, int> $skippedNoToken  host => packages skipped as non-candidates under the anonymous cap
     * @param array<string, int> $skippedBudget   host => candidates that the anonymous budget could not fit
     * @param list<string>       $cappedForges    hosts planned anonymously under a cap with at least one repository
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
     * Names the skipped packages, so that a finding can record that its check never ran
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

    /** Counts packages, not repositories: two packages of one repository count twice. */
    public function checkedPackages(string $forge): int
    {
        return $this->checkedPackages[$forge] ?? 0;
    }

    public function skippedNoToken(string $forge): int
    {
        return $this->skippedNoToken[$forge] ?? 0;
    }

    public function skippedBudget(string $forge): int
    {
        return $this->skippedBudget[$forge] ?? 0;
    }

    /**
     * The capped hosts that had at least one repository and no credentials, in
     * {@see RepoRef::FORGES} order.
     *
     * @return list<string>
     */
    public function cappedForges(): array
    {
        return $this->cappedForges;
    }
}
