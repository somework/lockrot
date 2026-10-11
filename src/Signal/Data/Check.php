<?php

declare(strict_types=1);

namespace Lockrot\Signal\Data;

/**
 * A check that did not run: an S10 `unchecked[]` entry or a `checks_skipped[]` entry
 * (docs/verdicts.md#what-was-not-checked).
 *
 * @internal
 */
final class Check implements \JsonSerializable
{
    private string $check;
    private string $reason;
    /** @var list<string> */
    private array $blocks;

    /** @param list<string> $blocks the ids of the signals that the check would read */
    public function __construct(string $check, string $reason, array $blocks)
    {
        $this->check = $check;
        $this->reason = $reason;
        $this->blocks = $blocks;
    }

    public function check(): string
    {
        return $this->check;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** @return list<string> */
    public function blocks(): array
    {
        return $this->blocks;
    }

    /** @return array{check: string, reason: string, blocks: list<string>} */
    public function jsonSerialize(): array
    {
        return ['check' => $this->check, 'reason' => $this->reason, 'blocks' => $this->blocks];
    }
}
