<?php

declare(strict_types=1);

namespace Lockrot\Output;

/**
 * One fact whose record is `error` for a value the finding fails, `gate.by[].facts[]`: a flag, an
 * advisory, or a signal that a missing check blocked. `facts[kind=signal].id` joins
 * `checks_missing[].blocks[]`.
 *
 * @internal
 *
 * @phpstan-type Shape array{kind: string, id: string}
 */
final class MarkedFact implements \JsonSerializable
{
    public const FLAG = 'flag';
    public const ADVISORY = 'advisory';
    public const SIGNAL = 'signal';

    private string $kind;
    private string $id;

    public function __construct(string $kind, string $id)
    {
        $this->kind = $kind;
        $this->id = $id;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function id(): string
    {
        return $this->id;
    }

    /** @return Shape */
    public function jsonSerialize(): array
    {
        return ['kind' => $this->kind, 'id' => $this->id];
    }
}
