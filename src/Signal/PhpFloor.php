<?php

declare(strict_types=1);

namespace Lockrot\Signal;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use Lockrot\Data\Php\PhpReleaseDates;

/**
 * The PHP that a release branch must admit before the project can move onto it: the project's own
 * `require.php` and the target PHP. Composer enforces only the target. The project's floor is a
 * promise that its maintainers keep. {@see \Lockrot\Signal\Rule\LeftBehindRule} holds the branch
 * it names to both, and {@see \Lockrot\Explain\Explanation::toArray()} shows every branch's answer.
 * See docs/verdicts.md#within-reach.
 *
 * @internal
 */
final class PhpFloor
{
    public const PROJECT = 'project';
    public const TARGET = 'target';

    /** The `misses_*` values: docs/verdicts.md#the-php-test-in-explain. */
    public const NEEDS_NEWER = 'needs_newer';
    public const STOPS_BEFORE = 'stops_before';
    public const SKIPS = 'skips';
    public const UNSATISFIABLE = 'unsatisfiable';

    private VersionParser $parser;
    /** The whole target minor, `>=8.4.0 <8.5.0`, or null with no target or a non-version. */
    private ?ConstraintInterface $targetMinor;
    private ?string $targetLabel;
    /** The lowest PHP the project promises to run on, as `== 7.2.5.0`, or null with no readable promise. */
    private ?Constraint $projectLowest;
    private ?string $projectPhp;

    /**
     * @param ?string $targetPhp  the run's target PHP, `8.4` or `8.4.7`
     * @param ?string $projectPhp the project's `require.php` as written in composer.json, null when absent
     */
    public function __construct(?string $targetPhp, ?string $projectPhp = null)
    {
        $this->parser = new VersionParser();
        [$this->targetMinor, $this->targetLabel] = $this->target($targetPhp);
        [$this->projectLowest, $this->projectPhp] = $this->project($projectPhp);
    }

    /**
     * What holds a branch with this php requirement back. It is {@see self::PROJECT} when the
     * project's lowest PHP is outside the requirement, else {@see self::TARGET} when no version of
     * the target minor is, else null. A branch that requires no PHP, or whose requirement cannot
     * be parsed, is not held back.
     */
    public function blocking(?string $constraint): ?string
    {
        $parsed = $this->parse($constraint);
        if ($this->projectAdmits($parsed) === false) {
            return self::PROJECT;
        }
        if ($this->targetAdmits($parsed) === false) {
            return self::TARGET;
        }

        return null;
    }

    /** Null is no answer, never "admitted": docs/verdicts.md#the-php-test-in-explain. */
    public function admitsProject(?string $constraint): ?bool
    {
        return $this->projectAdmits($this->parse($constraint));
    }

    /** Null is no answer, never "admitted": docs/verdicts.md#the-php-test-in-explain. */
    public function admitsTarget(?string $constraint): ?bool
    {
        return $this->targetAdmits($this->parse($constraint));
    }

    /** Null exactly where {@see self::admitsTarget()} is not false. */
    public function missesTarget(?string $constraint): ?string
    {
        $parsed = $this->parse($constraint);

        if ($parsed === null || $this->targetMinor === null || Intervals::haveIntersections($parsed, $this->targetMinor)) {
            return null;
        }

        return self::side($parsed, $this->targetMinor);
    }

    /** Null exactly where {@see self::admitsProject()} is not false. */
    public function missesProject(?string $constraint): ?string
    {
        $parsed = $this->parse($constraint);

        if ($parsed === null || $this->projectLowest === null || $parsed->matches($this->projectLowest)) {
            return null;
        }

        return self::side($parsed, $this->projectLowest);
    }

    public function describe(string $kind): string
    {
        return ($kind === self::PROJECT ? 'the project\'s php ' : 'the target PHP ').(string) $this->php($kind);
    }

    public function php(string $kind): ?string
    {
        return $kind === self::PROJECT ? $this->projectPhp : $this->targetLabel;
    }

    private function parse(?string $constraint): ?ConstraintInterface
    {
        if ($constraint === null) {
            return null;
        }
        try {
            return $this->parser->parseConstraints($constraint);
        } catch (\UnexpectedValueException $e) {
            return null;
        }
    }

    private function projectAdmits(?ConstraintInterface $constraint): ?bool
    {
        if ($constraint === null || $this->projectLowest === null) {
            return null;
        }

        return $constraint->matches($this->projectLowest);
    }

    private function targetAdmits(?ConstraintInterface $constraint): ?bool
    {
        if ($constraint === null || $this->targetMinor === null) {
            return null;
        }

        return Intervals::haveIntersections($constraint, $this->targetMinor);
    }

    /**
     * The floor is the target minor (`>=8.4.0.0-dev <8.5.0.0-dev`) or the project's point
     * (`== 8.2.0.0-dev`). Both include their lower bound, and only the target excludes its upper
     * bound.
     */
    private static function side(ConstraintInterface $php, ConstraintInterface $floor): string
    {
        $upper = $floor->getUpperBound();
        $below = Intervals::haveIntersections($php, new Constraint('<', $floor->getLowerBound()->getVersion()));
        $above = Intervals::haveIntersections($php, new Constraint($upper->isInclusive() ? '>' : '>=', $upper->getVersion()));
        if ($below && $above) {
            return self::SKIPS;
        }
        if ($above) {
            return self::NEEDS_NEWER;
        }

        return $below ? self::STOPS_BEFORE : self::UNSATISFIABLE;
    }

    /** @return array{0: ?ConstraintInterface, 1: ?string} */
    private function target(?string $targetPhp): array
    {
        if ($targetPhp === null) {
            return [null, null];
        }
        $minor = PhpReleaseDates::minorOf($targetPhp);
        try {
            return [$this->parser->parseConstraints('~'.$minor.'.0'), $minor];
        } catch (\UnexpectedValueException $e) {
            return [null, null];
        }
    }

    /** @return array{0: ?Constraint, 1: ?string} */
    private function project(?string $projectPhp): array
    {
        if ($projectPhp === null) {
            return [null, null];
        }
        try {
            $lower = $this->parser->parseConstraints($projectPhp)->getLowerBound();
        } catch (\UnexpectedValueException $e) {
            return [null, null];
        }
        if ($lower->isZero()) {
            return [null, null];
        }

        return [new Constraint('==', $lower->getVersion()), $projectPhp];
    }
}
