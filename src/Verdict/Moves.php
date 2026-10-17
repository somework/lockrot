<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Score\IfApplied;
use Lockrot\Solver\MoveCheck;

/**
 * The moves of one finding: `next_step` with its `also[]`, where the move that clears the
 * advisories is published (`security.move_in`), its fix kind, `security.partial`, and the score
 * after a move to each branch row's candidate.
 *
 * @internal
 */
final class Moves
{
    public const IN_NEXT_STEP = 'next_step';
    public const IN_ALSO = 'also';

    private ?Move $nextStep;
    private ?string $moveIn;
    private ?string $fixKind;
    private ?SecurityPartial $partial;
    /** @var array<string, IfApplied> */
    private array $rows;
    /** @var array<string, MoveCheck> */
    private array $rowChecks;

    /**
     * @param ?string                  $fixKind   the fix kind of the move `move_in` names, else of the security move
     * @param array<string, IfApplied> $rows      by branch label, the rows whose candidate a move can reach
     * @param array<string, MoveCheck> $rowChecks by branch label, Composer's check of the move to each row's candidate
     */
    public function __construct(?Move $nextStep, ?string $moveIn, ?string $fixKind, ?SecurityPartial $partial, array $rows, array $rowChecks = [])
    {
        $this->nextStep = $nextStep;
        $this->moveIn = $moveIn;
        $this->fixKind = $fixKind;
        $this->partial = $partial;
        $this->rows = $rows;
        $this->rowChecks = $rowChecks;
    }

    public static function none(): self
    {
        return new self(null, null, null, null, []);
    }

    public function nextStep(): ?Move
    {
        return $this->nextStep;
    }

    public function moveIn(): ?string
    {
        return $this->moveIn;
    }

    /** The move that `move_in` names: the next step or its first other move. Null when no move clears the counted advisories. */
    public function securityMove(): ?Move
    {
        if ($this->moveIn === null || $this->nextStep === null) {
            return null;
        }

        return $this->moveIn === self::IN_ALSO ? $this->nextStep->also()[0] : $this->nextStep;
    }

    public function fixKind(): ?string
    {
        return $this->fixKind;
    }

    public function partial(): ?SecurityPartial
    {
        return $this->partial;
    }

    /** The score after a move to the row's candidate, null when the row fixes nothing or is `blocked`. */
    public function rowIfApplied(string $branch): ?IfApplied
    {
        return $this->rows[$branch] ?? null;
    }

    /** Composer's check of the move to the row's candidate, null when the run checks no move or the row's move runs no request. */
    public function rowCheck(string $branch): ?MoveCheck
    {
        return $this->rowChecks[$branch] ?? null;
    }

    /** @param array<string, GradedPackage> $findings by package name */
    public function withFindings(array $findings): self
    {
        $clone = clone $this;
        $clone->nextStep = $this->nextStep === null ? null : $this->nextStep->withFindings($findings);

        return $clone;
    }
}
