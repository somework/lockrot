<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Cache;
use Lockrot\Data\Cache\CacheInterface;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Json\JsonWriter;

/**
 * A disabled or read-only cache needs no guard here: Composer\Cache::read() and ::write() check
 * isEnabled() themselves, so a read is a miss and a write does nothing. Only a cache that turns
 * disabled during a run reaches this class disabled, because {@see ServiceFactory::createCache()}
 * hands over an enabled cache only.
 *
 * @internal
 */
final class ComposerCacheAdapter implements CacheInterface
{
    private Cache $cache;

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    public function get(string $key): ?HttpResult
    {
        $raw = $this->cache->read($this->file($key));

        return HttpResult::fromEnvelopeJson($key, \is_string($raw) ? $raw : null);
    }

    public function set(string $key, HttpResult $result): void
    {
        $json = JsonWriter::encode($result->toEnvelope(), \JSON_UNESCAPED_SLASHES);
        if ($json !== null) {
            $this->cache->write($this->file($key), $json);
        }
    }

    /**
     * The SHA-1 of the key names the file, so a query string or a path cannot collide with
     * Composer's own cache file names.
     */
    private function file(string $key): string
    {
        return sha1($key).'.json';
    }
}
