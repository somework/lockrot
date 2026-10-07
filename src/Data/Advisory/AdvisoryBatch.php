<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

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
