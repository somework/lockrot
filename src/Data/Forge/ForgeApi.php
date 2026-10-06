<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

use Lockrot\Data\Http\HttpResult;

/** @internal */
interface ForgeApi
{
    /**
     * Keyed by role, in request order. The first request decides, a later one only enriches it.
     *
     * @return array<string, string> role => URL
     */
    public function requests(RepoRef $repo, bool $authenticated): array;

    /**
     * Only lockrot's own headers: Composer's HTTP layer adds Composer's credentials.
     *
     * @return list<string>
     */
    public function headers(?string $token): array;

    public function isRateLimited(HttpResult $result): bool;

    /**
     * @param array<string, array<string, mixed>> $json      decoded body per role: the deciding role always, a later one when it answered
     * @param \DateTimeImmutable                  $fetchedAt when the deciding answer was fetched
     * @param ?\DateTimeImmutable                 $cachedAt  the oldest fetch time among the answers that came from lockrot's cache, null when none did
     */
    public function activity(RepoRef $repo, array $json, \DateTimeImmutable $fetchedAt, ?\DateTimeImmutable $cachedAt): RepositoryActivity;
}
