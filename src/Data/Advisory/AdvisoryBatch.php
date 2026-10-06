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

    /**
     * @param array<string, list<Advisory>> $byName package name => the advisories affecting its installed version
     * @param list<RunNote>                 $notes  why the answer is incomplete
     */
    public function __construct(array $byName, array $notes = [])
    {
        $this->byName = $byName;
        $this->notes = $notes;
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

    /** @return list<RunNote> */
    public function notes(): array
    {
        return $this->notes;
    }
}
