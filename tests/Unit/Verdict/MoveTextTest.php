<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Verdict\MoveText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoveTextTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function versions(): iterable
    {
        yield 'a minor line' => ['5.4.x-dev', true];
        yield 'a major line with a v' => ['v2.x-dev', true];
        yield 'a branch' => ['dev-master', false];
        yield 'a release' => ['1.2.3', false];
        yield 'a branch that ends like a line' => ['dev-feature-2.x-dev', false];
        yield 'a line with a suffix' => ['2.x-dev-fix', false];
    }

    /**
     * @dataProvider versions
     */
    #[DataProvider('versions')]
    public function testOnlyABranchAliasSnapshotSitsOnItsOwnLine(string $version, bool $alias): void
    {
        self::assertSame($alias, MoveText::isBranchAlias($version));
    }
}
