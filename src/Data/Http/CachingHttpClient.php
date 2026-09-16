<?php

declare(strict_types=1);

namespace Lockrot\Data\Http;

use Lockrot\Clock;
use Lockrot\Data\Cache\CacheInterface;

final class CachingHttpClient implements HttpClientInterface
{
    private HttpClientInterface $inner;
    private CacheInterface $cache;
    private int $ttl;
    private Clock $clock;
    private bool $offline;

    public function __construct(HttpClientInterface $inner, CacheInterface $cache, int $ttlSeconds, Clock $clock, bool $offline = false)
    {
        $this->inner = $inner;
        $this->cache = $cache;
        $this->ttl = $ttlSeconds;
        $this->clock = $clock;
        $this->offline = $offline;
    }

    /** The client every cache miss is served by, so callers can inspect how it was configured. */
    public function inner(): HttpClientInterface
    {
        return $this->inner;
    }

    /**
     * @param list<string> $urls
     * @param list<string> $headers forwarded to the inner client for every request in the batch
     * @return array<string, HttpResult>
     */
    public function fetchAll(array $urls, array $headers = []): array
    {
        $results = [];
        $toFetch = [];
        /** @var array<string, HttpResult> $stale */
        $stale = [];
        $now = $this->clock->now()->getTimestamp();
        foreach ($urls as $url) {
            $cached = $this->cache->get($url);
            $isFresh = $cached !== null && $now - $cached->fetchedAt()->getTimestamp() < $this->ttl;
            if ($cached !== null && ($this->offline || $isFresh)) {
                $results[$url] = $cached->asCached();
                continue;
            }
            if ($this->offline) {
                $results[$url] = HttpResult::failure($url, 'offline and not cached: '.$url, $this->clock->now());
                continue;
            }
            if ($cached !== null) {
                $stale[$url] = $cached;
            }
            $toFetch[] = $url;
        }
        if ($toFetch !== []) {
            foreach ($this->inner->fetchAll($toFetch, $headers) as $url => $result) {
                if ($result->isFailure()) {
                    $results[$url] = isset($stale[$url]) ? $stale[$url]->asCached() : $result;
                    continue;
                }
                $this->cache->set($url, $result);
                $results[$url] = $result;
            }
        }

        return $results;
    }
}
