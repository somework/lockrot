<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Http;

use Lockrot\Clock;
use Lockrot\Data\Cache\ArrayCache;
use Lockrot\Data\Http\CachingHttpClient;
use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Http\HttpResult;
use PHPUnit\Framework\TestCase;

final class CachingHttpClientTest extends TestCase
{
    private const TTL = 86400;

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
                    $out[$url] = $this->responses[$url] ?? HttpResult::failure($url, 'unexpected', new \DateTimeImmutable());
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
        $result = $client->fetchAll(['https://a'])['https://a'];
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
        self::assertSame('new', $client->fetchAll(['https://a'])['https://a']->body());
        self::assertSame(1, $calls);
        $cached = $cache->get('https://a');
        self::assertNotNull($cached);
        self::assertSame('new', $cached->body());
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
        $client->fetchAll(['https://nf', 'https://err']);
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
        $results = $client->fetchAll(['https://a', 'https://b']);
        self::assertSame('old', $results['https://a']->body());
        self::assertTrue($results['https://b']->isFailure());
        self::assertStringContainsString('offline', (string) $results['https://b']->error());
        self::assertSame(0, $calls);
    }

    public function testFailedRefetchFallsBackToStaleCache(): void
    {
        $calls = 0;
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $cache = new ArrayCache();
        $cache->set('https://a', new HttpResult('https://a', 200, 'old', $clock->now()->modify('-3 days')));
        $client = new CachingHttpClient($this->inner(['https://a' => HttpResult::failure('https://a', 'timeout', $clock->now())], $calls), $cache, self::TTL, $clock);
        self::assertSame('old', $client->fetchAll(['https://a'])['https://a']->body());
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
                    $out[$url] = new HttpResult($url, 200, 'ok', new \DateTimeImmutable());
                }
                return $out;
            }
        };
        $client = new CachingHttpClient($inner, $cache, self::TTL, $clock);
        $client->fetchAll(['https://a'], ['Authorization: token abc']);
        self::assertSame(['Authorization: token abc'], $inner->seenHeaders);
    }

    /** An envelope whose fetched_at could not be read is a miss: refetched, and never served stale with an epoch-zero age. */
    public function testACorruptEnvelopeIsAMissNotAStaleAnswer(): void
    {
        $clock = Clock::fixed('2026-09-14T12:00:00+00:00');
        $cache = new ArrayCache();
        $corrupt = HttpResult::fromEnvelope('https://a', ['status' => 200, 'body' => '{}', 'fetched_at' => 'garbage']);
        $cache->set('https://a', $corrupt);
        $calls = 0;

        $refetched = (new CachingHttpClient($this->inner(['https://a' => HttpResult::failure('https://a', 'timeout', $clock->now())], $calls), $cache, self::TTL, $clock))->fetchAll(['https://a']);
        self::assertSame(1, $calls);
        self::assertTrue($refetched['https://a']->isFailure(), 'the failure stands; the corrupt entry is not a fallback');
        self::assertFalse($refetched['https://a']->fromCache());

        $offline = (new CachingHttpClient($this->inner([], $calls), $cache, self::TTL, $clock, true))->fetchAll(['https://a']);
        self::assertTrue($offline['https://a']->isFailure(), 'offline, a corrupt entry is not an answer either');
    }

    /** A hit, an offline hit and the stale-on-failure fallback all say so; a fetch does not. */
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

        $results = (new CachingHttpClient($inner, $cache, self::TTL, $clock))->fetchAll(['https://fresh', 'https://stale', 'https://new']);
        self::assertTrue($results['https://fresh']->fromCache(), 'fresh hit');
        self::assertTrue($results['https://stale']->fromCache(), 'stale entry served because the refetch failed');
        self::assertSame('2026-09-12T00:00:00+00:00', $results['https://stale']->fetchedAt()->format(\DATE_ATOM));
        self::assertFalse($results['https://new']->fromCache(), 'fetched in this run');

        $offline = (new CachingHttpClient($this->inner([], $calls), $cache, self::TTL, $clock, true))->fetchAll(['https://stale']);
        self::assertTrue($offline['https://stale']->fromCache(), 'offline hit, however old');
    }
}
