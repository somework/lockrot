<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

use Composer\Advisory\PartialSecurityAdvisory;
use Lockrot\Analyzer\RunNote;

/** @internal */
final class AdvisoryBatch
{
    /** @var array<string, list<Advisory>> */
    private array $byName;
    /** @var list<RunNote> */
    private array $notes;
    /** @var array<string, list<IgnoredAdvisory>> */
    private array $ignored;
    /** @var array<string, list<Advisory>> */
    private array $every;
    private AdvisoryCoverage $coverage;
    private bool $complete;
    /** @var array<string, list<PartialSecurityAdvisory>> */
    private array $records = [];
    /** @var list<string> */
    private array $asked = [];

    /**
     * @param array<string, list<Advisory>>        $byName  package name => the counted advisories that affect its installed version
     * @param list<RunNote>                        $notes   why the answer is incomplete
     * @param array<string, list<IgnoredAdvisory>> $ignored package name => the ignored advisories that affect its installed version
     * @param array<string, list<Advisory>>        $every   package name => every advisory of every version, without the ignored ones
     */
    public function __construct(array $byName, array $notes = [], array $ignored = [], array $every = [], ?AdvisoryCoverage $coverage = null, bool $complete = false)
    {
        $this->byName = $byName;
        $this->notes = $notes;
        $this->ignored = $ignored;
        $this->every = $every;
        $this->coverage = $coverage ?? AdvisoryCoverage::none();
        $this->complete = $complete;
    }

    /**
     * A copy with the lookup's records as Composer returned them, for the move check's blocking.
     *
     * @param array<string, list<PartialSecurityAdvisory>> $records every record of each name, ignored ones included
     * @param list<string>                                 $asked   the names that the lookup asked
     */
    public function withRecords(array $records, array $asked): self
    {
        $clone = clone $this;
        $clone->records = $records;
        $clone->asked = $asked;

        return $clone;
    }

    /** @return array<string, list<PartialSecurityAdvisory>> */
    public function records(): array
    {
        return $this->records;
    }

    /** @return list<string> */
    public function asked(): array
    {
        return $this->asked;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public static function unavailable(RunNote $note): self
    {
        return new self([], [$note]);
    }

    /** @return list<Advisory> */
    public function for(string $name): array
    {
        return $this->byName[$name] ?? [];
    }

    /** @return array<string, list<Advisory>> */
    public function byName(): array
    {
        return $this->byName;
    }

    /** @return list<IgnoredAdvisory> */
    public function ignored(string $name): array
    {
        return $this->ignored[$name] ?? [];
    }

    /** @return list<Advisory> every advisory the feeds hold for the name, for every version, without the ignored ones */
    public function every(string $name): array
    {
        return $this->every[$name] ?? [];
    }

    public function coverage(): AdvisoryCoverage
    {
        return $this->coverage;
    }

    /** Whether every advisory-capable repository with a feed answered, and at least one did. */
    public function complete(): bool
    {
        return $this->complete;
    }

    /** @return list<RunNote> */
    public function notes(): array
    {
        return $this->notes;
    }
}
