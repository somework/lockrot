<?php

declare(strict_types=1);

namespace Lockrot\Score;

/**
 * An accepted flag that fired, with the engine rerun that counts it.
 *
 * @internal
 *
 * @phpstan-import-type Shape from Modifier as ModifierShape
 *
 * @phpstan-type Shape array{flag: string, weight: int, if_counted: array{total: int, verdict: string, role: ?string, at_least: bool, modifiers: list<ModifierShape>}}
 */
final class Accepted
{
    private string $flag;
    private int $weight;
    private int $total;
    private string $verdict;
    private ?string $role;
    private bool $atLeast;
    /** @var list<Modifier> */
    private array $modifiers;

    /**
     * @param int          $total     the total of the rerun
     * @param ?string      $role      the flag's role in the rerun, null when it has no term there
     * @param list<Modifier> $modifiers the halvings of the rerun
     */
    public function __construct(string $flag, int $weight, int $total, string $verdict, ?string $role, bool $atLeast, array $modifiers)
    {
        $this->flag = $flag;
        $this->weight = $weight;
        $this->total = $total;
        $this->verdict = $verdict;
        $this->role = $role;
        $this->atLeast = $atLeast;
        $this->modifiers = $modifiers;
    }

    public function flag(): string
    {
        return $this->flag;
    }

    /** @return list<Modifier> */
    public function modifiers(): array
    {
        return $this->modifiers;
    }

    /** @return Shape */
    public function toArray(): array
    {
        return ['flag' => $this->flag, 'weight' => $this->weight, 'if_counted' => [
            'total' => $this->total,
            'verdict' => $this->verdict,
            'role' => $this->role,
            'at_least' => $this->atLeast,
            'modifiers' => array_map(static fn (Modifier $modifier): array => $modifier->toArray(), $this->modifiers),
        ]];
    }
}
