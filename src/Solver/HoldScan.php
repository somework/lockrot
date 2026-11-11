<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Package\BasePackage;
use Composer\Package\RootPackageInterface;
use Composer\Repository\LockArrayRepository;
use Composer\Repository\PlatformRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Intervals;
use Lockrot\Security\Holder;

/**
 * The holds of a refused move that `composer prohibits` finds and the solver's first failing
 * chain can miss: a conflict of the target release on a locked package, and a requirement of the
 * target release that the locked version of a dependency does not meet, with the root requirement
 * that keeps that dependency there, as `composer prohibits` names it. The other direction of a
 * conflict, a locked package's conflict on the moved package, is a link of the lock
 * ({@see \Lockrot\Security\LinkIndex}).
 *
 * @internal
 */
final class HoldScan
{
    private RootPackageInterface $root;
    /** @var array<string, list<string>> name => the normalized version of the locked package and of each of its aliases */
    private array $locked = [];

    public function __construct(RootPackageInterface $root, LockArrayRepository $locked)
    {
        $this->root = $root;
        foreach ($locked->getPackages() as $package) {
            $this->locked[$package->getName()][] = $package->getVersion();
        }
    }

    /** @return list<Holder> */
    public function of(BasePackage $target, ?string $targetPhp): array
    {
        $holds = [];
        $version = $target->getPrettyVersion();
        $rootRequires = ComposerSolver::rootLinks($this->root);
        foreach ($target->getConflicts() as $name => $link) {
            if ($this->isLockedIn($name, $link->getConstraint())) {
                $holds[] = new Holder(Holder::PACKAGE, $target->getName(), $version, Holder::CONFLICT, $link->getPrettyConstraint(), $name);
            }
        }
        foreach ($target->getRequires() as $name => $link) {
            if ($name === 'php') {
                if ($targetPhp !== null && !$link->getConstraint()->matches(new Constraint('==', $targetPhp))) {
                    $holds[] = new Holder(Holder::PACKAGE, $target->getName(), $version, Holder::PLATFORM, $link->getPrettyConstraint(), $name);
                }
                continue;
            }
            if (PlatformRepository::isPlatformPackage($name) || !isset($this->locked[$name]) || $this->isLockedIn($name, $link->getConstraint())) {
                continue;
            }
            $holds[] = new Holder(Holder::PACKAGE, $target->getName(), $version, Holder::REQUIRE, $link->getPrettyConstraint(), $name);
            $byRoot = $rootRequires[$name] ?? null;
            if ($byRoot !== null && !Intervals::haveIntersections($byRoot->getConstraint(), $link->getConstraint())) {
                $holds[] = new Holder(Holder::ROOT, $this->root->getName(), null, Holder::ROOT_REQUIRE, $byRoot->getPrettyConstraint(), $name);
            }
        }

        return $holds;
    }

    /** Whether $constraint matches the locked package of $name: Composer matches a link with the package or with any of its aliases. */
    private function isLockedIn(string $name, ConstraintInterface $constraint): bool
    {
        foreach ($this->locked[$name] ?? [] as $version) {
            if ($constraint->matches(new Constraint('==', $version))) {
                return true;
            }
        }

        return false;
    }
}
