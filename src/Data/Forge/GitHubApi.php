<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

use Lockrot\Data\Http\HttpResult;

/**
 * `GET /repos/{owner}/{repo}`: `archived` and `pushed_at` (the last push to any branch).
 *
 * @internal
 */
final class GitHubApi implements ForgeApi
{
    public const DEFAULT_API_BASE = 'https://api.github.com/repos/';

    private string $apiBase;

    public function __construct(string $apiBase = self::DEFAULT_API_BASE)
    {
        $this->apiBase = $apiBase;
    }

    public function requests(RepoRef $repo, bool $authenticated): array
    {
        return ['repo' => $this->apiBase.$repo->path()];
    }

    public function headers(?string $token): array
    {
        return self::headersFor($token);
    }

    /**
     * The headers every lockrot call to api.github.com carries.
     *
     * Static because self-update reaches the same API without an analyzer behind it
     * ({@see \Lockrot\SelfUpdate\ReleaseLocator}), and the authorization scheme must not be written
     * down in two places.
     *
     * @return list<string>
     */
    public static function headersFor(?string $token): array
    {
        $headers = ['Accept: application/vnd.github+json', 'User-Agent: lockrot'];
        if ($token !== null && $token !== '') {
            // Same scheme Composer itself uses for api.github.com (see its AuthHelper).
            $headers[] = 'Authorization: token '.$token;
        }

        return $headers;
    }

    /** GitHub answers an exhausted anonymous quota with 403, a secondary limit with 429. */
    public function isRateLimited(HttpResult $result): bool
    {
        return $result->status() === 403 || $result->status() === 429;
    }

    public function activity(RepoRef $repo, array $json, \DateTimeImmutable $fetchedAt, ?\DateTimeImmutable $cachedAt): RepositoryActivity
    {
        $body = $json['repo'];

        return new RepositoryActivity($repo, ($body['archived'] ?? false) === true, JsonDate::parse($body['pushed_at'] ?? null), $fetchedAt, $cachedAt);
    }
}
