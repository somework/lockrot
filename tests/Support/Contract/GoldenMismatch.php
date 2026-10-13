<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support\Contract;

/** PHP renders a string otherwise than the model's golden of the same case: the bundle is not written. */
final class GoldenMismatch extends \UnexpectedValueException
{
    /** @var array<string, array<string, array{expected: mixed, actual: mixed}>> */
    private array $mismatches;

    /** @param array<string, array<string, array{expected: mixed, actual: mixed}>> $mismatches by case id, then golden key */
    public function __construct(array $mismatches)
    {
        $keys = [];
        foreach ($mismatches as $id => $byKey) {
            foreach (array_keys($byKey) as $key) {
                $keys[] = $id.' '.$key;
            }
        }
        parent::__construct("PHP's renderings differ from the model's goldens: ".implode(', ', $keys));
        $this->mismatches = $mismatches;
    }

    /** @return array<string, array<string, array{expected: mixed, actual: mixed}>> */
    public function mismatches(): array
    {
        return $this->mismatches;
    }
}
