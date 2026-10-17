<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Package\BasePackage;
use Composer\Package\RootPackageInterface;
use Composer\Repository\LockArrayRepository;
use Composer\Repository\PlatformRepository;
use Composer\Semver\Constraint\Constraint;
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
    /** @var array<string, BasePackage> */
    private array $locked = [];

    public function __construct(RootPackageInterface $root, LockArrayRepository $locked)
    {
        $this->root = $root;
        foreach ($locked->getPackages() as $package) {
            $this->locked[$package->getName()] ??= $package;
        }
    }

    /** @return list<Holder> */
    public function of(BasePackage $target, ?string $targetPhp): array
    {
        $holds = [];
        $version = $target->getPrettyVersion();
        $rootRequires = array_merge($this->root->getDevRequires(), $this->root->getRequires());
        foreach ($target->getConflicts() as $name => $link) {
            $locked = $this->locked[$name] ?? null;
            if ($locked !== null && $link->getConstraint()->matches(new Constraint('==', $locked->getVersion()))) {
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
            $locked = $this->locked[$name] ?? null;
            if (PlatformRepository::isPlatformPackage($name) || $locked === null || $link->getConstraint()->matches(new Constraint('==', $locked->getVersion()))) {
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
}
