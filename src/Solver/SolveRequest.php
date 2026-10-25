<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\DependencyResolver\Request;

/**
 * One update request, as `composer update` or `composer require` builds it: the names it may
 * update, the inline constraints (`update vendor/pkg:^1.2`), the root requirements it changes
 * (`require vendor/pkg:^1.2`, `require php:>=8.1 --no-update`), and how far it unlocks dependencies.
 *
 * @internal
 */
final class SolveRequest
{
    /** A name that no lock holds: its partial update moves nothing (D5's baseline). */
    public const BASELINE_NAME = 'lockrot/baseline';

    public const LISTED = Request::UPDATE_ONLY_LISTED;
    /** `-w`: the dependencies too, except the root requirements. */
    public const WITH_DEPENDENCIES = Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS_NO_ROOT_REQUIRE;
    /** `-W`: every dependency, the root requirements included. */
    public const WITH_ALL_DEPENDENCIES = Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS;

    /** @var array<string, string> */
    private array $allow;
    /** @var array<string, string> */
    private array $inline;
    /** @var array<string, string> */
    private array $rootChanges;
    /** @var self::LISTED|self::WITH_DEPENDENCIES|self::WITH_ALL_DEPENDENCIES */
    private int $transitive;
    private bool $keepsTargets = false;
    /** @var array<string, string> */
    private array $joined = [];

    /**
     * @param array<string, string>                                       $allow       the update allow list, name => name
     * @param array<string, string>                                       $inline      name => constraint, as `update name:constraint`
     * @param array<string, string>                                       $rootChanges name => constraint, as `require name:constraint`
     * @param self::LISTED|self::WITH_DEPENDENCIES|self::WITH_ALL_DEPENDENCIES $transitive
     */
    private function __construct(array $allow, array $inline, array $rootChanges, int $transitive)
    {
        $this->allow = $allow;
        $this->inline = $inline;
        $this->rootChanges = $rootChanges;
        $this->transitive = $transitive;
    }

    public static function baseline(): self
    {
        return new self([self::BASELINE_NAME => self::BASELINE_NAME], [], [], self::LISTED);
    }

    /** @param array<string, string> $inline name => constraint, each name on the allow list */
    public static function update(array $inline): self
    {
        $allow = [];
        foreach (array_keys($inline) as $name) {
            $allow[$name] = $name;
        }

        return new self($allow, $inline, [], self::LISTED);
    }

    /**
     * @param array<string, string> $rootChanges name => constraint; a platform name such as `php`
     *                                           changes the root requirement without an update
     */
    public static function require(string $package, array $rootChanges): self
    {
        return new self([$package => $package], [], $rootChanges, self::LISTED);
    }

    /**
     * An update of the names at the solve's php, with no constraint: `run.php_window.target_fix`.
     * It moves no name, so each hold that Composer's refusal names keeps its target.
     *
     * @param list<string> $names
     */
    public static function unlock(array $names): self
    {
        $allow = [];
        foreach ($names as $name) {
            $allow[$name] = $name;
        }
        $request = new self($allow, [], [], self::LISTED);
        $request->keepsTargets = true;

        return $request;
    }

    /** @param array<string, string> $rootChanges name => constraint, beside the inline constraints */
    public function withRootChanges(array $rootChanges): self
    {
        $clone = clone $this;
        $clone->rootChanges = $rootChanges;

        return $clone;
    }

    /**
     * A copy that may also update the holders of a refused move, with no constraint of their own.
     * A link to a holder keeps its target in a hold.
     *
     * @param list<string> $holders
     */
    public function withJoined(array $holders): self
    {
        $clone = clone $this;
        foreach ($holders as $name) {
            $clone->joined[$name] = $name;
        }

        return $clone;
    }

    /** @param self::LISTED|self::WITH_DEPENDENCIES|self::WITH_ALL_DEPENDENCIES $transitive */
    public function withTransitive(int $transitive): self
    {
        $clone = clone $this;
        $clone->transitive = $transitive;

        return $clone;
    }

    /** @return array<string, string> name => name: Composer before 2.6 reads the allow list's keys, later versions its values */
    public function allow(): array
    {
        return $this->allow + $this->joined;
    }

    /** @return array<string, string> name => name: a link to one of them has no target in a hold ({@see ProblemHolds}) */
    public function moved(): array
    {
        return $this->keepsTargets ? [] : $this->allow;
    }

    /** @return array<string, string> */
    public function inline(): array
    {
        return $this->inline;
    }

    /** @return array<string, string> */
    public function rootChanges(): array
    {
        return $this->rootChanges;
    }

    /** @return self::LISTED|self::WITH_DEPENDENCIES|self::WITH_ALL_DEPENDENCIES */
    public function transitive(): int
    {
        return $this->transitive;
    }
}
