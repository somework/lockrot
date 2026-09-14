<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Packagist;

use Composer\MetadataMinifier\MetadataMinifier;
use Lockrot\Data\Packagist\P2Expander;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class P2ExpanderTest extends TestCase
{
    public function testAppliesDiffsAndUnset(): void
    {
        $minified = [
            ['name' => 'a/b', 'version' => '2.0.0', 'time' => '2020-01-01T00:00:00+00:00', 'require' => ['php' => '>=7.4'], 'abandoned' => true],
            ['version' => '1.1.0', 'time' => '2019-01-01T00:00:00+00:00', 'abandoned' => '__unset'],
            ['version' => '1.0.0', 'require' => '__unset'],
        ];
        $expanded = P2Expander::expand($minified);
        self::assertCount(3, $expanded);
        self::assertSame('a/b', $expanded[1]['name']);
        self::assertSame(['php' => '>=7.4'], $expanded[1]['require']);
        self::assertArrayNotHasKey('abandoned', $expanded[1]);
        self::assertArrayNotHasKey('require', $expanded[2]);
        self::assertSame('2019-01-01T00:00:00+00:00', $expanded[2]['time']);
    }

    public function testEmptyInput(): void
    {
        self::assertSame([], P2Expander::expand([]));
    }

    /** @dataProvider rawFiles */
    #[DataProvider('rawFiles')]
    public function testMatchesComposerMinifierOnRealResponses(string $file, string $package): void
    {
        $response = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($response);
        /** @var array<string, mixed> $response */
        $packages = $response['packages'];
        self::assertIsArray($packages);
        /** @var list<array<string, mixed>> $versions */
        $versions = $packages[$package];
        self::assertSame(MetadataMinifier::expand($versions), P2Expander::expandResponse($response, $package));
    }

    /** @return iterable<string, array{string, string}> */
    public static function rawFiles(): iterable
    {
        $dir = __DIR__.'/../../../fixtures/p2-raw/';
        yield 'phpzip' => [$dir.'phpzip_phpzip.json', 'phpzip/phpzip'];
        yield 'getallheaders' => [$dir.'ralouphie_getallheaders.json', 'ralouphie/getallheaders'];
        yield 'doctrine/cache' => [$dir.'doctrine_cache.json', 'doctrine/cache'];
        yield 'random_compat' => [$dir.'paragonie_random_compat.json', 'paragonie/random_compat'];
        yield 'xhprof~dev' => [$dir.'lox_xhprof~dev.json', 'lox/xhprof'];
    }

    public function testExpandResponseWithoutMinifiedFlagReturnsAsIs(): void
    {
        $response = ['packages' => ['a/b' => [['name' => 'a/b', 'version' => '1.0.0'], ['version' => '0.9.0']]]];
        self::assertSame([['name' => 'a/b', 'version' => '1.0.0'], ['version' => '0.9.0']], P2Expander::expandResponse($response, 'a/b'));
    }

    public function testExpandResponseMissingPackage(): void
    {
        self::assertSame([], P2Expander::expandResponse(['packages' => []], 'a/b'));
    }
}
