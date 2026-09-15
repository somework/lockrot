<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Downloader\TransportException;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use Lockrot\Clock;
use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Deadline;

/**
 * Parallel HTTP through Composer's HttpDownloader (curl multi when available).
 * API: HttpDownloader::add()/wait() — Composer 2.10.3 src/Composer/Util/HttpDownloader.php:131,363; 2.2.25 :143,370.
 *
 * A HttpDownloader returned by Factory::createHttpDownloader() defaults to sync-only
 * mode: add() throws LogicException("...must use the HttpDownloader instance which is
 * part of a Composer\Loop instance...") until async is enabled. Composer enables it by
 * wrapping the downloader in Composer\Util\Loop, whose constructor calls
 * HttpDownloader::enableAsync() — Composer 2.10.3 src/Composer/Util/Loop.php:32,34 and
 * getHttpDownloader() :42; 2.2.25 same file :33,36 and :47. We go through Loop rather than
 * calling the @internal-marked enableAsync() ourselves; the process-executor half of Loop
 * stays unused (we only need add()/wait()).
 */
final class ComposerHttpClient implements HttpClientInterface
{
    public const DEFAULT_TIMEOUT = 10;

    private HttpDownloader $downloader;
    private Clock $clock;
    private Deadline $deadline;

    /** @param ?Deadline $deadline install-time budget; null (and a never-expiring deadline) keeps {@see DEFAULT_TIMEOUT} */
    public function __construct(HttpDownloader $downloader, Clock $clock, ?Deadline $deadline = null)
    {
        $this->downloader = (new Loop($downloader))->getHttpDownloader();
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

    public function fetchAll(array $urls, array $headers = []): array
    {
        /** @var array<string, HttpResult> $results */
        $results = [];
        $options = ['http' => ['timeout' => $this->timeoutSeconds(), 'header' => $headers], 'retry-auth-failure' => false];
        foreach (array_unique($urls) as $url) {
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
