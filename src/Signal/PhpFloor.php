<?php

declare(strict_types=1);

namespace Lockrot\Signal;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use Lockrot\Data\Php\PhpReleaseDates;

/**
 * The PHP a release branch has to admit before the project can move onto it: the project's own
 * `require.php` (the promise its maintainers made — Matomo supports `>=7.2.5`, and a branch that
 * needs `>=8.1` is not one it can require without breaking that promise) and the target PHP (what
 * Composer resolves against, `config.platform.php` or the running PHP, so a branch outside it will
 * not install at all). Composer enforces only the second; the first is the maintainers' to keep,
 * and they are the ones reading the report. S8 holds the branch it names to both
 * ({@see \Lockrot\Signal\Rule\LeftBehindRule}), and `--explain` shows every branch's answer from
 * each floor ({@see \Lockrot\Explain\Explanation::toArray()}).
 *
 * @internal
 */
final class PhpFloor
{
    public const PROJECT = 'project';
    public const TARGET = 'target';

    /** Every PHP the branch's php admits is above the floor: it needs a newer PHP. */
    public const NEEDS_NEWER = 'needs_newer';
    /** Every PHP the branch's php admits is below the floor: its support stops before it. */
    public const STOPS_BEFORE = 'stops_before';
    /** The branch's php admits PHP below the floor and above it, not the floor itself. */
    public const SKIPS = 'skips';
    /** The branch's php admits no PHP at all (`>=9 <8`, a branch name), so the floor has no side. */
    public const UNSATISFIABLE = 'unsatisfiable';

    private VersionParser $parser;
    /** The whole target minor, `>=8.4.0 <8.5.0`; null when no target was given or it is not a version. */
    private ?ConstraintInterface $targetMinor;
    private ?string $targetLabel;
    /** The lowest PHP the project promises to run on, as `== 7.2.5.0`; null when it promises nothing readable. */
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
     * What holds a branch with this php requirement back: {@see self::PROJECT} when the project's
     * lowest PHP is outside it, else {@see self::TARGET} when no version of the target minor is,
     * else null — not held back. A branch that requires no PHP, or whose requirement cannot be
     * parsed, is not held back: there is nothing to hold it against. The two answers read in this
     * order are {@see self::admitsProject()} and {@see self::admitsTarget()}, and a null from
     * either is no answer, so it holds nothing back.
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

    /**
     * Whether a branch with this php requirement admits the lowest PHP the project's own
     * `require.php` promises. Null is no answer, never "admitted": the branch requires no PHP, its
     * requirement cannot be parsed, or the project names no lowest PHP (no `require.php`, `*`, or
     * one that cannot be parsed).
     */
    public function admitsProject(?string $constraint): ?bool
    {
        return $this->projectAdmits($this->parse($constraint));
    }

    /**
     * Whether a branch with this php requirement admits some version of the target PHP minor —
     * whether Composer resolving against the target could install it. Null is no answer, never
     * "admitted": the branch requires no PHP, its requirement cannot be parsed, or there is no
     * target to hold it against.
     */
    public function admitsTarget(?string $constraint): ?bool
    {
        return $this->targetAdmits($this->parse($constraint));
    }

    /**
     * Which side of the target PHP minor a branch with this php requirement is on, when it admits
     * no version of it: {@see self::NEEDS_NEWER}, {@see self::STOPS_BEFORE}, {@see self::SKIPS} or
     * {@see self::UNSATISFIABLE}. Null exactly where {@see self::admitsTarget()} is not false: the
     * target is admitted, or there is no answer.
     */
    public function missesTarget(?string $constraint): ?string
    {
        $parsed = $this->parse($constraint);

        if ($parsed === null || $this->targetMinor === null || Intervals::haveIntersections($parsed, $this->targetMinor)) {
            return null;
        }

        return self::side($parsed, $this->targetMinor);
    }

    /**
     * Which side of the project's lowest PHP a branch with this php requirement is on, when it does
     * not admit it; the same answers as {@see self::missesTarget()}. Null exactly where
     * {@see self::admitsProject()} is not false.
     */
    public function missesProject(?string $constraint): ?string
    {
        $parsed = $this->parse($constraint);

        if ($parsed === null || $this->projectLowest === null || $parsed->matches($this->projectLowest)) {
            return null;
        }

        return self::side($parsed, $this->projectLowest);
    }

    /** The floor named in a sentence: `the project's php >=7.2.5`, `the target PHP 7.2`. */
    public function describe(string $kind): string
    {
        return ($kind === self::PROJECT ? 'the project\'s php ' : 'the target PHP ').(string) $this->php($kind);
    }

    /** The floor as data: the project's `require.php` as written, or the target minor (`7.2`); null for a floor that is not there. */
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
     * Where a php requirement that admits nothing of the floor lies against it: whether it admits
     * anything below the floor's lower bound, and anything above its upper bound. The floor is the
     * target minor (`>=8.4.0.0-dev <8.5.0.0-dev`) or the project's point (`== 8.2.0.0-dev`): both
     * start at a bound they include, and only the target's end is left out of it.
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
