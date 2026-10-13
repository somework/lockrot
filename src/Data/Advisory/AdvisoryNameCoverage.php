<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

/**
 * What the advisory lookup did for one package name: one feed entry per advisory-capable
 * repository that has a feed and was reached, the records the feeds hold for the name, and why
 * the lookup is not complete for it.
 *
 * @internal
 */
final class AdvisoryNameCoverage
{
    /** @var list<AdvisoryFeed> */
    private array $feeds;
    private ?int $records;
    private ?string $reason;

    /** @param list<AdvisoryFeed> $feeds in configured order */
    public function __construct(array $feeds, ?int $records, ?string $reason)
    {
        $this->feeds = $feeds;
        $this->records = $records;
        $this->reason = $reason;
    }

    /** @return list<AdvisoryFeed> */
    public function feeds(): array
    {
        return $this->feeds;
    }

    /** The distinct advisory ids that every answering feed returned for the name. Null when no feed answered. */
    public function records(): ?int
    {
        return $this->records;
    }

    /** One of the `AdvisoryCoverage` reasons when the lookup is not complete for the name, else null. */
    public function reason(): ?string
    {
        return $this->reason;
    }
}
