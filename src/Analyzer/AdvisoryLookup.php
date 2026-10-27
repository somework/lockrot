<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\AdvisoryFeed;
use Lockrot\Data\Advisory\AdvisoryRepository;
use Lockrot\Verdict\Finding;

/**
 * report-2's `run.advisory_lookup`: how the run's own advisory lookup went, its packages counted
 * from the findings.
 *
 * @internal
 */
final class AdvisoryLookup implements \JsonSerializable
{
    private AdvisoryCoverage $coverage;
    private int $asked;
    private int $notAsked;

    private function __construct(AdvisoryCoverage $coverage, int $asked, int $notAsked)
    {
        $this->coverage = $coverage;
        $this->asked = $asked;
        $this->notAsked = $notAsked;
    }

    /**
     * A finding is asked when a feed answered or failed for its name.
     *
     * @param list<Finding> $findings
     */
    public static function of(AdvisoryCoverage $coverage, array $findings): self
    {
        $asked = 0;
        foreach ($findings as $finding) {
            $lookup = $finding->details()->advisoryLookup();
            $feeds = $lookup === null ? [] : $lookup->feeds();
            $asked += array_filter($feeds, static fn (AdvisoryFeed $feed): bool => $feed->answer() !== AdvisoryCoverage::NOT_ASKED) === [] ? 0 : 1;
        }

        return new self($coverage, $asked, \count($findings) - $asked);
    }

    public function packagesAsked(): int
    {
        return $this->asked;
    }

    public function packagesNotAsked(): int
    {
        return $this->notAsked;
    }

    /** `all`, or `composer-repositories` under `advisory-lookup: composer-repositories`. */
    public function scope(): string
    {
        return $this->coverage->scope();
    }

    /** @return list<AdvisoryRepository> */
    public function repositories(): array
    {
        return $this->coverage->repositories();
    }

    public function otherRepositories(): int
    {
        return $this->coverage->otherRepositories();
    }

    /** @return array{scope: string, scope_source: string, packages_asked: int, packages_not_asked: int, other_repositories: int, repositories: list<AdvisoryRepository>} */
    public function jsonSerialize(): array
    {
        return [
            'scope' => $this->scope(),
            'scope_source' => $this->coverage->scopeSource(),
            'packages_asked' => $this->asked,
            'packages_not_asked' => $this->notAsked,
            'other_repositories' => $this->coverage->otherRepositories(),
            'repositories' => $this->coverage->repositories(),
        ];
    }
}
