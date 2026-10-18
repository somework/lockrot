<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Semver\Constraint\ConstraintInterface;

/**
 * What the engine asks about moves (docs/verdicts.md#the-move-check). The same lock, metadata and
 * Composer version give the same answers.
 *
 * @internal
 */
interface MoveChecker
{
    /**
     * A partial update of a name outside the lock. When it is refused, every move would be refused
     * for the same reason: then {@see check()} checks none.
     */
    public function baseline(): CheckOutcome;

    public function check(MoveQuery $query): CheckOutcome;

    /** @param array<string, string> $inline name => constraint: one `composer update` of every name */
    public function combined(array $inline): CheckOutcome;

    /** The php version that the solve runs at, as Composer's problem text names it; null when nothing solves. */
    public function platformPhp(): ?string;

    /** The version of the Composer that solves, null when nothing solves. */
    public function composerVersion(): ?string;

    /**
     * The first exception that Composer's solver failed with in this run, null when it failed on none.
     *
     * @return array{class: string, message: string}|null
     */
    public function solverError(): ?array;

    /** Whether the solve removes the versions that an advisory affects, as Composer 2.9 and later do. */
    public function blocksAdvisories(): bool;

    /**
     * The same check with the advisory ranges that the run's lookup found, which the solve blocks
     * when {@see blocksAdvisories()} is true.
     *
     * @param array<string, list<ConstraintInterface>> $ranges by package name
     */
    public function withBlockedAdvisories(array $ranges): self;
}
