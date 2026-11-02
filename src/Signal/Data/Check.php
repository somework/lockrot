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
    /** @var array<string, list<string>> */
    private array $causes;

    /**
     * @param list<string>                $blocks the ids of the signals that the check would read
     * @param array<string, list<string>> $causes what kept each blocked signal from its input, by
     *                                            cause: the words read it, the JSON does not
     */
    public function __construct(string $check, string $reason, array $blocks, array $causes = [])
    {
        $this->check = $check;
        $this->reason = $reason;
        $this->blocks = $blocks;
        $this->causes = $causes;
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

    /** @return array<string, list<string>> */
    public function causes(): array
    {
        return $this->causes;
    }

    /** @return array{check: string, reason: string, blocks: list<string>} */
    public function jsonSerialize(): array
    {
        return ['check' => $this->check, 'reason' => $this->reason, 'blocks' => $this->blocks];
    }
}
