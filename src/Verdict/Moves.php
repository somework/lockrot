<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Score\IfApplied;

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

    /**
     * @param ?string                  $fixKind the fix kind of the move `move_in` names, else of the security move
     * @param array<string, IfApplied> $rows    by branch label, the rows whose candidate a move can reach
     */
    public function __construct(?Move $nextStep, ?string $moveIn, ?string $fixKind, ?SecurityPartial $partial, array $rows)
    {
        $this->nextStep = $nextStep;
        $this->moveIn = $moveIn;
        $this->fixKind = $fixKind;
        $this->partial = $partial;
        $this->rows = $rows;
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

    /** @param array<string, GradedPackage> $findings by package name */
    public function withFindings(array $findings): self
    {
        $clone = clone $this;
        $clone->nextStep = $this->nextStep === null ? null : $this->nextStep->withFindings($findings);

        return $clone;
    }
}
