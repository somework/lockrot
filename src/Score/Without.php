<?php

declare(strict_types=1);

namespace Lockrot\Score;

/**
 * The engine rerun without one counted flag or without the deciding advisory.
 *
 * @internal
 *
 * @phpstan-type Shape array{remove: array{kind: string, id: string}, revealed: list<array{flag: string, role: string}>, total: int, verdict: string, lead: ?string, deciding_advisory: ?string, at_least: bool}
 */
final class Without
{
    private string $kind;
    private string $id;
    /** @var list<array{flag: string, role: string}> */
    private array $revealed;
    private int $total;
    private string $verdict;
    private ?string $lead;
    private ?string $decidingAdvisory;
    private bool $atLeast;

    /**
     * @param string                                  $kind     `flag` or `advisory`
     * @param list<array{flag: string, role: string}> $revealed the words that the removal restores, with their role in the rerun
     */
    public function __construct(string $kind, string $id, array $revealed, int $total, string $verdict, ?string $lead, ?string $decidingAdvisory, bool $atLeast)
    {
        $this->kind = $kind;
        $this->id = $id;
        $this->revealed = $revealed;
        $this->total = $total;
        $this->verdict = $verdict;
        $this->lead = $lead;
        $this->decidingAdvisory = $decidingAdvisory;
        $this->atLeast = $atLeast;
    }

    /** @return Shape */
    public function toArray(): array
    {
        return [
            'remove' => ['kind' => $this->kind, 'id' => $this->id],
            'revealed' => $this->revealed,
            'total' => $this->total,
            'verdict' => $this->verdict,
            'lead' => $this->lead,
            'deciding_advisory' => $this->decidingAdvisory,
            'at_least' => $this->atLeast,
        ];
    }
}
