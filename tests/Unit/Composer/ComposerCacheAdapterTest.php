<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Composer;

use Composer\Cache;
use Composer\IO\NullIO;
use Lockrot\Composer\ComposerCacheAdapter;
use Lockrot\Data\Http\HttpResult;
use PHPUnit\Framework\TestCase;

final class ComposerCacheAdapterTest extends TestCase
{
    public function testRoundTripThroughComposerCache(): void
    {
        $dir = sys_get_temp_dir().'/lockrot-ccache-'.uniqid().'/';
        $adapter = new ComposerCacheAdapter(new Cache(new NullIO(), $dir));
        self::assertNull($adapter->get('https://repo.packagist.org/p2/a/b.json'));
        $adapter->set('https://repo.packagist.org/p2/a/b.json', new HttpResult('https://repo.packagist.org/p2/a/b.json', 200, '{}', new \DateTimeImmutable('2026-09-14T00:00:00+00:00')));
        $hit = $adapter->get('https://repo.packagist.org/p2/a/b.json');
        self::assertNotNull($hit);
        self::assertSame('{}', $hit->body());
        self::assertSame('2026-09-14', $hit->fetchedAt()->format('Y-m-d'));
        array_map('unlink', glob($dir.'*') ?: []);
        rmdir($dir);
    }

    /** A stored envelope whose fetch time cannot be read is a miss, never an answer of unknowable age. */
    public function testAnEnvelopeWithoutAReadableFetchTimeIsAMiss(): void
    {
        $dir = sys_get_temp_dir().'/lockrot-ccache-'.uniqid().'/';
        $cache = new Cache(new NullIO(), $dir);
        $adapter = new ComposerCacheAdapter($cache);
        $cache->write(sha1('https://a').'.json', '{"status":200,"fetched_at":"garbage","body":"{}","error":null}');
        self::assertNull($adapter->get('https://a'));
        $cache->write(sha1('https://b').'.json', '{"status":200,"body":"{}","error":null}');
        self::assertNull($adapter->get('https://b'));
        array_map('unlink', glob($dir.'*') ?: []);
        rmdir($dir);
    }

    public function testDisabledCacheIsAlwaysMiss(): void
    {
        $cache = new Cache(new NullIO(), '/dev/null/nope');
        $adapter = new ComposerCacheAdapter($cache);
        $adapter->set('k', new HttpResult('k', 200, 'x', new \DateTimeImmutable()));
        self::assertNull($adapter->get('k'));
    }
}
