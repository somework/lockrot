<?php

declare(strict_types=1);

namespace Lockrot\Security;

use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Data\Repository\StableRelease;

/**
 * A release the scan could move to, with its class and what holds it (SPEC-0.14 5.3). The class
 * reads only PHP; the links fill {@see heldBy()} for every class.
 *
 * @internal
 */
final class Candidate
{
    private StableRelease $release;
    private string $branch;
    /** @var Fix::UPDATE|Fix::UPGRADE|Fix::RAISE_PHP|Fix::BLOCKED */
    private string $kind;
    /** @var list<Holder> */
    private array $heldBy;

    /**
     * @param string                                             $branch {@see ReleaseBranch::of()} of the release
     * @param Fix::UPDATE|Fix::UPGRADE|Fix::RAISE_PHP|Fix::BLOCKED $kind
     * @param list<Holder>                                       $heldBy
     */
    public function __construct(StableRelease $release, string $branch, string $kind, array $heldBy)
    {
        $this->release = $release;
        $this->branch = $branch;
        $this->kind = $kind;
        $this->heldBy = $heldBy;
    }

    public function release(): StableRelease
    {
        return $this->release;
    }

    /** The branch key, `3` or `0.15`. */
    public function branchKey(): string
    {
        return $this->branch;
    }

    /** The branch as `ReleaseBranch::label()` writes it, `3.x`. */
    public function branch(): string
    {
        return ReleaseBranch::label($this->branch);
    }

    /** @return Fix::UPDATE|Fix::UPGRADE|Fix::RAISE_PHP|Fix::BLOCKED */
    public function kind(): string
    {
        return $this->kind;
    }

    /** @return list<Holder> */
    public function heldBy(): array
    {
        return $this->heldBy;
    }

    public function php(): ?string
    {
        return $this->release->php();
    }

    /** {@see Fix::BLOCKED_BY_TARGET} for a blocked candidate, else null. */
    public function blockedBy(): ?string
    {
        return $this->kind === Fix::BLOCKED ? Fix::BLOCKED_BY_TARGET : null;
    }

    public function easeRank(): int
    {
        return Fix::easeRank($this->kind, $this->heldBy !== []);
    }
}
