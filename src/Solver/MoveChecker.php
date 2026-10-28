<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Advisory\PartialSecurityAdvisory;

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

    /**
     * The refused move's request that also updates its holders, inline: Composer's answer,
     * with every release that a resolving solve changes ({@see CheckOutcome::changes()}).
     *
     * @param non-empty-list<string> $holders
     */
    public function joint(MoveQuery $query, array $holders): CheckOutcome;

    /**
     * One `composer update` of every name: Composer's answer, with every release that a resolving
     * solve changes ({@see CheckOutcome::changes()}).
     *
     * @param array<string, string> $inline name => constraint, empty for a name listed plain
     */
    public function combined(array $inline): CheckOutcome;

    /**
     * One update of the names at the solve's php, with no constraint, each hold with its target
     * (`run.php_window.target_fix`). With no name, it is `composer update --lock`. It takes the
     * baseline's reason when the baseline was not checked, and ignores a refused baseline.
     *
     * @param list<string> $names
     */
    public function solveAt(array $names): CheckOutcome;

    /** The php version that the solve runs at, as Composer's problem text names it; null when nothing solves. */
    public function platformPhp(): ?string;

    /** `config.platform.php` of Composer's merged config, null when it names none or when nothing solves. */
    public function configuredPhp(): ?string;

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

    /** @return list<string> the environment variables that turned Composer's blocking off (`run.move_check.blocking_disabled_by`) */
    public function blockingDisabledBy(): array;

    /**
     * The same check with the advisories that the run's lookup found. When {@see blocksAdvisories()}
     * is true, the solve blocks them as Composer's settings of the `block` operation say.
     *
     * @param array<string, list<PartialSecurityAdvisory>> $records every record of each name
     * @param list<string>                                 $covered the names that the lookup asked
     */
    public function withAdvisories(array $records, array $covered): self;
}
