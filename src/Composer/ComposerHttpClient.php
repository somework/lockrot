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
use Lockrot\Deadline;

/**
 * Parallel HTTP through Composer's HttpDownloader (curl multi when available), using its
 * add()/wait() pair.
 *
 * A HttpDownloader returned by Factory::createHttpDownloader() defaults to sync-only mode: add()
 * throws a LogicException until async is enabled. Composer enables it by wrapping the downloader in
 * Composer\Util\Loop, whose constructor calls HttpDownloader::enableAsync(). Loop is used here for
 * that reason rather than calling enableAsync() directly, which Composer marks internal; the
 * process-executor half of Loop stays unused.
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
     * @param IOInterface $io       the IO the downloader was built with: its authentications are what
     *                              Composer's AuthHelper adds to requests, see
     *                              {@see withoutRedundantAuthorization()}
     * @param Config      $config   the Config the downloader was built with: its `gitlab-domains` are
     *                              what Composer resolves a request's origin against
     * @param ?Deadline   $deadline install-time budget; null (and a never-expiring deadline) keeps {@see DEFAULT_TIMEOUT}
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
     * The per-request timeout for the next fetchAll(): the default, or what is left of the
     * install-time budget at this moment (never below one second). Computed at request time rather
     * than at construction because the repository-metadata pass runs in between — a value frozen
     * when the client was built would let the GitHub round outlast the budget by a whole timeout.
     */
    public function timeoutSeconds(): int
    {
        if ($this->deadline->isNever()) {
            return self::DEFAULT_TIMEOUT;
        }

        return max(1, (int) ceil($this->deadline->remainingSeconds()));
    }

    /**
     * The headers a request to $url carries: $headers, minus lockrot's own credential headers
     * (`Authorization`, `PRIVATE-TOKEN`) when Composer is about to add credentials of its own.
     *
     * Composer's AuthHelper adds a header to every request whose origin it holds credentials for —
     * `github-oauth`, `gitlab-token`, `gitlab-oauth`, `http-basic` or `bearer` in auth.json or
     * COMPOSER_AUTH, or what setup-php writes on a CI runner. {@see \Lockrot\Data\Forge\GitHubApi}
     * and {@see \Lockrot\Data\Forge\GitLabApi} send their own header for the token lockrot
     * resolved, and a request carrying two is refused (GitHub answers 401 "Bad credentials",
     * whatever the tokens are). So whenever Composer has credentials for the origin, its header is
     * the one on the wire and lockrot's is dropped here. The token lockrot resolved still decides
     * whether the repository-activity cap is lifted; only the header changes.
     *
     * The predicate is AuthHelper's own, on both Composer 2.2 and 2.10: the origin is
     * `Url::getOrigin()`'s — every `*.github.com` host folds into `github.com`, and a host that is
     * (or is the host part of) a `gitlab-domains` entry resolves to that entry — and credentials
     * count when they are stored under that origin or, for `api.github.com`/`api.bitbucket.org`,
     * under the site host. Credentials stored under `api.github.com` alone never reach AuthHelper
     * and are left alone here too; a github.com OAuth token is added to api.github.com requests
     * only, so a release download from github.com keeps lockrot's header. Two shapes of credentials make AuthHelper add something other
     * than a header (`client-certificate`, an undecodable `custom-headers`); they are not told
     * apart, and a request under them goes out without lockrot's token.
     *
     * @param list<string> $headers
     *
     * @return list<string>
     */
    public static function withoutRedundantAuthorization(IOInterface $io, Config $config, string $url, array $headers): array
    {
        if ($url === "") {
            return $headers;
        }
        $origin = Url::getOrigin($config, $url);
        if (!$io->hasAuthentication($origin)) {
            if (!\in_array($origin, ['api.bitbucket.org', 'api.github.com'], true) || !$io->hasAuthentication((string) substr($origin, 4))) {
                return $headers;
            }
            $origin = (string) substr($origin, 4);
        }
        // AuthHelper's one exception: a github.com OAuth token is added to api.github.com requests
        // only, so on any other github.com URL (a release download, say) lockrot's header stays.
        if ($origin === 'github.com' && $io->getAuthentication($origin)['password'] === 'x-oauth-basic' && preg_match('{^https?://api\.github\.com/}', $url) !== 1) {
            return $headers;
        }

        return array_values(array_filter($headers, static function (string $header): bool {
            return stripos($header, 'authorization:') !== 0 && stripos($header, 'private-token:') !== 0;
        }));
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
                    $results[$url] = new HttpResult($url, $status, null, $this->clock->now(), $e->getMessage());
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
