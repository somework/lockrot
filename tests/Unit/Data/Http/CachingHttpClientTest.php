<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Http;

use Lockrot\Clock;
use Lockrot\Data\Cache\ArrayCache;
use Lockrot\Data\Cache\CacheInterface;
use Lockrot\Data\Http\CachingHttpClient;
use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Http\HttpResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CachingHttpClientTest extends TestCase
{
    private const TTL = 86400;

    private const TOKEN = ['Authorization: token t'];
    /** Ends the cache key of a request with a credential header. */
    private const CREDENTIALED = ' credentialed';

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
        $cache->set('https://a'.self::CREDENTIALED, new HttpResult('https://a', 200, '{}', $clock->now()->modify('-1 hour')));
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
        $cache->set('https://a'.self::CREDENTIALED, new HttpResult('https://a', 200, '["old"]', $clock->now()->modify('-2 days')));
        $fresh = new HttpResult('https://a', 200, '["new"]', $clock->now());
        $client = new CachingHttpClient($this->inner(['https://a' => $fresh], $calls), $cache, self::TTL, $clock);
        self::assertSame('["new"]', $client->fetchAll(['https://a'], self::TOKEN)['https://a']->body());
        self::assertSame(1, $calls);
        $cached = $cache->get('https://a'.self::CREDENTIALED);
        self::assertNotNull($cached);
        self::assertSame('["new"]', $cached->body());
    }

    /** The TTL is the age at which an entry stops being fresh, so an entry exactly that old is refetched. */
    public function testAnEntryExactlyTheTtlOldIsAlreadyStale(): void
    {
        $calls = 0;
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $cache = new ArrayCache();
        $cache->set('https://a'.self::CREDENTIALED, new HttpResult('https://a', 200, '["old"]', $clock->now()->modify('-'.self::TTL.' seconds')));
        $fresh = new HttpResult('https://a', 200, '["new"]', $clock->now());
        $client = new CachingHttpClient($this->inner(['https://a' => $fresh], $calls), $cache, self::TTL, $clock);

        self::assertSame('["new"]', $client->fetchAll(['https://a'], self::TOKEN)['https://a']->body());
        self::assertSame(1, $calls);

        $calls = 0;
        $cache->set('https://a'.self::CREDENTIALED, new HttpResult('https://a', 200, '["old"]', $clock->now()->modify('-'.(self::TTL - 1).' seconds')));
        $client = new CachingHttpClient($this->inner(['https://a' => $fresh], $calls), $cache, self::TTL, $clock);

        self::assertSame('["old"]', $client->fetchAll(['https://a'], self::TOKEN)['https://a']->body());
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
        self::assertNotNull($cache->get('https://nf'.self::CREDENTIALED));
        self::assertNull($cache->get('https://err'.self::CREDENTIALED));
    }

    public function testOfflineUsesStaleCacheAndFailsMisses(): void
    {
        $calls = 0;
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $cache = new ArrayCache();
        $cache->set('https://a'.self::CREDENTIALED, new HttpResult('https://a', 200, '["old"]', $clock->now()->modify('-30 days')));
        $client = new CachingHttpClient($this->inner([], $calls), $cache, self::TTL, $clock, true);
        // Two misses, so a miss must not stop the batch at the first one.
        $results = $client->fetchAll(['https://a', 'https://b', 'https://c'], self::TOKEN);
        self::assertSame('["old"]', $results['https://a']->body());
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
        $cache->set('https://a'.self::CREDENTIALED, new HttpResult('https://a', 200, '["old"]', $clock->now()->modify('-3 days')));
        $client = new CachingHttpClient($this->inner(['https://a' => HttpResult::failure('https://a', 'timeout', $clock->now())], $calls), $cache, self::TTL, $clock);
        self::assertSame('["old"]', $client->fetchAll(['https://a'], self::TOKEN)['https://a']->body());
    }

    /**
     * @return iterable<string, array{HttpResult}>
     */
    public static function notAnswers(): iterable
    {
        $now = new \DateTimeImmutable('2026-09-14T00:00:00+00:00');

        yield 'a login page with 200' => [new HttpResult('https://a', 200, '<html>sign in</html>', $now)];
        yield 'a redirect left after the redirect cap' => [new HttpResult('https://a', 302, '', $now)];
        yield 'a 200 JSON scalar' => [new HttpResult('https://a', 200, '"x"', $now)];
    }

    /**
     * @dataProvider notAnswers
     */
    #[DataProvider('notAnswers')]
    public function testAnAnswerThatIsNotJsonIsNotStoredAndKeepsTheOlderAnswer(HttpResult $refetched): void
    {
        $calls = 0;
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $cache = new ArrayCache();
        $cache->set('https://a'.self::CREDENTIALED, new HttpResult('https://a', 200, '{"old":true}', $clock->now()->modify('-3 days')));
        $client = new CachingHttpClient($this->inner(['https://a' => $refetched], $calls), $cache, self::TTL, $clock);

        $result = $client->fetchAll(['https://a'], self::TOKEN)['https://a'];

        self::assertSame('{"old":true}', $result->body());
        self::assertTrue($result->fromCache());
        self::assertSame('{"old":true}', ($cache->get('https://a'.self::CREDENTIALED) ?? self::fail('the older answer stays'))->body());
    }

    /**
     * @dataProvider notAnswers
     */
    #[DataProvider('notAnswers')]
    public function testAnAnswerThatIsNotJsonWithNoOlderAnswerIsReturnedButNotStored(HttpResult $fetched): void
    {
        $calls = 0;
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $cache = new ArrayCache();
        $client = new CachingHttpClient($this->inner(['https://a' => $fetched], $calls), $cache, self::TTL, $clock);

        self::assertSame($fetched->status(), $client->fetchAll(['https://a'], self::TOKEN)['https://a']->status());
        self::assertNull($cache->get('https://a'.self::CREDENTIALED));
    }

    public function testAFailedRefetchDoesNotReplayAnExpiredNotFound(): void
    {
        $calls = 0;
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $cache = new ArrayCache();
        $cache->set('https://a'.self::CREDENTIALED, new HttpResult('https://a', 404, '', $clock->now()->modify('-3 days')));
        $client = new CachingHttpClient($this->inner(['https://a' => HttpResult::failure('https://a', 'install-time budget spent', $clock->now())], $calls), $cache, self::TTL, $clock);

        $result = $client->fetchAll(['https://a'], self::TOKEN)['https://a'];

        self::assertTrue($result->isFailure());
        self::assertSame('install-time budget spent', $result->error());
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
                    $out[$url] = new HttpResult($url, 200, '["ok"]', new \DateTimeImmutable('2026-09-14T00:00:00+00:00'));
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
        $cache->set('https://fresh'.self::CREDENTIALED, new HttpResult('https://fresh', 200, '{}', new \DateTimeImmutable('2026-09-14T00:00:00+00:00')));
        $cache->set('https://stale'.self::CREDENTIALED, new HttpResult('https://stale', 200, '{}', new \DateTimeImmutable('2026-09-12T00:00:00+00:00')));
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
        self::assertNotNull($cache->get($url.self::CREDENTIALED));
        self::assertNull($cache->get($url), 'no answer is stored under the URL alone');
    }

    /**
     * An entry under the URL alone has no credential state: an anonymous 404, a 3xx or a 2xx that is
     * not JSON, which a release with that key stored. A request with a credential header fetches again.
     *
     * @return iterable<string, array{HttpResult}>
     */
    public static function bareUrlEntries(): iterable
    {
        $now = new \DateTimeImmutable('2026-09-14T00:00:00+00:00');

        yield 'an anonymous 404' => [new HttpResult('https://api.github.com/repos/acme/private', 404, '', $now->modify('-1 hour'))];
        yield 'a 301' => [new HttpResult('https://api.github.com/repos/acme/private', 301, '', $now->modify('-1 hour'))];
        yield 'a proxy page' => [new HttpResult('https://api.github.com/repos/acme/private', 200, '<html>sign in</html>', $now->modify('-1 hour'))];
    }

    /**
     * @dataProvider bareUrlEntries
     */
    #[DataProvider('bareUrlEntries')]
    public function testARequestWithCredentialsReadsNoEntryUnderTheUrlAlone(HttpResult $bare): void
    {
        $calls = 0;
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $cache = new ArrayCache();
        $url = 'https://api.github.com/repos/acme/private';
        $cache->set($url, $bare);
        $client = new CachingHttpClient($this->inner([$url => new HttpResult($url, 200, '{"archived":false}', $clock->now())], $calls), $cache, self::TTL, $clock);

        $result = $client->fetchAll([$url], ['Accept: application/json', 'Authorization: token t'])[$url];

        self::assertSame([200, '{"archived":false}', false, 1], [$result->status(), $result->body(), $result->fromCache(), $calls]);
    }

    public function testARequestWithoutCredentialsReadsOnlyTheAnonymousEntry(): void
    {
        $calls = 0;
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $cache = new ArrayCache();
        $url = 'https://api.github.com/repos/acme/public';
        $cache->set($url, new HttpResult($url, 404, '', $clock->now()->modify('-1 hour')));
        $cache->set($url.' anonymous', new HttpResult($url, 200, '{"archived":true}', $clock->now()->modify('-1 hour')));
        $client = new CachingHttpClient($this->inner([], $calls), $cache, self::TTL, $clock);

        $result = $client->fetchAll([$url], ['Accept: application/json'])[$url];

        self::assertSame([200, '{"archived":true}', true, 0], [$result->status(), $result->body(), $result->fromCache(), $calls]);
    }

    public function testTheStaleAndOfflineLookupsKeepTheCredentialStateApart(): void
    {
        $calls = 0;
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $cache = new ArrayCache();
        $cache->set('https://a'.self::CREDENTIALED, new HttpResult('https://a', 200, '["with token"]', $clock->now()->modify('-3 days')));

        $online = new CachingHttpClient($this->inner(['https://a' => HttpResult::failure('https://a', 'timeout', $clock->now())], $calls), $cache, self::TTL, $clock);
        self::assertSame('timeout', $online->fetchAll(['https://a'])['https://a']->error(), 'no fallback to the answer of a request with a token');

        $offline = new CachingHttpClient($this->inner([], $calls), $cache, self::TTL, $clock, true);
        self::assertSame('offline and not cached: https://a', $offline->fetchAll(['https://a'])['https://a']->error());
        self::assertSame('["with token"]', $offline->fetchAll(['https://a'], self::TOKEN)['https://a']->body());
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
        $client = new CachingHttpClient($this->inner(['https://a' => new HttpResult('https://a', 200, '["json"]', $clock->now())], $calls), $cache, self::TTL, $clock);
        $client->fetchAll(['https://a']);
        $cached = $client->fetchAll(['https://a'])['https://a'];

        self::assertTrue($cached->fromCache());
        self::assertSame('https://a', $cached->url());
        self::assertSame('["json"]', $cached->body());
        self::assertSame(1, $calls);
    }

    /** A real-clock test: a future LOCKROT_TODAY pins the answer that the run reads, not the time that the shared cache keeps. */
    public function testAnAnswerUnderAFutureLockrotTodayIsStoredWithTheRealTime(): void
    {
        $calls = 0;
        $clock = Clock::fromEnvironment(['LOCKROT_TODAY' => '2999-01-01']);
        $cache = new ArrayCache();
        $before = $clock->fetchTime()->getTimestamp();
        $client = new CachingHttpClient($this->inner(['https://a' => new HttpResult('https://a', 200, '{}', $clock->now())], $calls), $cache, self::TTL, $clock);

        $result = $client->fetchAll(['https://a'], self::TOKEN)['https://a'];

        self::assertSame('2999-01-01T00:00:00+00:00', $result->fetchedAt()->format(\DATE_ATOM));
        $stored = ($cache->get('https://a'.self::CREDENTIALED) ?? self::fail('the answer is stored'))->fetchedAt()->getTimestamp();
        self::assertGreaterThanOrEqual($before, $stored);
        self::assertLessThanOrEqual($clock->fetchTime()->getTimestamp(), $stored);
    }

    public function testAnAnswerUnderAPastLockrotTodayIsStoredWithItsOwnTime(): void
    {
        $calls = 0;
        $clock = Clock::fromEnvironment(['LOCKROT_TODAY' => '2026-09-14']);
        $cache = new ArrayCache();
        $client = new CachingHttpClient($this->inner(['https://a' => new HttpResult('https://a', 200, '{}', $clock->now())], $calls), $cache, self::TTL, $clock);

        $client->fetchAll(['https://a'], self::TOKEN);

        self::assertSame('2026-09-14T00:00:00+00:00', ($cache->get('https://a'.self::CREDENTIALED) ?? self::fail('the answer is stored'))->fetchedAt()->format(\DATE_ATOM));
    }
}
