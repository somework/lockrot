<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Cache;
use Lockrot\Data\Cache\CacheInterface;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Json\JsonWriter;

/**
 * A disabled or read-only cache needs no guard here: Composer\Cache::read() and ::write() check
 * isEnabled() themselves, so a read is a miss and a write does nothing. The cache is best effort:
 * a read that throws is a miss and a write that throws is dropped. Composer\Cache::write() rethrows
 * an ErrorException when its retry fails, as on a rename that another process blocks, and a failed
 * read throws one through Composer's error handler.
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
        try {
            $raw = $this->cache->read($this->file($key));
        } catch (\Exception $e) {
            return null;
        }

        return HttpResult::fromEnvelopeJson($key, \is_string($raw) ? $raw : null);
    }

    public function set(string $key, HttpResult $result): void
    {
        $json = JsonWriter::encode($result->toEnvelope(), \JSON_UNESCAPED_SLASHES);
        if ($json === null) {
            return;
        }
        try {
            $this->cache->write($this->file($key), $json);
        } catch (\Exception $e) {
            // A dropped answer costs one refetch in a later run.
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
