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
 * their `problem` text. A key is a {@see MoveQuery::key()}, `combined <names>`, `baseline` or
 * `target_fix` ({@see solveAt()}). `*` and `combined` answer every other request and combined
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

    /**
     * @param array<string, array<mixed, mixed>> $checks key => `{result, form, reason, holds, problem}`
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
        if ($this->baselineRefused()) {
            return new CheckOutcome(MoveCheck::notChecked(MoveCheck::BASELINE_REFUSED));
        }
        $check = $this->recorded($query->key(), '*');

        return $check === null ? new CheckOutcome(MoveCheck::notChecked(MoveCheck::UNLOADED_NAME)) : self::outcome($check);
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
    private static function outcome(array $check): CheckOutcome
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

        $outcome = self::checkOf($check);

        $problem = self::text($check, 'problem') ?? 'Your requirements could not be resolved to an installable set of packages.';

        return new CheckOutcome($outcome, $holds, null, $outcome->isRefused() ? $problem : null);
    }

    public function combined(array $inline): CheckOutcome
    {
        if ($this->baselineRefused()) {
            return new CheckOutcome(MoveCheck::notChecked(MoveCheck::BASELINE_REFUSED));
        }
        $check = $this->recorded('combined '.implode(' ', array_keys($inline)), 'combined');

        return $check === null ? new CheckOutcome(MoveCheck::resolves(MoveCheck::INLINE)) : self::outcome($check);
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

    public function withAdvisories(array $records, array $covered): MoveChecker
    {
        return $this;
    }

    /** @param array<mixed, mixed> $check */
    private static function checkOf(array $check): MoveCheck
    {
        switch ($check['result'] ?? null) {
            case MoveCheck::RESOLVES:
                $form = self::required($check, 'form');
                if (!\in_array($form, [MoveCheck::INLINE, MoveCheck::WITH_DEPENDENCIES, MoveCheck::WITH_ALL_DEPENDENCIES], true)) {
                    throw new \InvalidArgumentException('a recorded check has an unknown form');
                }

                return MoveCheck::resolves($form);
            case MoveCheck::REFUSED:
                return MoveCheck::refused();
            default:
                return MoveCheck::notChecked(self::required($check, 'reason'));
        }
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
