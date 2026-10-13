<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\AdvisoryFeed;
use Lockrot\Data\Advisory\AdvisoryRepository;
use Lockrot\Verdict\Finding;

/**
 * report-2's `run.advisory_lookup`: how the one advisory lookup of the run went, its packages counted
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

    /** @return array{scope: string, scope_source: string, packages_asked: int, packages_not_asked: int, other_repositories: int, repositories: list<AdvisoryRepository>} */
    public function jsonSerialize(): array
    {
        return [
            'scope' => $this->coverage->scope(),
            'scope_source' => $this->coverage->scopeSource(),
            'packages_asked' => $this->asked,
            'packages_not_asked' => $this->notAsked,
            'other_repositories' => $this->coverage->otherRepositories(),
            'repositories' => $this->coverage->repositories(),
        ];
    }
}
