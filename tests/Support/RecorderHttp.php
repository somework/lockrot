<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Clock;
use Lockrot\Data\Http\HttpResult;

/**
 * The request of `bin/record-fixtures`. PHP's http wrapper sends the request headers again to the
 * host that a redirect names, so the recorder follows redirects itself and sends a credential only
 * to the scheme, host and port that it was meant for.
 */
final class RecorderHttp
{
    private const MAX_REDIRECTS = 5;
    private const CREDENTIAL_HEADER = '{^(authorization|private-token):}i';

    /**
     * @param list<string>                                                                  $headers
     * @param callable(string, array<string, mixed>): array{string|false, list<string>} $transport body and response header lines of one request
     * @param Clock                                                                     $clock     the fetch time of each result
     */
    public static function fetch(string $url, array $headers, ?string $post, bool $follow, callable $transport, Clock $clock): HttpResult
    {
        $origin = self::origin($url);
        $current = $url;
        for ($hop = 0;; ++$hop) {
            $sent = self::origin($current) === $origin ? $headers : array_values(array_filter($headers, static fn (string $header): bool => preg_match(self::CREDENTIAL_HEADER, $header) !== 1));
            $http = ['method' => $post === null ? 'GET' : 'POST', 'header' => implode("\r\n", array_merge(['User-Agent: lockrot-recorder'], $sent)), 'ignore_errors' => true, 'timeout' => 30, 'follow_location' => 0];
            if ($post !== null) {
                $http['content'] = $post;
            }
            [$body, $responseHeaders] = $transport($current, $http);
            $status = 0;
            $location = null;
            foreach ($responseHeaders as $line) {
                if (preg_match('{^HTTP/\S+\s+(\d{3})}', $line, $m) === 1) {
                    $status = (int) $m[1];
                    $location = null;
                } elseif (preg_match('{^location:\s*(\S+)}i', $line, $m) === 1) {
                    $location = $m[1];
                }
            }
            if (!$follow || $location === null || $status < 300 || $status > 399 || $hop >= self::MAX_REDIRECTS) {
                return new HttpResult($url, $status, $body === false ? null : $body, $clock->fetchTime());
            }
            $current = self::resolve($current, $location);
            // RFC 9110 section 15.4.4: a 303 answer names a resource to GET.
            $post = $status === 303 ? null : $post;
        }
    }

    /**
     * The transport of a real run.
     *
     * @param array<string, mixed> $http
     *
     * @return array{string|false, list<string>}
     */
    public static function stream(string $url, array $http): array
    {
        $body = @file_get_contents($url, false, stream_context_create(['http' => $http]));

        // PHP leaves the variable unset when the request fails before a response.
        return [$body, $http_response_header ?? []]; // @phpstan-ignore nullCoalesce.variable
    }

    /** The target of a `Location` reference, after RFC 3986 section 5.2.2 without dot-segment removal. */
    private static function resolve(string $current, string $location): string
    {
        if (preg_match('{^[a-z][a-z0-9+.-]*:}i', $location) === 1) {
            return $location;
        }
        if (strpos($location, '//') === 0) {
            return (string) parse_url($current, \PHP_URL_SCHEME).':'.$location;
        }
        if (strpos($location, '/') === 0) {
            return self::origin($current).$location;
        }
        $path = (string) parse_url($current, \PHP_URL_PATH);
        if (strpos($location, '?') === 0) {
            return self::origin($current).$path.$location;
        }

        $slash = strrpos($path, '/');

        return self::origin($current).($slash === false ? '/' : substr($path, 0, $slash + 1)).$location;
    }

    private static function origin(string $url): string
    {
        $port = parse_url($url, \PHP_URL_PORT);

        return (string) parse_url($url, \PHP_URL_SCHEME).'://'.(string) parse_url($url, \PHP_URL_HOST).($port === null || $port === false ? '' : ':'.$port);
    }
}
