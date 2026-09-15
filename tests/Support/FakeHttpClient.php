<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Http\HttpResult;

/**
 * A map of URL to canned {@see HttpResult}, plus a log of what was asked for.
 *
 * The self-update tests need both halves: the responses drive the code under test, and the log is
 * how "up to date means nothing is downloaded" is asserted at all. {@see \Lockrot\Data\Http\RecordedHttpClient}
 * cannot do that — it is production playback keyed by file name, with no recording side.
 */
final class FakeHttpClient implements HttpClientInterface
{
    public const AT = '2026-09-14T00:00:00+00:00';

    /** @var array<string, HttpResult> */
    private array $responses;
    /** @var list<string> */
    private array $requested = [];
    /** @var list<list<string>> */
    private array $headers = [];

    /** @param array<string, HttpResult> $responses keyed by URL */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public static function ok(string $url, string $body): HttpResult
    {
        return new HttpResult($url, 200, $body, new \DateTimeImmutable(self::AT));
    }

    public static function status(string $url, int $status, ?string $body = null): HttpResult
    {
        return new HttpResult($url, $status, $body, new \DateTimeImmutable(self::AT));
    }

    public static function transportFailure(string $url, string $error): HttpResult
    {
        return HttpResult::failure($url, $error, new \DateTimeImmutable(self::AT));
    }

    /**
     * @param list<string> $urls
     * @param list<string> $headers
     *
     * @return array<string, HttpResult>
     */
    public function fetchAll(array $urls, array $headers = []): array
    {
        $this->headers[] = $headers;
        $results = [];
        foreach ($urls as $url) {
            $this->requested[] = $url;
            $results[$url] = $this->responses[$url] ?? self::transportFailure($url, 'not stubbed: '.$url);
        }

        return $results;
    }

    /** @return list<string> every URL asked for, in order, across all fetchAll() calls */
    public function requested(): array
    {
        return $this->requested;
    }

    /** @return list<list<string>> the header list of each fetchAll() call, in order */
    public function headers(): array
    {
        return $this->headers;
    }
}
