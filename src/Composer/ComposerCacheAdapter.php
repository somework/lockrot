<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Cache;
use Lockrot\Data\Cache\CacheInterface;
use Lockrot\Data\Http\HttpResult;

/**
 * Stores HTTP envelopes in Composer's own cache directory, through Composer\Cache.
 *
 * A cache that is disabled (COMPOSER_CACHE_DIR=/dev/null from `composer --no-cache`, an unwritable
 * directory) or read-only (`cache-read-only`) needs no guard here: Composer\Cache::read() and
 * ::write() both check isEnabled() themselves and answer false, so a read is a miss and a write is a
 * no-op either way. {@see ServiceFactory::createCache()} does not hand such a cache over in the first
 * place — it falls back to an in-memory cache — so this only covers a cache that turns disabled
 * mid-run.
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
        $json = json_encode($result->toEnvelope(), \JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            $this->cache->write($this->file($key), $json);
        }
    }

    /**
     * One file per URL, named after its SHA-1 so that a query string or a path cannot collide with
     * Composer's own cache file names, and suffixed `.json` because that is what the file holds —
     * an envelope Composer's own `cache-files-*` housekeeping and a human reading the cache
     * directory both see.
     */
    private function file(string $key): string
    {
        return sha1($key).'.json';
    }
}
