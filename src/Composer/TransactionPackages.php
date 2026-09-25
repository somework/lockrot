<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Transaction;
use Composer\Package\AliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\PackageInterface;
use Lockrot\Lock\LockedPackage;

/**
 * The packages a Composer transaction is about to put on disk, in the shape the analyzer reads.
 *
 * Only the two operations that introduce or change a package are read: InstallOperation's package
 * and UpdateOperation's target. Uninstall and the two alias-marker operations are skipped — a
 * package being removed, or an alias marker being flipped, cannot bring new dependency rot into the
 * project.
 *
 * @internal
 */
final class TransactionPackages
{
    /**
     * Every entry comes back flagged as a production package, and that is not something this class
     * can improve on: a transaction is built from two flat package lists and exposes only
     * `getOperations()`, while the operations themselves expose only the packages. Nothing in that
     * chain records which of the root's `require` / `require-dev` sections pulled a package in.
     *
     * `PackageInterface::isDev()` is not that flag either — it answers "is this a development
     * *virtual* package or a concrete one", i.e. whether the version is a branch snapshot, which is
     * the `pinned` verdict's question, not this one.
     *
     * So the lock is the only source that knows, and the caller re-resolves the flag through it —
     * see {@see InstallTimeSummary::withDevFlagsFrom()}.
     *
     * @return list<LockedPackage> packages being installed or updated, in operation order, all
     *                             flagged prod; uninstall and alias-marker operations are ignored
     */
    public static function fromTransaction(Transaction $transaction): array
    {
        /** @var array<string, LockedPackage> $packages */
        $packages = [];
        foreach ($transaction->getOperations() as $operation) {
            $package = self::packageOf($operation);
            if ($package === null) {
                continue;
            }
            // Transaction::calculateOperations() emits a MarkAliasInstalledOperation for the alias
            // itself and a separate InstallOperation for the package it points at, so an alias
            // reaching this point is defensive rather than expected; unwrapping it keeps the locked
            // entry's own version/require/source fields as the ones that get read.
            if ($package instanceof AliasPackage) {
                $package = $package->getAliasOf();
            }
            // The solver only ever produces CompletePackage instances, so this is a type narrowing
            // for PHPStan rather than a case that happens in practice; anything else is skipped
            // because LockedPackage has no complete metadata (abandoned flag, type) to read from it.
            if (!$package instanceof CompletePackage) {
                continue;
            }
            $name = $package->getName();
            if (isset($packages[$name])) {
                continue;
            }
            $packages[$name] = LockedPackage::fromPackage($package, false);
        }

        return array_values($packages);
    }

    private static function packageOf(OperationInterface $operation): ?PackageInterface
    {
        if ($operation instanceof InstallOperation) {
            return $operation->getPackage();
        }
        if ($operation instanceof UpdateOperation) {
            return $operation->getTargetPackage();
        }

        return null;
    }
}
