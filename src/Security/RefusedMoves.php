<?php

declare(strict_types=1);

namespace Lockrot\Security;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;

/**
 * The moves of one package that Composer's solver refused in every form, with their holds. A
 * refusal of a constraint holds every release that the constraint admits, so a candidate there
 * takes the holds and its class follows them (SPEC §5.3).
 *
 * @internal
 */
final class RefusedMoves
{
    /** @var array<string, array{0: ConstraintInterface, 1: list<Holder>}> by constraint as written */
    private array $refused = [];

    public static function none(): self
    {
        return new self();
    }

    /**
     * A copy with one more refusal. A constraint that does not parse holds nothing.
     *
     * @param list<Holder> $holds
     */
    public function with(string $constraint, array $holds): self
    {
        if (isset($this->refused[$constraint])) {
            return $this;
        }
        try {
            $parsed = (new VersionParser())->parseConstraints($constraint);
        } catch (\UnexpectedValueException $e) {
            return $this;
        }
        $clone = clone $this;
        $clone->refused[$constraint] = [$parsed, $holds];

        return $clone;
    }

    public function count(): int
    {
        return \count($this->refused);
    }

    /** Whether a refused constraint admits the release. */
    public function refuses(string $normalized): bool
    {
        $release = new Constraint('==', $normalized);
        foreach ($this->refused as [$constraint]) {
            if ($constraint->matches($release)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a refused constraint whose refusal names no hold admits the release. */
    public function blocks(string $normalized): bool
    {
        $release = new Constraint('==', $normalized);
        foreach ($this->refused as [$constraint, $holders]) {
            if ($holders === [] && $constraint->matches($release)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Holder> the holds of every refused constraint that admits the release */
    public function holding(string $normalized): array
    {
        $release = new Constraint('==', $normalized);
        $holds = [];
        foreach ($this->refused as [$constraint, $holders]) {
            if ($constraint->matches($release)) {
                $holds = array_merge($holds, $holders);
            }
        }

        return $holds;
    }
}
