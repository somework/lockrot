<?php

declare(strict_types=1);

namespace Lockrot\Config;

/**
 * A check that the gate could not apply, on how many packages, and why: `gate.not_applied[]`.
 *
 * @internal
 *
 * @phpstan-type Shape array{check: string, packages: int, reason: string}
 */
final class GateNotApplied implements \JsonSerializable
{
    private string $check;
    private int $packages;
    private string $reason;

    public function __construct(string $check, int $packages, string $reason)
    {
        $this->check = $check;
        $this->packages = $packages;
        $this->reason = $reason;
    }

    public function check(): string
    {
        return $this->check;
    }

    public function packages(): int
    {
        return $this->packages;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** @return Shape */
    public function jsonSerialize(): array
    {
        return ['check' => $this->check, 'packages' => $this->packages, 'reason' => $this->reason];
    }
}
