<?php

declare(strict_types=1);

namespace Lockrot\Data\GitHub;

use Lockrot\Data\Http\HttpClientInterface;

final class GitHubClient
{
    public const DEFAULT_API_BASE = 'https://api.github.com/repos/';
    /** Fixed cache TTL for GitHub repository activity; no user-facing knob (ruling: --refresh/cache-ttl removed). */
    public const CACHE_TTL = 86400;

    private HttpClientInterface $http;
    private ?string $token;
    private string $apiBase;

    public function __construct(HttpClientInterface $http, ?string $token, string $apiBase = self::DEFAULT_API_BASE)
    {
        $this->http = $http;
        $this->token = $token;
        $this->apiBase = $apiBase;
    }

    public function url(string $repo): string
    {
        return $this->apiBase.$repo;
    }

    public function hasToken(): bool
    {
        return $this->token !== null && $this->token !== '';
    }

    /** @return list<string> */
    public function headers(): array
    {
        $headers = ['Accept: application/vnd.github+json', 'User-Agent: lockrot'];
        if ($this->hasToken()) {
            // Same scheme Composer itself uses for api.github.com — Composer 2.10.3
            // src/Composer/Util/AuthHelper.php:302.
            $headers[] = 'Authorization: token '.$this->token;
        }

        return $headers;
    }

    /** @param list<string> $repos */
    public function fetch(array $repos): GitHubBatch
    {
        if ($repos === []) {
            return GitHubBatch::empty();
        }
        $urls = [];
        foreach ($repos as $repo) {
            $urls[$repo] = $this->url($repo);
        }
        $responses = $this->http->fetchAll(array_values($urls), $this->headers());

        $activity = [];
        $notFound = [];
        $failed = [];
        $rateLimited = false;
        foreach ($urls as $repo => $url) {
            $result = $responses[$url];
            if ($result->isNotFound()) {
                $notFound[] = $repo;
                continue;
            }
            $json = $result->isOk() ? $result->json() : null;
            if ($json === null) {
                if ($result->status() === 403 || $result->status() === 429) {
                    $rateLimited = true;
                }
                $failed[$repo] = $result->error() ?? ('HTTP '.$result->status());
                continue;
            }
            $pushedAt = null;
            if (\is_string($json['pushed_at'] ?? null)) {
                try {
                    $pushedAt = new \DateTimeImmutable($json['pushed_at']);
                } catch (\Exception $e) {
                    $pushedAt = null;
                }
            }
            $activity[$repo] = new RepositoryActivity($repo, ($json['archived'] ?? false) === true, $pushedAt, $result->fetchedAt());
        }

        return new GitHubBatch($activity, $notFound, $failed, $rateLimited);
    }
}
