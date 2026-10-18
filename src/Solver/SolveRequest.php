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

    /** @var list<string> */
    private array $allow;
    /** @var array<string, string> */
    private array $inline;
    /** @var array<string, string> */
    private array $rootChanges;
    /** @var self::LISTED|self::WITH_DEPENDENCIES|self::WITH_ALL_DEPENDENCIES */
    private int $transitive;

    /**
     * @param list<string>                                                $allow       the update allow list
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
        return new self([self::BASELINE_NAME], [], [], self::LISTED);
    }

    /** @param array<string, string> $inline name => constraint, each name on the allow list */
    public static function update(array $inline): self
    {
        return new self(array_keys($inline), $inline, [], self::LISTED);
    }

    /**
     * @param array<string, string> $rootChanges name => constraint; a platform name such as `php`
     *                                           changes the root requirement without an update
     */
    public static function require(string $package, array $rootChanges): self
    {
        return new self([$package], [], $rootChanges, self::LISTED);
    }

    /** @param array<string, string> $rootChanges name => constraint, beside the inline constraints */
    public function withRootChanges(array $rootChanges): self
    {
        $clone = clone $this;
        $clone->rootChanges = $rootChanges;

        return $clone;
    }

    /** @param self::LISTED|self::WITH_DEPENDENCIES|self::WITH_ALL_DEPENDENCIES $transitive */
    public function withTransitive(int $transitive): self
    {
        $clone = clone $this;
        $clone->transitive = $transitive;

        return $clone;
    }

    /** @return list<string> */
    public function allow(): array
    {
        return $this->allow;
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
