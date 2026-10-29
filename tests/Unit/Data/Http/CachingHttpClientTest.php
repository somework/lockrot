<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Http;

use Lockrot\Clock;
use Lockrot\Data\Cache\ArrayCache;
use Lockrot\Data\Cache\CacheInterface;
use Lockrot\Data\Http\CachingHttpClient;
use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Http\HttpResult;
use PHPUnit\Framework\TestCase;

final class CachingHttpClientTest extends TestCase
{
    private const TTL = 86400;

    /** A credential header: the cache keys such a request on its URL alone. */
    private const TOKEN = ['Authorization: token t'];

    /** @param array<string, HttpResult> $responses */
    private function inner(array $responses, int &$calls): HttpClientInterface
    {
        return new class ($responses, $calls) implements HttpClientInterface {
            /** @var array<string, HttpResult> */
            private array $responses;
            private int $calls;
            /** @param array<string, HttpResult> $responses */
            public function __construct(array $responses, int &$calls)
            {
                $this->responses = $responses;
                $this->calls = &$calls;
            }
            /**
             * @param list<string> $urls
             * @param list<string> $headers
             * @return array<string, HttpResult>
             */
            public function fetchAll(array $urls, array $headers = []): array
            {
                $out = [];
                foreach ($urls as $url) {
                    ++$this->calls;
                    $out[$url] = $this->responses[$url] ?? HttpResult::failure($url, 'unexpected', new \DateTimeImmutable('2026-09-14T00:00:00+00:00'));
                }
                return $out;
            }
        };
    }

    public function testFreshEntryServedFromCache(): void
    {
        $calls = 0;
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $cache = new ArrayCache();
        $cache->set('https://a', new HttpResult('https://a', 200, '{}', $clock->now()->modify('-1 hour')));
        $client = new CachingHttpClient($this->inner([], $calls), $cache, self::TTL, $clock);
        $result = $client->fetchAll(['https://a'], self::TOKEN)['https://a'];
        self::assertTrue($result->isOk());
        self::assertSame(0, $calls);
    }

    public function testStaleEntryRefetchedAndStored(): void
    {
        $calls = 0;
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $cache = new ArrayCache();
        $cache->set('https://a', new HttpResult('https://a', 200, 'old', $clock->now()->modify('-2 days')));
        $fresh = new HttpResult('https://a', 200, 'new', $clock->now());
        $client = new CachingHttpClient($this->inner(['https://a' => $fresh], $calls), $cache, self::TTL, $clock);
        self::assertSame('new', $client->fetchAll(['https://a'], self::TOKEN)['https://a']->body());
        self::assertSame(1, $calls);
        $cached = $cache->get('https://a');
        self::assertNotNull($cached);
        self::assertSame('new', $cached->body());
    }

    /** The TTL is the age at which an entry stops being fresh, so an entry exactly that old is refetched. */
    public function testAnEntryExactlyTheTtlOldIsAlreadyStale(): void
    {
        $calls = 0;
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $cache = new ArrayCache();
        $cache->set('https://a', new HttpResult('https://a', 200, 'old', $clock->now()->modify('-'.self::TTL.' seconds')));
        $fresh = new HttpResult('https://a', 200, 'new', $clock->now());
        $client = new CachingHttpClient($this->inner(['https://a' => $fresh], $calls), $cache, self::TTL, $clock);

        self::assertSame('new', $client->fetchAll(['https://a'], self::TOKEN)['https://a']->body());
        self::assertSame(1, $calls);

        $calls = 0;
        $cache->set('https://a', new HttpResult('https://a', 200, 'old', $clock->now()->modify('-'.(self::TTL - 1).' seconds')));
        $client = new CachingHttpClient($this->inner(['https://a' => $fresh], $calls), $cache, self::TTL, $clock);

        self::assertSame('old', $client->fetchAll(['https://a'], self::TOKEN)['https://a']->body());
        self::assertSame(0, $calls);
    }

    public function testNotFoundIsCachedButFailuresAreNot(): void
    {
        $calls = 0;
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $cache = new ArrayCache();
        $client = new CachingHttpClient($this->inner([
            'https://nf' => new HttpResult('https://nf', 404, '', $clock->now()),
            'https://err' => HttpResult::failure('https://err', 'timeout', $clock->now()),
        ], $calls), $cache, self::TTL, $clock);
        $client->fetchAll(['https://nf', 'https://err'], self::TOKEN);
        self::assertNotNull($cache->get('https://nf'));
        self::assertNull($cache->get('https://err'));
    }

    public function testOfflineUsesStaleCacheAndFailsMisses(): void
    {
        $calls = 0;
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $cache = new ArrayCache();
        $cache->set('https://a', new HttpResult('https://a', 200, 'old', $clock->now()->modify('-30 days')));
        $client = new CachingHttpClient($this->inner([], $calls), $cache, self::TTL, $clock, true);
        // Two misses, so a miss must not stop the batch at the first one.
        $results = $client->fetchAll(['https://a', 'https://b', 'https://c'], self::TOKEN);
        self::assertSame('old', $results['https://a']->body());
        self::assertTrue($results['https://b']->isFailure());
        self::assertSame('offline and not cached: https://b', $results['https://b']->error(), 'the message names the URL that was not cached');
        self::assertTrue($results['https://c']->isFailure());
        self::assertSame('offline and not cached: https://c', $results['https://c']->error());
        self::assertSame(0, $calls);
    }

    public function testFailedRefetchFallsBackToStaleCache(): void
    {
        $calls = 0;
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $cache = new ArrayCache();
        $cache->set('https://a', new HttpResult('https://a', 200, 'old', $clock->now()->modify('-3 days')));
        $client = new CachingHttpClient($this->inner(['https://a' => HttpResult::failure('https://a', 'timeout', $clock->now())], $calls), $cache, self::TTL, $clock);
        self::assertSame('old', $client->fetchAll(['https://a'], self::TOKEN)['https://a']->body());
    }

    public function testHeadersAreForwardedToInnerClient(): void
    {
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $cache = new ArrayCache();
        $inner = new class () implements HttpClientInterface {
            /** @var list<string> */
            public array $seenHeaders = [];

            /**
             * @param list<string> $urls
             * @param list<string> $headers
             * @return array<string, HttpResult>
             */
            public function fetchAll(array $urls, array $headers = []): array
            {
                $this->seenHeaders = $headers;
                $out = [];
                foreach ($urls as $url) {
                    $out[$url] = new HttpResult($url, 200, 'ok', new \DateTimeImmutable('2026-09-14T00:00:00+00:00'));
                }
                return $out;
            }
        };
        $client = new CachingHttpClient($inner, $cache, self::TTL, $clock);
        $client->fetchAll(['https://a'], ['Authorization: token abc']);
        self::assertSame(['Authorization: token abc'], $inner->seenHeaders);
    }

    public function testAnswersServedFromTheCacheAreMarkedAsSuch(): void
    {
        $clock = Clock::fixed('2026-09-14T12:00:00+00:00');
        $cache = new ArrayCache();
        $cache->set('https://fresh', new HttpResult('https://fresh', 200, '{}', new \DateTimeImmutable('2026-09-14T00:00:00+00:00')));
        $cache->set('https://stale', new HttpResult('https://stale', 200, '{}', new \DateTimeImmutable('2026-09-12T00:00:00+00:00')));
        $calls = 0;
        $inner = $this->inner([
            'https://stale' => HttpResult::failure('https://stale', 'timeout', $clock->now()),
            'https://new' => new HttpResult('https://new', 200, '{}', $clock->now()),
        ], $calls);

        $results = (new CachingHttpClient($inner, $cache, self::TTL, $clock))->fetchAll(['https://fresh', 'https://stale', 'https://new'], self::TOKEN);
        self::assertTrue($results['https://fresh']->fromCache(), 'fresh hit');
        self::assertTrue($results['https://stale']->fromCache(), 'stale entry served because the refetch failed');
        self::assertSame('2026-09-12T00:00:00+00:00', $results['https://stale']->fetchedAt()->format(\DATE_ATOM));
        self::assertFalse($results['https://new']->fromCache(), 'fetched in this run');

        $offline = (new CachingHttpClient($this->inner([], $calls), $cache, self::TTL, $clock, true))->fetchAll(['https://stale'], self::TOKEN);
        self::assertTrue($offline['https://stale']->fromCache(), 'offline hit, however old');
    }

    public function testACachedAnonymousNotFoundIsNotServedToARequestWithCredentials(): void
    {
        $calls = 0;
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $cache = new ArrayCache();
        $url = 'https://api.github.com/repos/acme/private';
        $anonymous = new CachingHttpClient($this->inner([$url => new HttpResult($url, 404, '', $clock->now())], $calls), $cache, self::TTL, $clock);
        self::assertTrue($anonymous->fetchAll([$url], ['Accept: application/json', 'User-Agent: lockrot'])[$url]->isNotFound());
        self::assertSame(1, $calls);

        $calls = 0;
        $withToken = new CachingHttpClient($this->inner([$url => new HttpResult($url, 200, '{}', $clock->now())], $calls), $cache, self::TTL, $clock);
        $result = $withToken->fetchAll([$url], ['Accept: application/json', 'User-Agent: lockrot', 'Authorization: token t'])[$url];
        self::assertTrue($result->isOk());
        self::assertFalse($result->fromCache());
        self::assertSame(1, $calls);
    }

    public function testACachedAnswerWithCredentialsIsServedToTheSameCredentialedCall(): void
    {
        $calls = 0;
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $cache = new ArrayCache();
        $url = 'https://gitlab.com/api/v4/projects/acme%2Fprivate';
        $headers = ['Accept: application/json', 'User-Agent: lockrot', 'private-token: t'];
        $client = new CachingHttpClient($this->inner([$url => new HttpResult($url, 200, '{}', $clock->now())], $calls), $cache, self::TTL, $clock);
        $client->fetchAll([$url], $headers);
        $again = $client->fetchAll([$url], $headers)[$url];

        self::assertTrue($again->fromCache());
        self::assertSame(1, $calls);
        self::assertNotNull($cache->get($url), 'a request with a credential header keeps its URL as the key');
    }

    public function testTheStaleAndOfflineLookupsKeepTheCredentialStateApart(): void
    {
        $calls = 0;
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $cache = new ArrayCache();
        $cache->set('https://a', new HttpResult('https://a', 200, 'with token', $clock->now()->modify('-3 days')));

        $online = new CachingHttpClient($this->inner(['https://a' => HttpResult::failure('https://a', 'timeout', $clock->now())], $calls), $cache, self::TTL, $clock);
        self::assertSame('timeout', $online->fetchAll(['https://a'])['https://a']->error(), 'no fallback to the answer of a request with a token');

        $offline = new CachingHttpClient($this->inner([], $calls), $cache, self::TTL, $clock, true);
        self::assertSame('offline and not cached: https://a', $offline->fetchAll(['https://a'])['https://a']->error());
        self::assertSame('with token', $offline->fetchAll(['https://a'], self::TOKEN)['https://a']->body());
    }

    /** A cache that stores no URL, such as Composer's, returns its key as the URL of an answer. */
    public function testAnAnonymousAnswerFromTheCacheCarriesTheRequestedUrl(): void
    {
        $calls = 0;
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $cache = new class () implements CacheInterface {
            /** @var array<string, array<string, mixed>> */
            private array $envelopes = [];

            public function get(string $key): ?HttpResult
            {
                return isset($this->envelopes[$key]) ? HttpResult::fromEnvelope($key, $this->envelopes[$key]) : null;
            }

            public function set(string $key, HttpResult $result): void
            {
                $this->envelopes[$key] = $result->toEnvelope();
            }
        };
        $client = new CachingHttpClient($this->inner(['https://a' => new HttpResult('https://a', 200, 'not json', $clock->now())], $calls), $cache, self::TTL, $clock);
        $client->fetchAll(['https://a']);
        $cached = $client->fetchAll(['https://a'])['https://a'];

        self::assertTrue($cached->fromCache());
        self::assertSame('https://a', $cached->url());
        self::assertSame('not json', $cached->body());
        self::assertSame(1, $calls);
    }
}
