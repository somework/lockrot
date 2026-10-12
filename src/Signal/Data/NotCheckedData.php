<?php

declare(strict_types=1);

namespace Lockrot\Signal\Data;

use Lockrot\Signal\Signal;

/** @internal */
final class NotCheckedData implements SignalData
{
    /** @var non-empty-list<Check> */
    private array $checks;

    /** @param non-empty-list<Check> $checks */
    public function __construct(array $checks)
    {
        $this->checks = $checks;
    }

    public function signalId(): string
    {
        return Signal::S10;
    }

    /** @return non-empty-list<Check> */
    public function checks(): array
    {
        return $this->checks;
    }

    /** @return array{unchecked: non-empty-list<Check>, blocks: list<string>} each blocked signal once, in signal order */
    public function jsonSerialize(): array
    {
        $blocks = [];
        foreach ($this->checks as $check) {
            foreach ($check->blocks() as $id) {
                // PHP stores the numeric key as an int, so ksort() orders S2 before S10.
                $blocks[substr($id, 1)] = $id;
            }
        }
        ksort($blocks);

        return ['unchecked' => $this->checks, 'blocks' => array_values($blocks)];
    }
}
