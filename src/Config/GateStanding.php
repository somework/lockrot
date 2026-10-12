<?php

declare(strict_types=1);

namespace Lockrot\Config;

use Lockrot\Output\FailedValue;
use Lockrot\Score\GateScore;

/**
 * Where one finding stands against the gate values, as {@see Gate::decide()} decided it: the
 * finding's `gate` (docs/schema.md#the-gate). It is not {@see \Lockrot\Score\Standing}, the baseline
 * standing `known new worsened`.
 *
 * @internal
 *
 * @phpstan-type Shape array{reaches_fail_on: bool, fails: bool, exempt_by: ?string, by: list<FailedValue>, basis: ?GateScore}
 */
final class GateStanding implements \JsonSerializable
{
    private bool $reachesFailOn;
    private bool $fails;
    private ?string $exemptBy;
    /** @var list<FailedValue> */
    private array $by;
    private ?GateScore $basis;

    /**
     * @param list<FailedValue> $by    one per value the finding fails, empty when it does not fail
     * @param ?GateScore        $basis the gate score under a baseline entry
     */
    public function __construct(bool $reachesFailOn, bool $fails, ?string $exemptBy, array $by = [], ?GateScore $basis = null)
    {
        $this->reachesFailOn = $reachesFailOn;
        $this->fails = $fails;
        $this->exemptBy = $exemptBy;
        $this->by = $by;
        $this->basis = $basis;
    }

    public function reachesFailOn(): bool
    {
        return $this->reachesFailOn;
    }

    public function fails(): bool
    {
        return $this->fails;
    }

    /** One of {@see Gate::EXEMPTIONS}, or null when nothing exempts the finding. */
    public function exemptBy(): ?string
    {
        return $this->exemptBy;
    }

    /** @return list<FailedValue> */
    public function by(): array
    {
        return $this->by;
    }

    public function basis(): ?GateScore
    {
        return $this->basis;
    }

    /** Whether the finding fails $value, the `value` of a `run.gates[]` entry. */
    public function failsValue(string $value): bool
    {
        foreach ($this->by as $failed) {
            if ($failed->value()->value() === $value) {
                return true;
            }
        }

        return false;
    }

    /** @return Shape */
    public function jsonSerialize(): array
    {
        return [
            'reaches_fail_on' => $this->reachesFailOn,
            'fails' => $this->fails,
            'exempt_by' => $this->exemptBy,
            'by' => $this->by,
            'basis' => $this->basis,
        ];
    }
}
