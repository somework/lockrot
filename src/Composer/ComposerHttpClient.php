<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use Composer\Util\Url;
use Lockrot\Clock;
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

    private HttpDownloader $downloader;
    private IOInterface $io;
    private Config $config;
    private Clock $clock;
    private Deadline $deadline;

    /**
     * @param IOInterface $io       the IO that the downloader was built with, see {@see withoutRedundantAuthorization()}
     * @param Config      $config   the Config that the downloader was built with: Composer resolves the origin of a request against its `gitlab-domains`
     * @param ?Deadline   $deadline install-time budget, where null or a never-expiring deadline keeps {@see DEFAULT_TIMEOUT}
     */
    public function __construct(HttpDownloader $downloader, IOInterface $io, Config $config, Clock $clock, ?Deadline $deadline = null)
    {
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
     * GitHub answers 401 "Bad credentials". The origin and credential rules copy AuthHelper of
     * Composer 2.2 and 2.10, so the header that Composer adds is the one on the wire. The token that
     * lockrot resolved still lifts
     * the repository-activity cap. See docs/internals.md#which-credentials.
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

        return array_values(array_filter($headers, static fn (string $header): bool => !self::isCredentialHeader($header)));
    }

    /** @param array<mixed> $headers */
    private static function carriesCredentials(array $headers): bool
    {
        foreach ($headers as $header) {
            if (\is_string($header) && self::isCredentialHeader($header)) {
                return true;
            }
        }

        return false;
    }

    private static function isCredentialHeader(string $header): bool
    {
        return stripos($header, 'authorization:') === 0 || stripos($header, 'private-token:') === 0;
    }

    public function fetchAll(array $urls, array $headers = []): array
    {
        /** @var array<string, HttpResult> $results */
        $results = [];
        $timeout = $this->timeoutSeconds();
        foreach (array_unique($urls) as $url) {
            $options = [
                'http' => ['timeout' => $timeout, 'header' => self::withoutRedundantAuthorization($this->io, $this->config, $url, $headers)],
                'retry-auth-failure' => false,
            ];
            $promise = $this->downloader->add($url, $options);
            $promise->then(
                function (Response $response) use ($url, &$results): void {
                    $results[$url] = new HttpResult($url, $response->getStatusCode(), $response->getBody(), $this->clock->now());
                },
                function (\Throwable $e) use ($url, &$results): void {
                    $status = $e instanceof TransportException ? (int) ($e->getStatusCode() ?? 0) : 0;
                    $results[$url] = new HttpResult($url, $status, null, $this->clock->now(), RepositoryUrl::inText($e->getMessage()));
                }
            );
        }
        $this->downloader->wait();
        foreach ($urls as $url) {
            if (!isset($results[$url])) {
                $results[$url] = HttpResult::failure($url, 'no response', $this->clock->now());
            }
        }

        return $results;
    }
}
