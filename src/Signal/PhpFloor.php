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
 * promise that its maintainers keep. See docs/verdicts.md#within-reach.
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
    private ?string $projectPoint;
    private ?string $projectPhp;

    /**
     * @param ?string $targetPhp  the run's target PHP, `8.4` or `8.4.7`
     * @param ?string $projectPhp the project's `require.php` as written in composer.json, null when absent
     */
    public function __construct(?string $targetPhp, ?string $projectPhp = null)
    {
        $this->parser = new VersionParser();
        [$this->targetMinor, $this->targetLabel] = $this->target($targetPhp);
        [$this->projectPoint, $this->projectPhp] = $this->project($projectPhp);
        $this->projectLowest = $this->projectPoint === null ? null : new Constraint('==', $this->projectPoint.'.0');
    }

    /**
     * The project's lowest PHP as the stable point that every project comparison checks, written
     * `MAJOR.MINOR.PATCH`: `>=8.2` gives `8.2.0`, `>=7.4.0-RC1` gives `7.4.0`, `>7.1` gives
     * `7.1.1`. Null with no `require.php`, no lower bound (`*`, `<8`) or an unreadable one.
     * See SPEC-0.14 5.3 `run.project_php_lowest`.
     */
    public function lowestAsString(): ?string
    {
        return $this->projectPoint;
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
     * Correct only for a $php that admits none of $floor: an admitting $php can read as `skips`.
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

    /** @return array{0: ?string, 1: ?string} the point, and `require.php` as written */
    private function project(?string $projectPhp): array
    {
        $point = $projectPhp === null ? null : self::pointOf($projectPhp);

        return $point === null ? [null, null] : [$point, $projectPhp];
    }

    /**
     * The lowest stable `X.Y.Z` that a php constraint admits by its lower bound, as
     * {@see lowestAsString()} reads `require.php`. An exclusive bound, or one with a non-zero
     * fourth segment, admits no `X.Y.Z` of its own, so the point is the next patch. Null with no
     * lower bound or an unreadable constraint.
     */
    public static function pointOf(string $constraint): ?string
    {
        try {
            $lower = (new VersionParser())->parseConstraints($constraint)->getLowerBound();
        } catch (\UnexpectedValueException $e) {
            return null;
        }
        if ($lower->isZero()) {
            return null;
        }
        $version = $lower->getVersion();
        $numbers = explode('-', $version)[0];
        [$major, $minor, $patch, $fourth] = array_map('intval', explode('.', $numbers));
        if ($fourth !== 0 || (!$lower->isInclusive() && $numbers === $version)) {
            ++$patch;
        }

        return $major.'.'.$minor.'.'.$patch;
    }
}
