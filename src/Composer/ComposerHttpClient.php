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
    private int $timeout;

    public function __construct(HttpDownloader $downloader, Clock $clock, int $timeoutSeconds = self::DEFAULT_TIMEOUT)
    {
        $this->downloader = (new Loop($downloader))->getHttpDownloader();
        $this->clock = $clock;
        $this->timeout = $timeoutSeconds;
    }

    public function fetchAll(array $urls, array $headers = []): array
    {
        /** @var array<string, HttpResult> $results */
        $results = [];
        $options = ['http' => ['timeout' => $this->timeout, 'header' => $headers], 'retry-auth-failure' => false];
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
