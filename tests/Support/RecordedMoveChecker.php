<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Security\Holder;
use Lockrot\Solver\CheckOutcome;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveChecker;
use Lockrot\Solver\MoveQuery;

/**
 * The move checks that a case records (`inputs.checks`), as Composer's solver answered them, with
 * their `problem` text. A key is a {@see MoveQuery::key()}, `joint <key> with <holders>` (SPEC
 * 5.6, "The requests"), `combined <names>`, `baseline` or `target_fix` ({@see solveAt()}). `*` and `combined` answer every other request and combined
 * update, and an absent `baseline` or `combined` resolves. A lenient checker leaves another request
 * `not_checked`. A strict checker, for a case with a `solver_world`, fails the test on it.
 */
final class RecordedMoveChecker implements MoveChecker
{
    /** @var array<string, array<mixed, mixed>> */
    private array $checks;
    /** @var array<mixed, mixed> */
    private array $run;
    private bool $strict;
    /** @var list<string>|null the names that the run's advisory lookup asked, null before the run passes them */
    private ?array $covered = null;
    /** @var list<array<string, string>> */
    private array $combinedAsked = [];
    /** @var list<MoveQuery> */
    private array $queries = [];

    /**
     * @param array<string, array<mixed, mixed>> $checks key => `{result, form, reason, installs, holds, problem}`, with
     *                                                  `installs` the release of each name that the solve changes, or
     *                                                  the release of the move's package
     * @param array<mixed, mixed>                $run    the case's `run.move_check`
     */
    public function __construct(array $checks, array $run, bool $strict = false)
    {
        $this->checks = $checks;
        $this->run = $run;
        $this->strict = $strict;
    }

    public function baseline(): CheckOutcome
    {
        $check = $this->recorded('baseline');

        return $check === null ? new CheckOutcome(MoveCheck::resolves(MoveCheck::INLINE)) : self::outcome($check);
    }

    public function check(MoveQuery $query): CheckOutcome
    {
        $this->queries[] = $query;
        if ($this->baselineRefused()) {
            return new CheckOutcome(MoveCheck::notChecked(MoveCheck::BASELINE_REFUSED));
        }
        $check = $this->recorded($query->key(), '*');

        return $check === null ? new CheckOutcome(MoveCheck::notChecked(MoveCheck::UNLOADED_NAME)) : $this->unread($check) ?? self::outcome($check, $query->package());
    }

    /**
     * The answer recorded under `joint <key> with <holders>`, the holders in name order. `joint *`
     * answers every other joint request. A request that records none is not checked.
     */
    public function joint(MoveQuery $query, array $holders): CheckOutcome
    {
        $this->queries[] = $query;
        sort($holders);
        $joint = $this->recorded('joint '.rtrim($query->key()).' with '.implode(' ', $holders), 'joint *');
        if ($joint === null) {
            return new CheckOutcome(MoveCheck::notChecked(MoveCheck::UNLOADED_NAME));
        }

        return $this->unread($joint) ?? self::outcome($joint, $query->package())->withChanges(self::installs($joint));
    }

    /** @return list<MoveQuery> each query of {@see check()} and {@see joint()}, in the order asked */
    public function queries(): array
    {
        return $this->queries;
    }

    /** As {@see \Lockrot\Solver\SolverChecker}: Composer refuses the lock itself, so lockrot asks no move of it. */
    private function baselineRefused(): bool
    {
        return (($this->checks['baseline'] ?? [])['result'] ?? null) === MoveCheck::REFUSED;
    }

    /**
     * @return array<mixed, mixed>|null
     *
     * @throws \LogicException when a strict checker gets a request that the case does not record
     */
    private function recorded(string $key, string ...$fallbacks): ?array
    {
        foreach (array_merge([$key], $fallbacks) as $candidate) {
            if (isset($this->checks[$candidate])) {
                return $this->checks[$candidate];
            }
        }
        if ($this->strict) {
            throw new \LogicException('the case records no answer to the request "'.$key.'"');
        }

        return null;
    }

    /** @param array<mixed, mixed> $check */
    private static function outcome(array $check, ?string $package = null): CheckOutcome
    {
        $holds = [];
        foreach (\is_array($check['holds'] ?? null) ? $check['holds'] : [] as $hold) {
            self::assertArray($hold);
            $source = self::required($hold, 'source');
            $link = self::required($hold, 'link');
            if (!\in_array($source, [Holder::ROOT, Holder::PACKAGE], true) || !\in_array($link, [Holder::REQUIRE, Holder::REQUIRE_DEV, Holder::CONFLICT, Holder::REPLACE, Holder::ROOT_REQUIRE, Holder::PLATFORM], true)) {
                throw new \InvalidArgumentException('a recorded hold has an unknown source or link');
            }
            $holds[] = new Holder($source, self::text($hold, 'package'), self::text($hold, 'version'), $link, self::required($hold, 'constraint'), self::text($hold, 'target'));
        }

        $outcome = self::checkOf($check, self::text($check, 'installs') ?? ($package === null ? null : self::installs($check)[$package] ?? null));

        $problem = self::text($check, 'problem') ?? 'Your requirements could not be resolved to an installable set of packages.';

        return new CheckOutcome($outcome, $holds, $outcome->isRefused() ? $problem : null);
    }

    public function combined(array $inline): CheckOutcome
    {
        $this->combinedAsked[] = $inline;
        if ($this->baselineRefused()) {
            return new CheckOutcome(MoveCheck::notChecked(MoveCheck::BASELINE_REFUSED));
        }
        $check = $this->recorded('combined '.implode(' ', array_keys($inline)), 'combined');

        return $check === null ? new CheckOutcome(MoveCheck::resolves(MoveCheck::INLINE)) : $this->unread($check) ?? self::outcome($check)->withChanges(self::installs($check));
    }

    /** @return list<array<string, string>> the argument of each combined update asked, in order */
    public function combinedAsked(): array
    {
        return $this->combinedAsked;
    }

    /**
     * As {@see \Lockrot\Solver\SolverChecker}: while Composer blocks advisories, a solve that installs
     * a name that the run's lookup did not ask is not checked. A hydrated run reads no other name.
     *
     * @param array<mixed, mixed> $check
     */
    private function unread(array $check): ?CheckOutcome
    {
        if ($this->covered === null || !$this->blocksAdvisories() || ($check['result'] ?? null) !== MoveCheck::RESOLVES) {
            return null;
        }

        return array_diff(array_keys(self::installs($check)), $this->covered) === [] ? null : new CheckOutcome(MoveCheck::notChecked(MoveCheck::ADVISORY_LOOKUP_INCOMPLETE));
    }

    public function solveAt(array $names): CheckOutcome
    {
        $check = $this->recorded('target_fix');

        return $check === null ? new CheckOutcome(MoveCheck::notChecked(MoveCheck::UNLOADED_NAME)) : self::outcome($check);
    }

    /** A case can record the solve's php, `php`, in `inputs.move_check`. */
    public function platformPhp(): ?string
    {
        return self::text($this->run, 'php');
    }

    /** A case can record `config.platform.php`, `config_php`, in `inputs.move_check`. */
    public function configuredPhp(): ?string
    {
        return self::text($this->run, 'config_php');
    }

    public function composerVersion(): ?string
    {
        return self::text($this->run, 'composer');
    }

    /** A case can record `error`, `{class, message}`, in `inputs.move_check`. */
    public function solverError(): ?array
    {
        $error = \is_array($this->run['error'] ?? null) ? $this->run['error'] : null;

        return $error === null ? null : ['class' => self::required($error, 'class'), 'message' => self::required($error, 'message')];
    }

    public function blocksAdvisories(): bool
    {
        return ($this->run['blocks_advisories'] ?? false) === true;
    }

    public function blockingDisabledBy(): array
    {
        $names = $this->run['blocking_disabled_by'] ?? [];

        return \is_array($names) ? array_values(array_filter($names, 'is_string')) : [];
    }

    public function withAdvisories(array $records, array $covered): MoveChecker
    {
        $clone = clone $this;
        $clone->covered = $covered;

        return $clone;
    }

    /** @param array<mixed, mixed> $check */
    private static function checkOf(array $check, ?string $installs): MoveCheck
    {
        switch ($check['result'] ?? null) {
            case MoveCheck::RESOLVES:
                $form = self::required($check, 'form');
                if (!\in_array($form, [MoveCheck::INLINE, MoveCheck::WITH_DEPENDENCIES, MoveCheck::WITH_ALL_DEPENDENCIES], true)) {
                    throw new \InvalidArgumentException('a recorded check has an unknown form');
                }

                return MoveCheck::resolves($form, $installs);
            case MoveCheck::REFUSED:
                return MoveCheck::refused();
            default:
                return MoveCheck::notChecked(self::required($check, 'reason'));
        }
    }

    /**
     * @param array<mixed, mixed> $check
     *
     * @return array<string, string>
     */
    private static function installs(array $check): array
    {
        $installs = [];
        foreach (\is_array($check['installs'] ?? null) ? $check['installs'] : [] as $name => $version) {
            if (\is_string($version)) {
                $installs[(string) $name] = $version;
            }
        }

        return $installs;
    }

    /** @param array<mixed, mixed> $row */
    private static function required(array $row, string $key): string
    {
        $value = self::text($row, $key);
        if ($value === null) {
            throw new \InvalidArgumentException('a recorded check has no '.$key);
        }

        return $value;
    }

    /** @param array<mixed, mixed> $row */
    private static function text(array $row, string $key): ?string
    {
        return \is_string($row[$key] ?? null) ? $row[$key] : null;
    }

    /**
     * @param mixed $value
     *
     * @phpstan-assert array<mixed, mixed> $value
     */
    private static function assertArray($value): void
    {
        if (!\is_array($value)) {
            throw new \InvalidArgumentException('a recorded hold is not an object');
        }
    }
}
