<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Lockrot\Security\Holder;

/**
 * A move's check, with the holds and Composer's problem text of a refusal, and the version that a
 * resolved move installs.
 *
 * @internal
 */
final class CheckOutcome
{
    private MoveCheck $check;
    /** @var list<Holder> */
    private array $holds;
    private ?string $installs;
    private ?string $problem;

    /** @param list<Holder> $holds */
    public function __construct(MoveCheck $check, array $holds = [], ?string $installs = null, ?string $problem = null)
    {
        $this->check = $check;
        $this->holds = $holds;
        $this->installs = $installs;
        $this->problem = $problem;
    }

    public function check(): MoveCheck
    {
        return $this->check;
    }

    /** @return list<Holder> distinct, in `held_by[]`'s order; empty unless the check refused the move */
    public function holds(): array
    {
        return $this->holds;
    }

    /** The version of the moved package that a resolved move installs, null otherwise. */
    public function installs(): ?string
    {
        return $this->installs;
    }

    public function problem(): ?string
    {
        return $this->problem;
    }
}
