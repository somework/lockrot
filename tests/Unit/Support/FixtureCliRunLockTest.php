<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Support;

use Composer\Package\Locker;
use Lockrot\Tests\Support\FixtureCliRun;
use PHPUnit\Framework\TestCase;

/** @coversNothing */
final class FixtureCliRunLockTest extends TestCase
{
    private const MANIFEST = '{"require": {"a/b": "^1.0"}, "repositories": [{"type": "composer", "url": "https://packages.example.org/8"}]}';
    private const MIRRORED = '{"require": {"a/b": "^1.0"}, "repositories": [{"type": "composer", "url": "http://127.0.0.1:8080/mirror"}]}';

    public function testALockInSyncWithTheProjectStaysInSyncWithTheMirroredManifest(): void
    {
        $lock = (string) json_encode(['content-hash' => Locker::getContentHash(self::MANIFEST), 'packages' => []]);

        $copy = json_decode(FixtureCliRun::lockForManifest($lock, self::MANIFEST, self::MIRRORED), true);

        self::assertIsArray($copy);
        self::assertSame(Locker::getContentHash(self::MIRRORED), $copy['content-hash']);
    }

    public function testALockOutOfSyncWithTheProjectStaysOutOfSync(): void
    {
        $lock = (string) json_encode(['content-hash' => 'd41d8cd98f00b204e9800998ecf8427e', 'packages' => []]);

        self::assertSame($lock, FixtureCliRun::lockForManifest($lock, self::MANIFEST, self::MIRRORED));
    }
}
