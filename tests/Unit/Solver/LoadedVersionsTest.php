<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Package\BasePackage;
use Lockrot\Solver\LoadedVersions;
use Lockrot\Tests\Support\ArrayVersionSource;
use PHPUnit\Framework\TestCase;

final class LoadedVersionsTest extends TestCase
{
    public function testEachNameThatThePoolBuilderAsksIsLoadedOnceAndAnAskedNameStopsNoOtherName(): void
    {
        $source = new ArrayVersionSource(['acme/a' => [['version' => '1.0.0']], 'acme/b' => [['version' => '2.0.0']]]);
        $repository = new LoadedVersions($source);

        $repository->loadPackages(['acme/a' => null], ['stable' => BasePackage::STABILITY_STABLE], []);
        $loaded = $repository->loadPackages(['acme/a' => null, 'acme/b' => null], ['stable' => BasePackage::STABILITY_STABLE], []);

        self::assertSame(['acme/a 1.0.0', 'acme/b 2.0.0'], array_map(static fn (BasePackage $package): string => $package->getName().' '.$package->getPrettyVersion(), array_values($loaded['packages'])));
        self::assertSame(['acme/a 1.0.0', 'acme/b 2.0.0'], array_map(static fn (BasePackage $package): string => $package->getName().' '.$package->getPrettyVersion(), $repository->getPackages()));
    }
}
