<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
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
    private Clock $clock;
    private Deadline $deadline;

    /**
     * @param IOInterface $io       the IO the downloader was built with: its authentications are what
     *                              Composer's AuthHelper adds to requests, see {@see headersFor()}
     * @param ?Deadline   $deadline install-time budget; null (and a never-expiring deadline) keeps {@see DEFAULT_TIMEOUT}
     */
    public function __construct(HttpDownloader $downloader, IOInterface $io, Clock $clock, ?Deadline $deadline = null)
    {
        $this->downloader = (new Loop($downloader))->getHttpDownloader();
        $this->io = $io;
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
     * The headers a request to $url carries: $headers, minus lockrot's own `Authorization` when
     * Composer is about to add one of its own.
     *
     * Composer's AuthHelper appends `Authorization: token …` to every api.github.com request once
     * github.com has credentials — `github-oauth` in auth.json or COMPOSER_AUTH, or what
     * setup-php writes on a CI runner. {@see \Lockrot\Data\GitHub\GitHubClient::headersFor()} sends the
     * same header for the token lockrot resolved, and GitHub answers a request that carries two
     * Authorization headers with 401 "Bad credentials", whatever the tokens are. So whenever
     * Composer has github.com credentials, its header is the one on the wire and lockrot's is
     * dropped here. The token lockrot resolved still decides whether the repository-activity cap
     * is lifted; only the header changes.
     *
     * api.github.com is looked up under both names because AuthHelper does the same: credentials
     * stored for `api.github.com` count, and so do the canonical `github.com` ones.
     *
     * @param list<string> $headers
     *
     * @return list<string>
     */
    public static function headersFor(IOInterface $io, string $url, array $headers): array
    {
        if (parse_url($url, \PHP_URL_HOST) !== 'api.github.com') {
            return $headers;
        }
        if (!$io->hasAuthentication('github.com') && !$io->hasAuthentication('api.github.com')) {
            return $headers;
        }

        return array_values(array_filter($headers, static function (string $header): bool {
            return stripos($header, 'authorization:') !== 0;
        }));
    }

    public function fetchAll(array $urls, array $headers = []): array
    {
        /** @var array<string, HttpResult> $results */
        $results = [];
        $timeout = $this->timeoutSeconds();
        foreach (array_unique($urls) as $url) {
            $options = [
                'http' => ['timeout' => $timeout, 'header' => self::headersFor($this->io, $url, $headers)],
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
