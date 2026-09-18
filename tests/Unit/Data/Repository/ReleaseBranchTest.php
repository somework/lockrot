<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Repository;

use Lockrot\Data\Repository\ReleaseBranch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseBranchTest extends TestCase
{
    /** @dataProvider keys */
    #[DataProvider('keys')]
    public function testOf(string $version, ?string $expected): void
    {
        self::assertSame($expected, ReleaseBranch::of($version));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function keys(): iterable
    {
        yield 'lock pretty version' => ['v1.2.3', '1'];
        yield 'normalized' => ['1.2.3.0', '1'];
        yield 'two-digit major' => ['10.0.1', '10'];
        yield 'release candidate' => ['2.0.0-RC1', '2'];
        yield '0.x is a branch per minor' => ['0.3.1', '0.3'];
        yield '0.0.x' => ['0.0.4', '0.0'];
        yield 'dev branch' => ['dev-master', null];
        yield 'branch alias' => ['2.x-dev', null];
        yield 'unparsable' => ['not-a-version', null];
    }

    public function testLabel(): void
    {
        self::assertSame('1.x', ReleaseBranch::label('1'));
        self::assertSame('0.3.x', ReleaseBranch::label('0.3'));
    }

    public function testIsAbove(): void
    {
        self::assertTrue(ReleaseBranch::isAbove('2', '1'));
        self::assertTrue(ReleaseBranch::isAbove('10', '9'));
        self::assertTrue(ReleaseBranch::isAbove('1', '0.9'));
        self::assertTrue(ReleaseBranch::isAbove('0.10', '0.9'));
        self::assertFalse(ReleaseBranch::isAbove('1', '1'));
        self::assertFalse(ReleaseBranch::isAbove('0.3', '1'));
    }
}
