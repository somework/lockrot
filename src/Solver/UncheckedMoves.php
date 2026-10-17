<?php

declare(strict_types=1);

namespace Lockrot\Solver;

/**
 * Checks no move, for one reason: a run with no composer.json, or a Composer that lacks the
 * solver's classes.
 *
 * @internal
 */
final class UncheckedMoves implements MoveChecker
{
    private string $reason;

    public function __construct(string $reason)
    {
        $this->reason = $reason;
    }

    public function baseline(): CheckOutcome
    {
        return new CheckOutcome(MoveCheck::notChecked($this->reason));
    }

    public function check(MoveQuery $query): CheckOutcome
    {
        return new CheckOutcome(MoveCheck::notChecked($this->reason));
    }

    public function combined(array $inline): CheckOutcome
    {
        return new CheckOutcome(MoveCheck::notChecked($this->reason));
    }

    public function platformPhp(): ?string
    {
        return null;
    }

    public function composerVersion(): ?string
    {
        return null;
    }

    public function blocksAdvisories(): bool
    {
        return false;
    }

    public function withBlockedAdvisories(array $ranges): MoveChecker
    {
        return $this;
    }
}
