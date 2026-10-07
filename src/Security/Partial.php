<?php

declare(strict_types=1);

namespace Lockrot\Security;

/**
 * The `update` release on the installed branch that clears some, not all, counted advisories,
 * when the security move is not an `update` (`security.partial` of SPEC-0.14 5.3). The engine
 * rerun and `unverified[]` are the move layer's.
 *
 * @internal
 */
final class Partial
{
    private Candidate $release;
    /** @var list<string> */
    private array $clears;

    /** @param list<string> $clears advisory ids, some and not all of the counted ones */
    public function __construct(Candidate $release, array $clears)
    {
        $this->release = $release;
        $this->clears = $clears;
    }

    public function version(): string
    {
        return $this->release->release()->pretty();
    }

    public function release(): Candidate
    {
        return $this->release;
    }

    /** @return list<string> */
    public function clears(): array
    {
        return $this->clears;
    }
}
