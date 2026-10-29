<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use Composer\Util\RemoteFilesystem;
use Composer\Util\Url;
use Lockrot\Clock;
use Lockrot\Data\Http\CachingHttpClient;
use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Data\Repository\RepositoryUrl;
use Lockrot\Deadline;

/**
 * A HttpDownloader from Factory::createHttpDownloader() is sync-only, and add() throws a
 * LogicException until async is enabled. The constructor of Composer\Util\Loop enables it, and
 * Composer marks HttpDownloader::enableAsync() internal, so this class wraps the downloader in Loop.
 *
 * @internal
 */
final class ComposerHttpClient implements HttpClientInterface
{
    public const DEFAULT_TIMEOUT = 10;
    /** Composer's own cap on the hops of one request. */
    private const MAX_REDIRECTS = 20;

    private HttpDownloader $downloader;
    private IOInterface $io;
    private Config $config;
    private Clock $clock;
    private Deadline $deadline;
    private bool $refusesRedirects;

    /**
     * @param IOInterface $io       the IO that the downloader was built with, see {@see withoutRedundantAuthorization()}
     * @param Config      $config   the Config that the downloader was built with: Composer resolves the origin of a request against its `gitlab-domains`
     * @param ?Deadline   $deadline install-time budget, where null or a never-expiring deadline keeps {@see DEFAULT_TIMEOUT}
     * @param ?bool       $refusesRedirects whether the downloader takes `prevent_url_access_callable`, null for whether curl runs it: RemoteFilesystem throws on the option
     */
    public function __construct(HttpDownloader $downloader, IOInterface $io, Config $config, Clock $clock, ?Deadline $deadline = null, ?bool $refusesRedirects = null)
    {
        $this->refusesRedirects = $refusesRedirects ?? HttpDownloader::isCurlEnabled();
        $this->downloader = (new Loop($downloader))->getHttpDownloader();
        $this->io = $io;
        $this->config = $config;
        $this->clock = $clock;
        $this->deadline = $deadline ?? Deadline::never();
    }

    /**
     * The timeout of each request in the next fetchAll(): the default, or the time left of the
     * budget, at least one second. It is read at request time, because the repository-metadata pass
     * runs after construction, and with a frozen value the activity requests outlast the budget.
     */
    public function timeoutSeconds(): int
    {
        return self::timeoutFor($this->deadline);
    }

    public static function timeoutFor(Deadline $deadline): int
    {
        if ($deadline->isNever()) {
            return self::DEFAULT_TIMEOUT;
        }

        return max(1, (int) ceil($deadline->remainingSeconds()));
    }

    /**
     * $headers without lockrot's own credential headers (`Authorization`, `PRIVATE-TOKEN`) when
     * Composer's AuthHelper adds credentials of its own, because a request with two is refused and
     * GitHub answers 401 "Bad credentials". The origin and credential rules copy Composer's
     * AuthHelper, so the header that Composer adds is the one on the wire. The token that lockrot
     * resolved still lifts the repository-activity cap. See docs/internals.md#which-credentials.
     *
     * @param list<string> $headers
     *
     * @return list<string>
     */
    public static function withoutRedundantAuthorization(IOInterface $io, Config $config, string $url, array $headers): array
    {
        if ($url === '') {
            return $headers;
        }
        $origin = Url::getOrigin($config, $url);
        if (!$io->hasAuthentication($origin)) {
            // The fallback of AuthHelper::findAuthOrigin(). getOrigin() folds `api.github.com` into
            // github.com, so it never arrives here, but it stays listed to match.
            if (!\in_array($origin, ['api.bitbucket.org', 'api.github.com'], true) || !$io->hasAuthentication((string) substr($origin, 4))) {
                return $headers;
            }
            $origin = (string) substr($origin, 4);
        }
        $auth = $io->getAuthentication($origin);
        // AuthHelper adds a github.com OAuth token to api.github.com requests only, so lockrot's
        // header stays on any other github.com URL.
        if ($origin === 'github.com' && $auth['password'] === 'x-oauth-basic' && preg_match('{^https?://api\.github\.com/}', $url) !== 1) {
            return $headers;
        }
        // AuthHelper adds no credential header for an SSL client certificate, or for custom headers
        // that carry none, so lockrot's header stays.
        if ($auth['username'] === 'client-certificate') {
            return $headers;
        }
        if ($auth['password'] === 'custom-headers') {
            $custom = json_decode((string) $auth['username'], true);
            if (!\is_array($custom) || !self::carriesCredentials($custom)) {
                return $headers;
            }
        }

        return self::withoutCredentials($headers);
    }

    /**
     * @param list<string> $headers
     *
     * @return list<string>
     */
    private static function withoutCredentials(array $headers): array
    {
        return array_values(array_filter($headers, static fn (string $header): bool => !CachingHttpClient::isCredentialHeader($header)));
    }

    /** @param array<mixed> $headers */
    private static function carriesCredentials(array $headers): bool
    {
        foreach ($headers as $header) {
            if (\is_string($header) && CachingHttpClient::isCredentialHeader($header)) {
                return true;
            }
        }

        return false;
    }

    public function fetchAll(array $urls, array $headers = []): array
    {
        /** @var array<string, HttpResult> $results */
        $results = [];
        /** @var array<string, string> $redirects url => the URL on another origin that a redirect named */
        $redirects = [];
        /** @var array<string, string> $stopped the URLs whose request, redirect hop or retry the install-time budget stopped */
        $stopped = [];
        // Composer's curl downloader asks this before each request, hop and retry starts.
        // Requests above its limit wait in a queue.
        $budgetEnded = function (string $url) use (&$stopped): bool {
            if (!$this->deadline->isPast()) {
                return false;
            }
            $stopped[$url] = $url;

            return true;
        };
        $timeout = $this->timeoutSeconds();
        foreach (array_unique($urls) as $url) {
            $sent = self::withoutRedundantAuthorization($this->io, $this->config, $url, $headers);
            $options = self::options($timeout, $sent);
            if (!$this->refusesRedirects && self::carriesCredentials($sent)) {
                $this->fetchHolding($url, $options, $results, $redirects);
                continue;
            }
            if ($this->refusesRedirects) {
                $options['prevent_url_access_callable'] = static function (string $target) use ($url, &$redirects, $budgetEnded): bool {
                    if ($budgetEnded($url)) {
                        return true;
                    }
                    if ($target === '' || self::origin($target) === self::origin($url)) {
                        return false;
                    }
                    $redirects[$url] = $target;

                    return true;
                };
            }
            $this->add($url, $url, $options, $results);
        }
        $this->downloader->wait();
        // Composer replays the request's own headers to a redirect's target, so the request goes
        // again to that target without lockrot's credentials.
        $timeout = $this->timeoutSeconds();
        foreach ($redirects as $url => $target) {
            if ($this->deadline->isPast()) {
                $stopped[$url] = $url;

                continue;
            }
            $options = self::options($timeout, self::withoutCredentials($headers));
            if ($this->refusesRedirects) {
                $options['prevent_url_access_callable'] = static fn (): bool => $budgetEnded($url);
            }
            $this->add($url, $target, $options, $results);
        }
        if ($redirects !== []) {
            $this->downloader->wait();
        }
        foreach ($stopped as $url) {
            $results[$url] = HttpResult::failure($url, 'install-time budget spent', $this->clock->now());
        }
        foreach ($urls as $url) {
            if (!isset($results[$url])) {
                $results[$url] = HttpResult::failure($url, 'no response', $this->clock->now());
            }
        }

        return $results;
    }

    /**
     * @param array{http: array{timeout: int, header: list<string>}, retry-auth-failure: false, prevent_url_access_callable?: \Closure(string): bool} $options
     * @param array<string, HttpResult>                                                                                                        $results the answer for $url goes in here
     */
    private function add(string $url, string $target, array $options, array &$results): void
    {
        $this->downloader->add($target, $options)->then(
            function (Response $response) use ($url, &$results): void {
                $results[$url] = new HttpResult($url, $response->getStatusCode(), $response->getBody(), $this->clock->now(), null, array_values($response->getHeaders()));
            },
            function (\Throwable $e) use ($url, &$results): void {
                $results[$url] = $this->failed($url, $e);
            }
        );
    }

    private function failed(string $url, \Throwable $e): HttpResult
    {
        $status = $e instanceof TransportException ? (int) ($e->getStatusCode() ?? 0) : 0;
        $headers = $e instanceof TransportException ? $e->getHeaders() ?? [] : [];

        return new HttpResult($url, $status, null, $this->clock->now(), RepositoryUrl::inText($e->getMessage()), array_values(array_filter($headers, 'is_string')));
    }

    /**
     * A request with lockrot's credentials where the downloader cannot stop a redirect: without
     * curl. RemoteFilesystem follows no hop past its `redirects` cap, so lockrot
     * follows a same-origin hop with the credentials. A target on another origin goes in $redirects
     * for a request without them.
     *
     * @param array{http: array{timeout: int, header: list<string>}, retry-auth-failure: false} $options
     * @param array<string, HttpResult>                                                       $results
     * @param array<string, string>                                                           $redirects
     */
    private function fetchHolding(string $url, array $options, array &$results, array &$redirects): void
    {
        $filesystem = new RemoteFilesystem($this->io, $this->config, $this->downloader->getOptions(), (bool) $this->config->get('disable-tls'));
        $target = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS && $target !== ''; ++$hop) {
            // Each request blocks until it ends, so it takes only what the install-time budget has left.
            if ($this->deadline->isPast()) {
                $results[$url] = HttpResult::failure($url, 'install-time budget spent', $this->clock->now());

                return;
            }
            $options['http']['timeout'] = $this->timeoutSeconds();
            try {
                $body = $filesystem->getContents(Url::getOrigin($this->config, $target), $target, false, $options + ['redirects' => \PHP_INT_MAX]);
            } catch (\Throwable $e) {
                $results[$url] = $this->failed($url, $e);

                return;
            }
            $headers = $filesystem->getLastHeaders();
            $status = (int) RemoteFilesystem::findStatusCode($headers);
            $location = $status >= 300 && $status < 400 ? Response::findHeaderValue($headers, 'location') : null;
            if ($location === null || $location === '') {
                $results[$url] = new HttpResult($url, $status, \is_string($body) ? $body : null, $this->clock->now(), null, $headers);

                return;
            }
            $next = self::resolve($target, $location);
            if (self::origin($next) !== self::origin($target)) {
                $redirects[$url] = $next;

                return;
            }
            $target = $next;
        }
        $results[$url] = HttpResult::failure($url, 'too many redirects', $this->clock->now());
    }

    /** A `Location` against the URL that answered it, as Composer's CurlDownloader reads it. */
    private static function resolve(string $base, string $location): string
    {
        if (parse_url($location, \PHP_URL_SCHEME) !== null) {
            return $location;
        }
        $scheme = (string) parse_url($base, \PHP_URL_SCHEME);
        if (strpos($location, '//') === 0) {
            return $scheme.':'.$location;
        }
        if ($location[0] === '/') {
            return (string) preg_replace('{^([^:]+://[^/?#]+).*$}', '$1', $base).$location;
        }

        return (string) preg_replace('{^(.+/)[^/?]*(?:\?.*)?$}', '${1}', $base).$location;
    }

    /**
     * @param list<string> $headers
     *
     * @return array{http: array{timeout: int, header: list<string>}, retry-auth-failure: false}
     */
    private static function options(int $timeout, array $headers): array
    {
        return ['http' => ['timeout' => $timeout, 'header' => $headers], 'retry-auth-failure' => false];
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts)) {
            return '';
        }

        return strtolower(($parts['scheme'] ?? '').'://'.($parts['host'] ?? '')).':'.(string) ($parts['port'] ?? '');
    }
}
