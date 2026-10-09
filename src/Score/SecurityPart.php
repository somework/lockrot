<?php

declare(strict_types=1);

namespace Lockrot\Score;

/**
 * The security part, with the count of the counted advisories and the ones tied with the deciding one.
 *
 * @internal
 *
 * @phpstan-type Shape array{status: string, contribution: int|float, alone: array{total: int, verdict: ?string}, of: int, tied: list<string>}
 */
final class SecurityPart
{
    private Part $part;
    private int $of;
    /** @var list<string> */
    private array $tied;

    /** @param list<string> $tied the ids of the other counted advisories with the deciding one's points */
    public function __construct(Part $part, int $of, array $tied)
    {
        $this->part = $part;
        $this->of = $of;
        $this->tied = $tied;
    }

    public function isCounted(): bool
    {
        return $this->part->isCounted();
    }

    /** The counted advisories. */
    public function of(): int
    {
        return $this->of;
    }

    /** @return Shape */
    public function toArray(): array
    {
        return $this->part->toArray() + ['of' => $this->of, 'tied' => $this->tied];
    }
}
