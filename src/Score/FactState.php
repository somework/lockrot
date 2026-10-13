<?php

declare(strict_types=1);

namespace Lockrot\Score;

/**
 * Where one counted fact stands against the baseline entry: a flag's `flags[].baseline` or an S9
 * row's `baseline`.
 *
 * @internal
 *
 * @phpstan-type Shape array{state: string, since: ?string, covered_by: ?string}
 */
final class FactState implements \JsonSerializable
{
    public const KNOWN = 'known';
    public const COVERED = 'covered';
    public const NEW_FACT = 'new';
    public const RE_RATED = 're_rated';
    public const FIX_LOST = 'fix_lost';

    private string $state;
    private ?string $since;
    private ?string $coveredBy;

    /**
     * @param ?string $since     the `known_since` of the recorded fact, null for a new fact
     * @param ?string $coveredBy the recorded lead that covers a flag the entry does not list
     */
    public function __construct(string $state, ?string $since, ?string $coveredBy)
    {
        $this->state = $state;
        $this->since = $since;
        $this->coveredBy = $coveredBy;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function since(): ?string
    {
        return $this->since;
    }

    public function coveredBy(): ?string
    {
        return $this->coveredBy;
    }

    /** @return Shape */
    public function jsonSerialize(): array
    {
        return ['state' => $this->state, 'since' => $this->since, 'covered_by' => $this->coveredBy];
    }
}
