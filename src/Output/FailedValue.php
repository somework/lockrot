<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Verdict\FailOnValue;

/**
 * One value of `run.gates[]` that a finding fails, with the facts whose records are `error`,
 * `finding.gate.by[]`.
 *
 * @internal
 *
 * @phpstan-type Shape array{value: string, kind: string, facts: list<MarkedFact>}
 */
final class FailedValue implements \JsonSerializable
{
    private FailOnValue $value;
    /** @var list<MarkedFact> */
    private array $facts;

    /** @param list<MarkedFact> $facts */
    public function __construct(FailOnValue $value, array $facts)
    {
        $this->value = $value;
        $this->facts = $facts;
    }

    public function value(): FailOnValue
    {
        return $this->value;
    }

    /** @return list<MarkedFact> */
    public function facts(): array
    {
        return $this->facts;
    }

    /** @return Shape */
    public function jsonSerialize(): array
    {
        return ['value' => $this->value->value(), 'kind' => $this->value->kind(), 'facts' => $this->facts];
    }
}
