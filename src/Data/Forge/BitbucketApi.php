<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

use Lockrot\Data\Http\HttpResult;

/**
 * `GET /2.0/repositories/{workspace}/{slug}/commits?pagelen=1`: the newest commit across every
 * branch. Bitbucket Cloud has no archived state, so S3 never fires here. The repository document
 * would add nothing (`updated_on` moves on settings changes as well as pushes) and the anonymous
 * quota is 60 requests an hour, so it is not fetched.
 *
 * lockrot sends no credentials of its own to Bitbucket; Composer's (`http-basic` with an API
 * token, or a `bitbucket-oauth` consumer once exchanged, see {@see ForgeAuth}) are added by
 * Composer's HTTP layer.
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

    public function activity(RepoRef $repo, array $json, \DateTimeImmutable $fetchedAt, bool $fromCache): RepositoryActivity
    {
        $values = $json['commits']['values'] ?? null;
        $newest = \is_array($values) ? ($values[0] ?? null) : null;

        return new RepositoryActivity($repo, false, \is_array($newest) ? JsonDate::parse($newest['date'] ?? null) : null, $fetchedAt, $fromCache);
    }
}
