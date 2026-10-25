<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

/**
 * Another finding of the same report, as a move names it: a holder (`held_by[].holder`) or a
 * replacement (`replacement.finding`, without `replacement` and `next_step`). For display only: no
 * score reads it.
 *
 * @internal
 */
final class GradedPackage implements \JsonSerializable
{
    private string $verdict;
    private ?string $lead;
    /** @var list<string> */
    private array $flagIds;
    private ?string $replacement;
    private ?Move $move;
    private string $version;
    private bool $short = false;

    /**
     * @param list<string> $flagIds the counted flags
     * @param string       $version the locked version that the finding grades
     */
    public function __construct(string $verdict, ?string $lead, array $flagIds, ?string $replacement, ?Move $move, string $version)
    {
        $this->version = $version;
        $this->verdict = $verdict;
        $this->lead = $lead;
        $this->flagIds = $flagIds;
        $this->replacement = $replacement;
        $this->move = $move;
    }

    public static function of(Finding $finding): self
    {
        return new self($finding->verdict(), $finding->lead(), $finding->flagIds(), $finding->countedSuccessor(), $finding->nextStep(), $finding->version());
    }

    /** `replacement.finding`: the verdict, the lead and the flags. */
    public function asReplacement(): self
    {
        $clone = clone $this;
        $clone->short = true;

        return $clone;
    }

    public function verdict(): string
    {
        return $this->verdict;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function isGraded(): bool
    {
        return \in_array($this->verdict, ScoreModel::GRADES, true);
    }

    public function lead(): ?string
    {
        return $this->lead;
    }

    /** @return list<string> */
    public function flagIds(): array
    {
        return $this->flagIds;
    }

    /** @return array{verdict: string, lead: ?string, flag_ids: list<string>, replacement?: ?string, next_step?: ?array{kind: string, to_branch: ?string, constraint: ?string, check: ?\Lockrot\Solver\MoveCheck}} */
    public function jsonSerialize(): array
    {
        $out = ['verdict' => $this->verdict, 'lead' => $this->lead, 'flag_ids' => $this->flagIds];
        if ($this->short) {
            return $out;
        }
        $move = $this->move;

        return $out + [
            'replacement' => $this->replacement,
            'next_step' => $move === null ? null : ['kind' => $move->kind(), 'to_branch' => $move->toBranch(), 'constraint' => $move->constraint(), 'check' => $move->check()],
        ];
    }
}
