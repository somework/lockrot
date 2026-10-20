<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\Package\BasePackage;
use Composer\Semver\Constraint\ConstraintInterface;

/**
 * What Composer's pool optimizer and solver read of a built pool and its request: equal inputs get
 * one answer. A package is its object, and the root is the same for every copy, which differs
 * only in the links that the request requires. Two objects that are alive at the same time never
 * share an id, so the caller keeps the pool of the inputs that it compares against.
 *
 * @internal
 */
final class SolverInputs
{
    public static function of(Pool $pool, Request $request, BasePackage $root): string
    {
        $id = static fn (BasePackage $package): string => $package === $root ? 'root' : (string) spl_object_id($package);

        return serialize([
            array_map($id, $pool->getPackages()),
            array_map($id, $pool->getUnacceptableFixedOrLockedPackages()),
            array_map($id, array_values($request->getFixedPackages())),
            array_map($id, $request->getLockedPackages()),
            array_map(static fn (ConstraintInterface $constraint): array => [$constraint->__toString(), $constraint->getPrettyString()], $request->getRequires()),
        ]);
    }
}
