<?php

declare(strict_types=1);

namespace Lockrot\Config;

/**
 * One `run.gates[]` value and how many findings fail it: `gate.failing_by[]`.
 *
 * @internal
 */
final class GateValueFailing implements \JsonSerializable
{
    private string $value;
    private int $failing;

    public function __construct(string $value, int $failing)
    {
        $this->value = $value;
        $this->failing = $failing;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function failing(): int
    {
        return $this->failing;
    }

    /** @return array{value: string, failing: int} */
    public function jsonSerialize(): array
    {
        return ['value' => $this->value, 'failing' => $this->failing];
    }
}
