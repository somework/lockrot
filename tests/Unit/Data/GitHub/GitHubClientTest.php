<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\GitHub;

use Lockrot\Data\GitHub\GitHubClient;
use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Http\HttpResult;
use PHPUnit\Framework\TestCase;

final class GitHubClientTest extends TestCase
{
    /**
     * @param array<string, HttpResult> $map
     * @param list<string>              $seenHeaders
     */
    private function http(array $map, array &$seenHeaders): HttpClientInterface
    {
        return new class ($map, $seenHeaders) implements HttpClientInterface {
            /** @var array<string, HttpResult> */
            private array $map;
            /** @var list<string> */
            public array $seenHeaders;

            /**
             * @param array<string, HttpResult> $map
             * @param list<string>              $seenHeaders
             */
            public function __construct(array $map, array &$seenHeaders)
            {
                $this->map = $map;
                $this->seenHeaders = &$seenHeaders;
            }

            /**
             * @param list<string> $urls
             * @param list<string> $headers
             *
             * @return array<string, HttpResult>
             */
            public function fetchAll(array $urls, array $headers = []): array
            {
                $this->seenHeaders = $headers;
                $out = [];
                foreach ($urls as $url) {
                    $out[$url] = $this->map[$url] ?? new HttpResult($url, 404, '', new \DateTimeImmutable('2026-09-14T00:00:00+00:00'));
                }

                return $out;
            }
        };
    }

    public function testParsesArchivedAndPushedAtAndSendsTokenHeader(): void
    {
        $headers = [];
        $url = 'https://api.github.com/repos/Grandt/PHPZip';
        $body = '{"full_name":"Grandt/PHPZip","archived":false,"pushed_at":"2015-11-16T16:31:37Z"}';
        $client = new GitHubClient($this->http([$url => new HttpResult($url, 200, $body, new \DateTimeImmutable('2026-09-14T00:00:00+00:00'))], $headers), 'ghp_x');
        $batch = $client->fetch(['Grandt/PHPZip', 'gone/repo']);
        $activity = $batch->activity()['Grandt/PHPZip'];
        self::assertFalse($activity->isArchived());
        $pushedAt = $activity->pushedAt();
        self::assertNotNull($pushedAt);
        self::assertSame('2015-11-16', $pushedAt->format('Y-m-d'));
        self::assertSame(['gone/repo'], $batch->notFound());
        self::assertContains('Authorization: token ghp_x', $headers);
        self::assertContains('Accept: application/vnd.github+json', $headers);
    }

    public function testNoTokenNoAuthHeader(): void
    {
        $headers = [];
        (new GitHubClient($this->http([], $headers), null))->fetch(['a/b']);
        foreach ($headers as $h) {
            self::assertStringStartsNotWith('Authorization', $h);
        }
    }

    public function testRateLimitAndFailures(): void
    {
        $headers = [];
        $url = 'https://api.github.com/repos/a/b';
        $client = new GitHubClient($this->http([$url => new HttpResult($url, 403, '{"message":"API rate limit exceeded"}', new \DateTimeImmutable())], $headers), null);
        $batch = $client->fetch(['a/b']);
        self::assertTrue($batch->rateLimited());
        self::assertArrayHasKey('a/b', $batch->failed());
    }

    public function testArchivedRepo(): void
    {
        $headers = [];
        $url = 'https://api.github.com/repos/a/b';
        $client = new GitHubClient($this->http([$url => new HttpResult($url, 200, '{"archived":true,"pushed_at":"2020-01-01T00:00:00Z"}', new \DateTimeImmutable())], $headers), null);
        $activity = $client->fetch(['a/b'])->activity()['a/b'];
        self::assertTrue($activity->isArchived());
    }

    public function testHasTokenReflectsWhetherANonEmptyTokenWasGiven(): void
    {
        $headers = [];
        self::assertTrue((new GitHubClient($this->http([], $headers), 'ghp_x'))->hasToken());
        self::assertFalse((new GitHubClient($this->http([], $headers), null))->hasToken());
        self::assertFalse((new GitHubClient($this->http([], $headers), ''))->hasToken());
    }
}
