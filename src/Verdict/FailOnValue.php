<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

/**
 * One parsed `--fail-on` value, `run.gates[]`: its kind and the grade, flag or minimum severity it
 * names. `unchecked` has no threshold.
 *
 * @internal
 *
 * @phpstan-type Shape array{value: string, kind: string, threshold: ?string}
 */
final class FailOnValue implements \JsonSerializable
{
    private string $value;
    private string $kind;
    private ?string $threshold;

    public function __construct(string $value, string $kind, ?string $threshold)
    {
        $this->value = $value;
        $this->kind = $kind;
        $this->threshold = $threshold;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function threshold(): ?string
    {
        return $this->threshold;
    }

    /** @return Shape */
    public function jsonSerialize(): array
    {
        return ['value' => $this->value, 'kind' => $this->kind, 'threshold' => $this->threshold];
    }
}
