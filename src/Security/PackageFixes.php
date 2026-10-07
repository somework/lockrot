<?php

declare(strict_types=1);

namespace Lockrot\Security;

/**
 * What the release scan found for one package: a fix per
 * counted advisory, a row per release branch, what `composer update` gets, the security move and a
 * partial update. Nothing here is serialised: the report layer writes it.
 *
 * @internal
 */
final class PackageFixes
{
    /** @var array<string, Fix> */
    private array $fixes;
    /** @var list<BranchFixes> */
    private array $branches;
    private ?Gets $gets;
    private ?Candidate $move;
    private ?Partial $partial;

    /**
     * @param array<string, Fix>  $fixes    by advisory id, in the order the advisories came
     * @param list<BranchFixes>   $branches highest branch first
     */
    public function __construct(array $fixes, array $branches, ?Gets $gets, ?Candidate $move, ?Partial $partial)
    {
        $this->fixes = $fixes;
        $this->branches = $branches;
        $this->gets = $gets;
        $this->move = $move;
        $this->partial = $partial;
    }

    /** @return array<string, Fix> */
    public function fixes(): array
    {
        return $this->fixes;
    }

    /** @throws \OutOfBoundsException for an id that the caller did not give the scan */
    public function forAdvisory(string $id): Fix
    {
        if (!isset($this->fixes[$id])) {
            throw new \OutOfBoundsException('The release scan was given no advisory '.$id.'.');
        }

        return $this->fixes[$id];
    }

    /**
     * Every release branch of the package, highest first. A branch below the installed one has no
     * candidate. Empty when lockrot did not read the releases.
     *
     * @return list<BranchFixes>
     */
    public function branches(): array
    {
        return $this->branches;
    }

    /** The installed branch's row, `security.installed_branch_fixes`. Null for a branch snapshot or unread releases. */
    public function installedBranch(): ?BranchFixes
    {
        foreach ($this->branches as $row) {
            if ($row->installed()) {
                return $row;
            }
        }

        return null;
    }

    public function gets(): ?Gets
    {
        return $this->gets;
    }

    /**
     * The easiest branch lower bound that clears every counted range, the lowest at its ease: the
     * installed or a higher branch's {@see BranchFixes::candidate()}. Null when no branch clears
     * them all, or no counted advisory has a range.
     */
    public function move(): ?Candidate
    {
        return $this->move;
    }

    public function partial(): ?Partial
    {
        return $this->partial;
    }
}
