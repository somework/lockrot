<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Cache;
use Lockrot\Data\Cache\CacheInterface;
use Lockrot\Data\Http\HttpResult;

/** Stores HTTP envelopes in Composer's cache dir (Composer\Cache read/write/isEnabled — Cache.php:121,138,90). */
final class ComposerCacheAdapter implements CacheInterface
{
    private Cache $cache;

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    public function get(string $key): ?HttpResult
    {
        if (!$this->cache->isEnabled()) {
            return null;
        }
        $raw = $this->cache->read($this->file($key));

        return HttpResult::fromEnvelopeJson($key, \is_string($raw) ? $raw : null);
    }

    public function set(string $key, HttpResult $result): void
    {
        if (!$this->cache->isEnabled()) {
            return;
        }
        $json = json_encode($result->toEnvelope(), \JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            $this->cache->write($this->file($key), $json);
        }
    }

    private function file(string $key): string
    {
        return sha1($key).'.json';
    }
}
