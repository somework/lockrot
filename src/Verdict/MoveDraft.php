<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Security\Candidate;
use Lockrot\Signal\BranchRow;

/**
 * A move before {@see MoveBuilder} judges it: its kind, its source flag and where it goes. The
 * lower bound is the release the move names, with its class and holders.
 *
 * @internal
 */
final class MoveDraft
{
    private string $kind;
    private string $source;
    private ?BranchRow $row = null;
    private ?Candidate $lower = null;
    private ?string $constraint = null;
    private ?string $fixKind = null;
    private ?string $reason = null;
    private ?Replacement $replacement = null;
    private ?Latest $latest = null;

    private function __construct(string $kind, string $source)
    {
        $this->kind = $kind;
        $this->source = $source;
    }

    public static function of(string $kind, string $source): self
    {
        return new self($kind, $source);
    }

    /**
     * A move to a branch: the lowest release it admits, and the constraint (null for `update` and `blocked`).
     */
    public function to(BranchRow $row, ?Candidate $lower, ?string $constraint): self
    {
        $clone = clone $this;
        $clone->row = $row;
        $clone->lower = $lower;
        $clone->constraint = $constraint;

        return $clone;
    }

    public function withFixKind(?string $fixKind): self
    {
        $clone = clone $this;
        $clone->fixKind = $fixKind;

        return $clone;
    }

    public function withReason(string $reason): self
    {
        $clone = clone $this;
        $clone->reason = $reason;

        return $clone;
    }

    public function withReplacement(Replacement $replacement): self
    {
        $clone = clone $this;
        $clone->replacement = $replacement;

        return $clone;
    }

    public function withLatest(Latest $latest, ?BranchRow $row): self
    {
        $clone = clone $this;
        $clone->latest = $latest;
        $clone->row = $row;

        return $clone;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function row(): ?BranchRow
    {
        return $this->row;
    }

    public function lower(): ?Candidate
    {
        return $this->lower;
    }

    public function constraint(): ?string
    {
        return $this->constraint;
    }

    public function fixKind(): ?string
    {
        return $this->fixKind;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function replacement(): ?Replacement
    {
        return $this->replacement;
    }

    public function latest(): ?Latest
    {
        return $this->latest;
    }

    /** Whether the move goes to a branch row that the scan read: `update`, `require` or `raise-php`. */
    public function isRowMove(): bool
    {
        return $this->row !== null && \in_array($this->kind, Move::ROW_KINDS, true);
    }
}
