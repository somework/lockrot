<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Composer;

use Composer\DependencyResolver\Transaction;
use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Composer\TransactionPackages;
use Lockrot\Lock\LockedPackage;
use PHPUnit\Framework\TestCase;

final class TransactionPackagesTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function package(string $name, string $version, array $overrides = []): BasePackage
    {
        return (new ArrayLoader())->load(array_merge([
            'name' => $name,
            'version' => $version,
            // Without a notification-url the package would read as "not from a Composer repository".
            'notification-url' => 'https://packagist.org/downloads/',
        ], $overrides));
    }

    /**
     * @param list<LockedPackage> $packages
     *
     * @return list<string>
     */
    private function names(array $packages): array
    {
        $names = array_map(static fn (LockedPackage $package): string => $package->name(), $packages);
        sort($names);

        return $names;
    }

    public function testInstallOperationsBecomeLockedPackages(): void
    {
        $transaction = new Transaction([], [$this->package('vendor/a', '1.0.0'), $this->package('vendor/b', '2.0.0')]);

        self::assertSame(['vendor/a', 'vendor/b'], $this->names(TransactionPackages::fromTransaction($transaction)));
    }

    public function testUpdateOperationReportsTheTargetVersion(): void
    {
        $transaction = new Transaction([$this->package('vendor/a', '1.0.0')], [$this->package('vendor/a', '2.0.0')]);

        $packages = TransactionPackages::fromTransaction($transaction);

        self::assertCount(1, $packages);
        self::assertSame('vendor/a', $packages[0]->name());
        self::assertSame('2.0.0', $packages[0]->version());
    }

    public function testUninstallOnlyTransactionYieldsNoPackages(): void
    {
        $transaction = new Transaction([$this->package('vendor/a', '1.0.0')], []);

        self::assertSame([], TransactionPackages::fromTransaction($transaction));
    }

    public function testAnAliasedPackageIsReportedOnceAtItsLockedVersion(): void
    {
        $aliased = $this->package('vendor/a', 'dev-main', ['extra' => ['branch-alias' => ['dev-main' => '2.x-dev']]]);
        $transaction = new Transaction([], [$aliased]);

        $packages = TransactionPackages::fromTransaction($transaction);

        self::assertCount(1, $packages);
        self::assertSame('vendor/a', $packages[0]->name());
        self::assertSame('dev-main', $packages[0]->version());
    }
}
