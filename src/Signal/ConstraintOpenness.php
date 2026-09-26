<?php

declare(strict_types=1);

namespace Lockrot\Signal;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use Lockrot\Data\Php\PhpReleaseDates;

/**
 * Decides whether a php requirement is an "open promise": it accepts the target PHP version only because
 * it has no upper bound, and was written for an older major (e.g. ">=5.3.0" accepting PHP 8.4).
 *
 * @internal
 */
final class ConstraintOpenness
{
    private VersionParser $parser;

    public function __construct(?VersionParser $parser = null)
    {
        $this->parser = $parser ?? new VersionParser();
    }

    public function matches(string $constraint, string $targetPhp): bool
    {
        $parsed = $this->parse($constraint, $targetPhp);
        if ($parsed === null) {
            return false;
        }
        [$parsedConstraint, $target] = $parsed;

        return $parsedConstraint->matches($target);
    }

    public function isOpenEndedFor(string $constraint, string $targetPhp): bool
    {
        $parsed = $this->parse($constraint, $targetPhp);
        if ($parsed === null) {
            return false;
        }
        [$parsedConstraint, $target] = $parsed;
        if (!$parsedConstraint->matches($target) || !$parsedConstraint->getUpperBound()->isPositiveInfinity()) {
            return false;
        }
        $targetMajor = (int) explode('.', PhpReleaseDates::minorOf($targetPhp))[0];
        $lower = $parsedConstraint->getLowerBound();
        $lowerMajor = $lower->isZero() ? 0 : (int) explode('.', $lower->getVersion())[0];

        return $lowerMajor < $targetMajor;
    }

    /**
     * The PHP major the constraint was written for: the major of its lower bound (`>=5.3.0` → 5,
     * `>= 7` → 7), 0 for one with no lower bound (`*`), null when the string cannot be parsed.
     */
    public function lowerMajor(string $constraint): ?int
    {
        try {
            $lower = $this->parser->parseConstraints($constraint)->getLowerBound();
        } catch (\UnexpectedValueException $e) {
            return null;
        }

        return $lower->isZero() ? 0 : (int) explode('.', $lower->getVersion())[0];
    }

    /**
     * Parses the constraint and builds the target-version constraint used to test it.
     *
     * @return array{0: ConstraintInterface, 1: Constraint}|null null when the constraint string is not parseable
     */
    private function parse(string $constraint, string $targetPhp): ?array
    {
        try {
            $parsed = $this->parser->parseConstraints($constraint);
            $target = new Constraint('==', $this->parser->normalize(PhpReleaseDates::minorOf($targetPhp).'.0'));
        } catch (\UnexpectedValueException $e) {
            return null;
        }

        return [$parsed, $target];
    }
}
