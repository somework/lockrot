<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
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

    /**
     * A copy without the root requirements on the moved package that admit $version, Composer's
     * steps on the way to the hold that refuses the move. When no other hold is left, the copy keeps
     * them: Composer then names only the root requirement.
     */
    public function withoutAdmitting(string $version): self
    {
        $holds = array_values(array_filter($this->holds, static fn (Holder $hold): bool => !self::admits($hold, $version)));
        $clone = clone $this;
        $clone->holds = $holds === [] ? $this->holds : $holds;

        return $clone;
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

    private static function admits(Holder $hold, string $version): bool
    {
        if ($hold->source() !== Holder::ROOT) {
            return false;
        }
        if ($hold->target() !== null || !\in_array($hold->link(), [Holder::REQUIRE, Holder::REQUIRE_DEV], true)) {
            return false;
        }
        $parser = new VersionParser();
        try {
            return $parser->parseConstraints($hold->constraint())->matches(new Constraint('==', $parser->normalize($version)));
        } catch (\UnexpectedValueException $e) {
            return false;
        }
    }
}
