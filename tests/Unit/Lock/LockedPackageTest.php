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
    public function testIsSnapshotVersionReadsTheStabilityNotTheString(): void
    {
        self::assertTrue(LockedPackage::isSnapshotVersion('dev-main'));
        self::assertTrue(LockedPackage::isSnapshotVersion('2.x-dev'));
        self::assertTrue(LockedPackage::isSnapshotVersion('dev-main#a1b2c3d'));
        self::assertTrue(LockedPackage::isSnapshotVersion('9999999-dev'));
        self::assertFalse(LockedPackage::isSnapshotVersion('v1.2.3'));
        self::assertFalse(LockedPackage::isSnapshotVersion('1.2.3#a1b2c3d'));
        self::assertFalse(LockedPackage::isSnapshotVersion('2.0.0-RC1'));
        self::assertFalse(LockedPackage::isSnapshotVersion('1.0.0-beta.2'));
    }
}
