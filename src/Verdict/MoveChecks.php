<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Composer\Semver\Constraint\ConstraintInterface;
use Lockrot\Security\RefusedMoves;
use Lockrot\Solver\CheckOutcome;
use Lockrot\Solver\ComposerFeatures;
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
    private bool $inlineOnAnyPackage;
    /** @var array<string, array{0: MoveQuery, 1: CheckOutcome}> */
    private array $asked = [];

    /** @param bool $inlineOnAnyPackage whether the Composer that runs the command takes an inline constraint on a package that is not a root requirement */
    public function __construct(MoveChecker $checker, bool $inlineOnAnyPackage)
    {
        $this->checker = $checker;
        $this->inlineOnAnyPackage = $inlineOnAnyPackage;
    }

    /**
     * The command runs on the Composer that solves. Composer 2.2 and 2.3 refuse an inline
     * constraint on a package that composer.json does not require (`Only root package
     * requirements can receive temporary constraints`).
     */
    public static function of(MoveChecker $checker): self
    {
        return new self($checker, ComposerFeatures::hasTemporaryConstraints());
    }

    public function checker(): MoveChecker
    {
        return $this->checker;
    }

    /**
     * @param array<string, list<ConstraintInterface>> $ranges by package name
     */
    public function withBlockedAdvisories(array $ranges): self
    {
        return new self($this->checker->withBlockedAdvisories($ranges), $this->inlineOnAnyPackage);
    }

    public function check(MoveQuery $query): CheckOutcome
    {
        $key = $query->key();
        if (!isset($this->asked[$key])) {
            $this->asked[$key] = [$query, $this->checker->check($query)];
        }

        return $this->asked[$key][1];
    }

    /** Whether an `update` step names its constraint inline (`composer update vendor/pkg:^1.2`). */
    public function writesInline(bool $isRootRequirement): bool
    {
        return $this->inlineOnAnyPackage || $isRootRequirement;
    }

    /** The refusals of one package so far. */
    public function refused(string $package): RefusedMoves
    {
        $refused = RefusedMoves::none();
        foreach ($this->asked as [$query, $outcome]) {
            if ($query->package() === $package && $outcome->check()->result() === MoveCheck::REFUSED) {
                $refused = $refused->with($query->constraint(), $outcome->holds());
            }
        }

        return $refused;
    }
}
