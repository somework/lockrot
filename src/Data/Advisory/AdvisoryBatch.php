<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

use Lockrot\Analyzer\RunNote;

/**
 * What one {@see AdvisoryLoaderInterface::load()} call found, and what it could not do.
 *
 * @internal
 */
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

    /** No lookup at all, for the one reason given. */
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

    /** A repository could not be reached: what `--strict-network` fails on, as the notes say. */
    public function hadNetworkFailure(): bool
    {
        foreach ($this->notes as $note) {
            if ($note->setsNetworkFailures()) {
                return true;
            }
        }

        return false;
    }
}
