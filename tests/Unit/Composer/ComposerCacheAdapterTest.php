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
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            array_map('unlink', glob($dir.'*') ?: []);
            @rmdir($dir);
        }
        $this->dirs = [];
    }

    /** A cache directory of this test's own, removed in tearDown whether or not the assertions passed. */
    private function cacheDir(): string
    {
        $dir = sys_get_temp_dir().'/lockrot-ccache-'.uniqid('', true).'/';
        $this->dirs[] = $dir;

        return $dir;
    }

    public function testRoundTripThroughComposerCache(): void
    {
        $dir = $this->cacheDir();
        $adapter = new ComposerCacheAdapter(new Cache(new NullIO(), $dir));
        self::assertNull($adapter->get('https://repo.packagist.org/p2/a/b.json'));
        $adapter->set('https://repo.packagist.org/p2/a/b.json', new HttpResult('https://repo.packagist.org/p2/a/b.json', 200, '{}', new \DateTimeImmutable('2026-09-14T00:00:00+00:00')));
        $hit = $adapter->get('https://repo.packagist.org/p2/a/b.json');
        self::assertNotNull($hit);
        self::assertSame('{}', $hit->body());
        self::assertSame('2026-09-14', $hit->fetchedAt()->format('Y-m-d'));
        // One `<sha1>.json` per URL: the hash keeps a query string or a path from colliding with
        // Composer's own cache file names, and the suffix says what the file holds.
        self::assertSame([sha1('https://repo.packagist.org/p2/a/b.json').'.json'], self::fileNamesIn($dir));
    }

    /** @return list<string> */
    private static function fileNamesIn(string $dir): array
    {
        $names = array_map('basename', glob($dir.'*') ?: []);
        sort($names);

        return $names;
    }

    /** A stored envelope whose fetch time cannot be read is a miss, never an answer of unknowable age. */
    public function testAnEnvelopeWithoutAReadableFetchTimeIsAMiss(): void
    {
        $dir = $this->cacheDir();
        $cache = new Cache(new NullIO(), $dir);
        $adapter = new ComposerCacheAdapter($cache);
        $cache->write(sha1('https://a').'.json', '{"status":200,"fetched_at":"garbage","body":"{}","error":null}');
        self::assertNull($adapter->get('https://a'));
        $cache->write(sha1('https://b').'.json', '{"status":200,"body":"{}","error":null}');
        self::assertNull($adapter->get('https://b'));
    }

    public function testDisabledCacheIsAlwaysMiss(): void
    {
        $cache = new Cache(new NullIO(), '/dev/null/nope');
        $adapter = new ComposerCacheAdapter($cache);
        $adapter->set('k', new HttpResult('k', 200, 'x', new \DateTimeImmutable()));
        self::assertNull($adapter->get('k'));
    }
}
