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

    /** @return array{unchecked: non-empty-list<Check>, blocks: list<string>} each blocked signal once, in check order */
    public function jsonSerialize(): array
    {
        $blocks = [];
        foreach ($this->checks as $check) {
            foreach ($check->blocks() as $id) {
                $blocks[] = $id;
            }
        }

        return ['unchecked' => $this->checks, 'blocks' => array_values(array_unique($blocks))];
    }
}
