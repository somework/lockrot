<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

use Lockrot\Data\Http\HttpResult;

/**
 * One forge's REST API: which URLs answer for a repository, and how to read the answers.
 *
 * @internal
 */
interface ForgeApi
{
    /**
     * The requests that describe $repo, keyed by role, in the order they are made; the first is
     * the one whose fetch time the activity records. Every one has to succeed for the repository
     * to count as answered.
     *
     * @return array<string, string> role => URL
     */
    public function requests(RepoRef $repo, bool $authenticated): array;

    /**
     * The headers lockrot itself puts on a request, with its own token when it has one for the
     * host. Composer's HTTP layer adds Composer's own credentials separately.
     *
     * @return list<string>
     */
    public function headers(?string $token): array;

    /** Whether a failed answer is the forge saying "too many requests". */
    public function isRateLimited(HttpResult $result): bool;

    /**
     * @param array<string, array<string, mixed>> $json      decoded body per role: the deciding role always, a later one when it answered
     * @param \DateTimeImmutable                  $fetchedAt when the deciding answer was fetched
     * @param ?\DateTimeImmutable                 $cachedAt  the oldest fetch time among the answers that came from lockrot's cache, null when none did
     */
    public function activity(RepoRef $repo, array $json, \DateTimeImmutable $fetchedAt, ?\DateTimeImmutable $cachedAt): RepositoryActivity;
}
