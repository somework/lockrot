<?php

declare(strict_types=1);

namespace Lockrot\Security;

use Lockrot\Verdict\GradedPackage;

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
    private ?string $newestOnInstalledBranch;

    /**
     * @param array<string, Fix>  $fixes                   by advisory id, in the order the advisories came
     * @param list<BranchFixes>   $branches                highest branch first
     * @param ?string             $newestOnInstalledBranch see {@see newestOnInstalledBranch()}
     */
    public function __construct(array $fixes, array $branches, ?Gets $gets, ?Candidate $move, ?Partial $partial, ?string $newestOnInstalledBranch = null)
    {
        $this->newestOnInstalledBranch = $newestOnInstalledBranch;
        $this->fixes = $fixes;
        $this->branches = $branches;
        $this->gets = $gets;
        $this->move = $move;
        $this->partial = $partial;
    }

    /**
     * The holders of every candidate, each with its own finding: the report fills them once every
     * finding is graded.
     *
     * @param array<string, GradedPackage> $findings by package name
     */
    public function withFindings(array $findings): self
    {
        return new self(
            array_map(static fn (Fix $fix): Fix => $fix->withFindings($findings), $this->fixes),
            array_map(static fn (BranchFixes $row): BranchFixes => $row->withFindings($findings), $this->branches),
            $this->gets,
            $this->move === null ? null : $this->move->withFindings($findings),
            $this->partial === null ? null : $this->partial->withFindings($findings),
            $this->newestOnInstalledBranch
        );
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
     * The highest release on the installed branch that the lock's links allow and the target runs:
     * what `composer update` installs there. A refusal of the move check does not lower it, and a
     * solve that installs a lower release names it in `check.installs`.
     */
    public function newestOnInstalledBranch(): ?string
    {
        return $this->newestOnInstalledBranch;
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
