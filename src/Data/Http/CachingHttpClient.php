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
    private bool $refresh;

    public function __construct(HttpClientInterface $inner, CacheInterface $cache, int $ttlSeconds, Clock $clock, bool $offline = false, bool $refresh = false)
    {
        $this->inner = $inner;
        $this->cache = $cache;
        $this->ttl = $ttlSeconds;
        $this->clock = $clock;
        $this->offline = $offline;
        $this->refresh = $refresh;
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
            if ($cached !== null && ($this->offline || (!$this->refresh && $isFresh))) {
                $results[$url] = $cached;
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
                    $results[$url] = $stale[$url] ?? $result;
                    continue;
                }
                $this->cache->set($url, $result);
                $results[$url] = $result;
            }
        }

        return $results;
    }
}
