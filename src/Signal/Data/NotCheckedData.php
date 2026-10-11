<?php

declare(strict_types=1);

namespace Lockrot\Signal\Data;

/** @internal */
final class NotCheckedData implements SignalData
{
    /** @var non-empty-list<Check> */
    private array $checks;

    /** @param non-empty-list<Check> $checks no two of them block the same signal */
    public function __construct(array $checks)
    {
        $this->checks = $checks;
    }

    /** @return non-empty-list<Check> */
    public function checks(): array
    {
        return $this->checks;
    }

    /** @return array{unchecked: non-empty-list<Check>, blocks: list<string>} */
    public function jsonSerialize(): array
    {
        $blocks = [];
        foreach ($this->checks as $check) {
            foreach ($check->blocks() as $id) {
                $blocks[] = $id;
            }
        }

        return ['unchecked' => $this->checks, 'blocks' => $blocks];
    }
}
