<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Composer\Advisory\PartialSecurityAdvisory;
use Lockrot\Security\RefusedMoves;
use Lockrot\Solver\CheckOutcome;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveChecker;
use Lockrot\Solver\MoveQuery;

/**
 * The run's move checks: each Composer request is asked once, and each refusal is kept for the
 * release scan of its package ({@see RefusedMoves}).
 *
 * @internal
 */
final class MoveChecks
{
    private MoveChecker $checker;
    /** @var array<string, array{0: MoveQuery, 1: CheckOutcome}> */
    private array $asked = [];
    private bool $asksNew = true;

    private function __construct(MoveChecker $checker)
    {
        $this->checker = $checker;
    }

    public static function of(MoveChecker $checker): self
    {
        return new self($checker);
    }

    public function checker(): MoveChecker
    {
        return $this->checker;
    }

    /**
     * @param array<string, list<PartialSecurityAdvisory>> $records every record of each name
     * @param list<string>                                 $covered the names that the run's lookup asked
     */
    public function withAdvisories(array $records, array $covered): self
    {
        return new self($this->checker->withAdvisories($records, $covered));
    }

    /** A copy that answers only the requests asked so far, and leaves every other one not checked. */
    public function answeredOnly(): self
    {
        $clone = clone $this;
        $clone->asksNew = false;

        return $clone;
    }

    public function check(MoveQuery $query): CheckOutcome
    {
        $key = $query->key();
        if (!isset($this->asked[$key]) && !$this->asksNew) {
            return new CheckOutcome(MoveCheck::notChecked(MoveCheck::PASS_CAP));
        }
        if (!isset($this->asked[$key])) {
            $this->asked[$key] = [$query, $this->joined($query, $this->checker->check($query)->withoutAdmitting($query->version()))->withoutAdmitting($query->version())->withoutRootOnHolders()];
        }

        return $this->asked[$key][1];
    }

    /** A refused move gets one more request, which also updates the packages that hold it. */
    private function joined(MoveQuery $query, CheckOutcome $outcome): CheckOutcome
    {
        $holders = $outcome->check()->isRefused() ? $outcome->holders($query->package()) : [];
        if ($holders === []) {
            return $outcome;
        }

        return $outcome->joinedWith($this->checker->joint($query, $holders), $query->package(), $query->constraint(), $holders);
    }

    /** The refusals of one package so far. */
    public function refused(string $package): RefusedMoves
    {
        $refused = RefusedMoves::none();
        foreach ($this->asked as [$query, $outcome]) {
            if ($query->package() === $package && $outcome->check()->result() === MoveCheck::REFUSED) {
                $refused = $refused->with($query->constraint(), $outcome->holds(), $outcome->range());
            }
        }

        return $refused;
    }
}
