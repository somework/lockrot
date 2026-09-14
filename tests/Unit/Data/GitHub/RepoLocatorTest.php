<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\GitHub;

use Lockrot\Data\GitHub\RepoLocator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepoLocatorTest extends TestCase
{
    /** @dataProvider urls */
    #[DataProvider('urls')]
    public function testParse(?string $url, ?string $expected): void
    {
        self::assertSame($expected, RepoLocator::github($url));
    }

    /** @return iterable<array{?string, ?string}> */
    public static function urls(): iterable
    {
        yield ['https://github.com/Grandt/PHPZip.git', 'Grandt/PHPZip'];
        yield ['https://github.com/ralouphie/getallheaders', 'ralouphie/getallheaders'];
        yield ['git@github.com:php-fig/cache.git', 'php-fig/cache'];
        yield ['git://github.com/lox/xhprof.git', 'lox/xhprof'];
        yield ['https://github.com/owner/repo.with.dots.git', 'owner/repo.with.dots'];
        yield ['ssh://git@github.com/o/r.git', 'o/r'];
        yield ['https://github.com/owner.name/repo', 'owner.name/repo'];
        yield ['https://github.com/o/r/tree/main', null];
        yield ['https://gitlab.com/owner/repo.git', null];
        yield ['https://git.example.com/private/thing.git', null];
        yield [null, null];
        yield ['', null];
    }
}
