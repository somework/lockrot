<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Forge;

use Lockrot\Data\Forge\SupportSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SupportSourceTest extends TestCase
{
    /** @return iterable<string, array{0: array<mixed>, 1: ?string}> */
    public static function supports(): iterable
    {
        yield 'maintainer-set repository' => [['source' => 'https://github.com/phpstan/phpstan-src'], 'https://github.com/phpstan/phpstan-src'];
        yield 'packagist default /tree/<version>' => [['source' => 'https://github.com/sebastianbergmann/phpunit/tree/13.3.4'], 'https://github.com/sebastianbergmann/phpunit'];
        yield 'gitlab /-/tree/<ref>' => [['source' => 'https://gitlab.com/g/sub/p/-/tree/v1.2'], 'https://gitlab.com/g/sub/p'];
        yield 'gitlab old-style /tree/<ref>' => [['source' => 'https://gitlab.com/g/p/tree/main'], 'https://gitlab.com/g/p'];
        yield 'trailing slash' => [['source' => 'https://github.com/a/b/'], 'https://github.com/a/b'];
        yield 'a repository literally named tree keeps its last segment' => [['source' => 'https://github.com/a/tree'], 'https://github.com/a/tree'];
        yield 'empty' => [['source' => ''], null];
        yield 'not a string' => [['source' => ['url' => 'x']], null];
        yield 'absent' => [['issues' => 'https://github.com/a/b/issues'], null];
        yield 'only a tree page' => [['source' => '/tree/main'], null];
    }

    /**
     * @dataProvider supports
     *
     * @param array<mixed> $support
     */
    #[DataProvider('supports')]
    public function testUrl(array $support, ?string $expected): void
    {
        self::assertSame($expected, SupportSource::url($support));
    }
}
