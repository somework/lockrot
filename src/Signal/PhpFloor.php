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
