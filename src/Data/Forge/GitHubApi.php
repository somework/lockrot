<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

use Lockrot\Data\Http\HttpResult;

/**
 * `pushed_at` is the last push to any branch.
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
     * Static because self-update ({@see \Lockrot\SelfUpdate\ReleaseLocator}) calls the same API
     * without an analyzer, and the authorization scheme must have one home.
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

    /**
     * GitHub answers a primary or a secondary limit with 403 or 429. A 403 is a limit only with
     * `x-ratelimit-remaining: 0` or `retry-after`: a blocked or SAML-protected repository also gets 403.
     * https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api
     */
    public function isRateLimited(HttpResult $result): bool
    {
        return $result->status() === 429
            || ($result->status() === 403 && ($result->header('x-ratelimit-remaining') === '0' || $result->header('retry-after') !== null));
    }

    public function activity(RepoRef $repo, array $json, \DateTimeImmutable $fetchedAt, ?\DateTimeImmutable $cachedAt): RepositoryActivity
    {
        $body = $json['repo'];

        return new RepositoryActivity($repo, ($body['archived'] ?? false) === true, JsonDate::parse($body['pushed_at'] ?? null), $fetchedAt, $cachedAt);
    }
}
