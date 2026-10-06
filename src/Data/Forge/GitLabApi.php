<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

use Lockrot\Data\Http\HttpResult;

/**
 * The newest commit across every branch is the nearest thing to GitHub's `pushed_at`. The
 * project's `last_activity_at` is not used: it moves on stars, forks and issue traffic.
 *
 * Only an authenticated request to the project document carries `archived`: an anonymous request
 * gets GitLab's basic projection, which omits it. See docs/internals.md, "What is sent".
 *
 * @internal
 */
final class GitLabApi implements ForgeApi
{
    public function requests(RepoRef $repo, bool $authenticated): array
    {
        $project = 'https://'.$repo->host().'/api/v4/projects/'.rawurlencode($repo->path());
        $requests = ['commits' => $project.'/repository/commits?all=true&per_page=1'];
        if ($authenticated) {
            // Enrichment: read when it answers, and the commit date stands on its own when it does not.
            $requests['project'] = $project;
        }

        return $requests;
    }

    /** A personal access token goes in `PRIVATE-TOKEN`, the header Composer uses for its `gitlab-token` too. */
    public function headers(?string $token): array
    {
        $headers = ['Accept: application/json', 'User-Agent: lockrot'];
        if ($token !== null && $token !== '') {
            $headers[] = 'PRIVATE-TOKEN: '.$token;
        }

        return $headers;
    }

    public function isRateLimited(HttpResult $result): bool
    {
        return $result->status() === 429;
    }

    public function activity(RepoRef $repo, array $json, \DateTimeImmutable $fetchedAt, ?\DateTimeImmutable $cachedAt): RepositoryActivity
    {
        $newest = $json['commits'][0] ?? null;
        $committedAt = \is_array($newest) ? JsonDate::parse($newest['committed_date'] ?? null) : null;
        $archived = (($json['project'] ?? [])['archived'] ?? false) === true;

        return new RepositoryActivity($repo, $archived, $committedAt, $fetchedAt, $cachedAt);
    }
}
