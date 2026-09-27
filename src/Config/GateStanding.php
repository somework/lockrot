<?php

declare(strict_types=1);

namespace Lockrot\Config;

/**
 * Where one finding stands against fail-on, as {@see Gate::decide()} decided it: whether it reaches
 * the threshold, what exempts it if something does, and whether it fails the run. A report writes it
 * as the finding's `gate`.
 *
 * @internal
 */
final class GateStanding
{
    private bool $reachesFailOn;
    private bool $fails;
    private ?string $exemptBy;

    public function __construct(bool $reachesFailOn, bool $fails, ?string $exemptBy)
    {
        $this->reachesFailOn = $reachesFailOn;
        $this->fails = $fails;
        $this->exemptBy = $exemptBy;
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

    /** @return array{reaches_fail_on: bool, fails: bool, exempt_by: ?string} */
    public function toArray(): array
    {
        return [
            'reaches_fail_on' => $this->reachesFailOn,
            'fails' => $this->fails,
            'exempt_by' => $this->exemptBy,
        ];
    }
}
