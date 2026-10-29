<?php

declare(strict_types=1);

namespace Lockrot\Data\Http;

use Lockrot\Clock;
use Lockrot\Data\Cache\CacheInterface;

/** @internal */
final class CachingHttpClient implements HttpClientInterface
{
    /**
     * Ends the key of a request without a credential header: a host answers 404 for a private
     * repository to an anonymous request, and a run with a token must not reuse that answer. No URL
     * holds a space.
     */
    private const ANONYMOUS_KEY = ' anonymous';

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

    public function inner(): HttpClientInterface
    {
        return $this->inner;
    }

    /**
     * @param list<string> $urls
     * @param list<string> $headers
     * @return array<string, HttpResult>
     */
    public function fetchAll(array $urls, array $headers = []): array
    {
        $results = [];
        $toFetch = [];
        /** @var array<string, HttpResult> $stale */
        $stale = [];
        $now = $this->clock->now()->getTimestamp();
        $suffix = self::carriesCredentials($headers) ? '' : self::ANONYMOUS_KEY;
        foreach ($urls as $url) {
            $cached = $this->cached($url, $url.$suffix);
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
                $this->cache->set($url.$suffix, $result);
                $results[$url] = $result;
            }
        }

        return $results;
    }

    public static function isCredentialHeader(string $header): bool
    {
        return stripos($header, 'authorization:') === 0 || stripos($header, 'private-token:') === 0;
    }

    /** @param list<string> $headers */
    private static function carriesCredentials(array $headers): bool
    {
        foreach ($headers as $header) {
            if (self::isCredentialHeader($header)) {
                return true;
            }
        }

        return false;
    }

    /** A cache that stores no URL, such as Composer's, returns the key as the URL of the answer. */
    private function cached(string $url, string $key): ?HttpResult
    {
        $cached = $this->cache->get($key);
        if ($cached === null || $cached->url() === $url) {
            return $cached;
        }

        return new HttpResult($url, $cached->status(), $cached->body(), $cached->fetchedAt(), $cached->error());
    }
}
