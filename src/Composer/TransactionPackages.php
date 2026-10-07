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
 * Only InstallOperation's package and UpdateOperation's target are read: an uninstall or an alias
 * marker cannot bring new dependency rot into the project.
 *
 * @internal
 */
final class TransactionPackages
{
    /**
     * Every entry is flagged prod. A transaction and its operations record no `require` or
     * `require-dev` section, and `PackageInterface::isDev()` tells whether a version is a branch
     * snapshot, which is the question of the `pinned` verdict. The caller resolves the flag through
     * the lock ({@see InstallTimeSummary::withDevFlagsFrom()}).
     *
     * @return list<LockedPackage> in operation order
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
            // Defensive: Transaction::calculateOperations() emits a separate InstallOperation for
            // the package that an alias points at. Unwrapping keeps the package's own version,
            // require and source fields.
            $aliasVersions = [];
            if ($package instanceof AliasPackage) {
                if (!$package->isRootPackageAlias()) {
                    $aliasVersions[] = $package->getVersion();
                }
                $package = $package->getAliasOf();
            }
            // The solver produces CompletePackage only, so this narrows the type for PHPStan.
            // LockedPackage needs the complete metadata (abandoned flag, type) of that class.
            if (!$package instanceof CompletePackage) {
                continue;
            }
            $name = $package->getName();
            if (isset($packages[$name])) {
                $packages[$name] = $packages[$name]->withAliasVersions(array_values(array_unique(array_merge($packages[$name]->aliasVersions(), $aliasVersions))));
                continue;
            }
            $packages[$name] = LockedPackage::fromPackage($package, false)->withAliasVersions($aliasVersions);
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
