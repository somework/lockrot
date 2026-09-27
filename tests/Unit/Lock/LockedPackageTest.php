<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Lock;

use Lockrot\Lock\LockedPackage;
use PHPUnit\Framework\TestCase;

final class LockedPackageTest extends TestCase
{
    /**
     * A branch is a branch however the lock writes it: `dev-` prefix, `-dev` suffix, and with the
     * `#reference` Composer appends to an inline-alias or a pinned commit, which the stability
     * parser strips before it looks. A release with a reference is still a release.
     */
    public function testIsBranchSnapshotReadsTheStabilityNotTheString(): void
    {
        self::assertTrue(self::package('dev-main')->isBranchSnapshot());
        self::assertTrue(self::package('2.x-dev')->isBranchSnapshot());
        self::assertTrue(self::package('dev-main#a1b2c3d')->isBranchSnapshot());
        self::assertTrue(self::package('9999999-dev')->isBranchSnapshot());
        self::assertFalse(self::package('v1.2.3')->isBranchSnapshot());
        self::assertFalse(self::package('1.2.3#a1b2c3d')->isBranchSnapshot());
        self::assertFalse(self::package('2.0.0-RC1')->isBranchSnapshot());
        self::assertFalse(self::package('1.0.0-beta.2')->isBranchSnapshot());
    }

    private static function package(string $version): LockedPackage
    {
        return new LockedPackage('vendor/pkg', $version, null, null, [], null, 'library', true, false, false);
    }
}
