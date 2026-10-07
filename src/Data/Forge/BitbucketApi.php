<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

use Lockrot\Data\Http\HttpResult;

/**
 * Reads the newest commit across every branch: docs/internals.md, "What is sent". Bitbucket Cloud
 * has no archived state, so `archived` is always false. The repository document is not fetched:
 * `updated_on` moves on settings changes too, and a second request spends Bitbucket's anonymous
 * rate limit.
 *
 * lockrot sends no credentials of its own to Bitbucket: Composer's HTTP layer adds Composer's
 * ({@see ForgeAuth}).
 *
 * @internal
 */
final class BitbucketApi implements ForgeApi
{
    public const DEFAULT_API_BASE = 'https://api.bitbucket.org/2.0/repositories/';

    private string $apiBase;

    public function __construct(string $apiBase = self::DEFAULT_API_BASE)
    {
        $this->apiBase = $apiBase;
    }

    public function requests(RepoRef $repo, bool $authenticated): array
    {
        return ['commits' => $this->apiBase.$repo->path().'/commits?pagelen=1'];
    }

    public function headers(?string $token): array
    {
        return ['Accept: application/json', 'User-Agent: lockrot'];
    }

    public function isRateLimited(HttpResult $result): bool
    {
        return $result->status() === 429;
    }

    public function activity(RepoRef $repo, array $json, \DateTimeImmutable $fetchedAt, ?\DateTimeImmutable $cachedAt): RepositoryActivity
    {
        $values = $json['commits']['values'] ?? null;
        $newest = \is_array($values) ? ($values[0] ?? null) : null;

        return new RepositoryActivity($repo, false, \is_array($newest) ? JsonDate::parse($newest['date'] ?? null) : null, $fetchedAt, $cachedAt);
    }
}
