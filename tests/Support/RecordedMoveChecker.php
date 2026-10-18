<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Security\Holder;
use Lockrot\Solver\CheckOutcome;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveChecker;
use Lockrot\Solver\MoveQuery;

/**
 * The move checks that a case records (`inputs.checks`, keyed by {@see MoveQuery::key()}), as
 * Composer's solver answered them. lockrot also asks about moves that it does not publish, so a
 * request that the case does not record is `not_checked`: a published one then differs from the
 * case's move. A test can record `*`: the answer to every request that it does not record, and
 * `combined`: the answer to the combined update, which resolves when the case records none.
 */
final class RecordedMoveChecker implements MoveChecker
{
    /** @var array<string, array<mixed, mixed>> */
    private array $checks;
    /** @var array<mixed, mixed> */
    private array $run;

    /**
     * @param array<string, array<mixed, mixed>> $checks key => `{result, form, reason, holds}`
     * @param array<mixed, mixed>                $run    the case's `run.move_check`
     */
    public function __construct(array $checks, array $run)
    {
        $this->checks = $checks;
        $this->run = $run;
    }

    public function baseline(): CheckOutcome
    {
        return new CheckOutcome(MoveCheck::resolves(MoveCheck::INLINE));
    }

    public function check(MoveQuery $query): CheckOutcome
    {
        $check = $this->checks[$query->key()] ?? $this->checks['*'] ?? null;

        return $check === null ? new CheckOutcome(MoveCheck::notChecked(MoveCheck::UNLOADED_NAME)) : self::outcome($check);
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

        return new CheckOutcome($outcome, $holds, null, $outcome->isRefused() ? 'Your requirements could not be resolved to an installable set of packages.' : null);
    }

    public function combined(array $inline): CheckOutcome
    {
        $check = $this->checks['combined'] ?? null;

        return $check === null ? new CheckOutcome(MoveCheck::resolves(MoveCheck::INLINE)) : self::outcome($check);
    }

    public function platformPhp(): ?string
    {
        return null;
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
