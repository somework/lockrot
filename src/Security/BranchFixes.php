<?php

declare(strict_types=1);

namespace Lockrot\Security;

/**
 * A release branch's `fixes`: what a move onto the branch
 * clears, from its lower bound, and what holds that move.
 *
 * @internal
 */
final class BranchFixes
{
    private string $branch;
    private bool $installed;
    private int $fixed;
    private int $unknown;
    private int $of;
    private ?Candidate $candidate;
    private ?string $newest;
    /** @var list<string> */
    private array $clears;

    /**
     * @param ?Candidate   $candidate the branch's lower bound with its class and holders, null when the branch fixes nothing
     * @param list<string> $clears    the ids of the counted advisories that the branch fixes
     */
    public function __construct(string $branch, bool $installed, int $fixed, int $unknown, int $of, ?Candidate $candidate, ?string $newest, array $clears)
    {
        $this->branch = $branch;
        $this->installed = $installed;
        $this->fixed = $fixed;
        $this->unknown = $unknown;
        $this->of = $of;
        $this->candidate = $candidate;
        $this->newest = $newest;
        $this->clears = $clears;
    }

    /** `ReleaseBranch::label()`, `2.x`. */
    public function branch(): string
    {
        return $this->branch;
    }

    public function installed(): bool
    {
        return $this->installed;
    }

    /**
     * Counted advisories that the branch's newest release lies outside of: the branch has a lower
     * bound for each. One that a later release on the branch reintroduces is not fixed on it.
     */
    public function fixed(): int
    {
        return $this->fixed;
    }

    /** Counted advisories with no affected range: lockrot cannot judge them on any branch. */
    public function unknown(): int
    {
        return $this->unknown;
    }

    public function of(): int
    {
        return $this->of;
    }

    /**
     * The class of the branch's lower bound ({@see candidate()}), so the row, a move to the branch
     * and the fix of that release agree. Null when the branch fixes nothing.
     *
     * @return Fix::UPDATE|Fix::UPGRADE|Fix::RAISE_PHP|Fix::BLOCKED|null
     */
    public function fixKind(): ?string
    {
        return $this->candidate === null ? null : $this->candidate->kind();
    }

    /** The lowest release from which every release up to the branch's newest lies outside every range the branch fixes. */
    public function lowest(): ?string
    {
        return $this->candidate === null ? null : $this->candidate->release()->pretty();
    }

    public function candidate(): ?Candidate
    {
        return $this->candidate;
    }

    /** The branch's newest stable release: what `composer update` installs on it. */
    public function newest(): ?string
    {
        return $this->newest;
    }

    /** @return list<string> */
    public function clears(): array
    {
        return $this->clears;
    }
}
