<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

/** What one {@see AdvisoryLoaderInterface::load()} call found, and what it could not do. */
final class AdvisoryBatch
{
    /** @var array<string, list<Advisory>> */
    private array $byName;
    /** @var list<string> */
    private array $notes;
    private bool $hadNetworkFailure;

    /**
     * @param array<string, list<Advisory>> $byName            package name => the advisories affecting its installed version
     * @param list<string>                  $notes             report notes: why the answer is incomplete, each a full sentence
     * @param bool                          $hadNetworkFailure a repository could not be reached; what `--strict-network` fails on
     */
    public function __construct(array $byName, array $notes = [], bool $hadNetworkFailure = false)
    {
        $this->byName = $byName;
        $this->notes = $notes;
        $this->hadNetworkFailure = $hadNetworkFailure;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /** No lookup at all, for the one reason given. */
    public static function unavailable(string $note): self
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

    /** @return list<string> */
    public function notes(): array
    {
        return $this->notes;
    }

    public function hadNetworkFailure(): bool
    {
        return $this->hadNetworkFailure;
    }
}
