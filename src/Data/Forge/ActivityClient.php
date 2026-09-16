<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

use Lockrot\Data\Http\HttpClientInterface;

/**
 * Fetches repository activity from every forge in one go: the repositories are grouped by host
 * (headers differ per forge, and lockrot's GitLab token goes to gitlab.com alone), each group is
 * one parallel `fetchAll`, and each forge's {@see ForgeApi} reads its own answers.
 *
 * The first request an api lists for a repository is the one that decides: a 404 there is "not
 * found", any other failure fails the repository. A later request is enrichment (GitLab's project
 * document, for the archived flag) and is read when it answered; when it did not, the repository
 * keeps what the first request said.
 */
final class ActivityClient
{
    /** Fixed cache TTL for repository activity, whichever forge; there is no user-facing knob for it. */
    public const CACHE_TTL = 86400;

    private HttpClientInterface $http;
    private ForgeAuth $auth;
    /** @var array<string, ForgeApi> */
    private array $apis;

    /** @param array<string, ForgeApi>|null $apis forge => api; the real three when null */
    public function __construct(HttpClientInterface $http, ForgeAuth $auth, ?array $apis = null)
    {
        $this->http = $http;
        $this->auth = $auth;
        $this->apis = $apis ?? [RepoRef::GITHUB => new GitHubApi(), RepoRef::GITLAB => new GitLabApi(), RepoRef::BITBUCKET => new BitbucketApi()];
    }

    public function auth(): ForgeAuth
    {
        return $this->auth;
    }

    /** @param list<RepoRef> $repos */
    public function fetch(array $repos): ActivityBatch
    {
        $activity = [];
        $notFound = [];
        $failed = [];
        $rateLimited = [];
        foreach (self::byHost($repos) as $group) {
            $api = $this->apis[$group[0]->forge()];
            $authenticated = $this->auth->isAuthenticated($group[0]);
            $requests = [];
            $urls = [];
            foreach ($group as $repo) {
                $requests[$repo->key()] = $api->requests($repo, $authenticated);
                foreach ($requests[$repo->key()] as $url) {
                    $urls[] = $url;
                }
            }
            $responses = $this->http->fetchAll($urls, $api->headers($this->auth->tokenFor($group[0])));
            foreach ($group as $repo) {
                $forge = $repo->forge();
                $json = [];
                $fetchedAt = null;
                $cachedAt = null;
                foreach ($requests[$repo->key()] as $role => $url) {
                    $result = $responses[$url];
                    $decoded = $result->isOk() ? $result->json() : null;
                    if ($decoded !== null && $result->fromCache() && ($cachedAt === null || $result->fetchedAt() < $cachedAt)) {
                        // Every answer read into $json counts for the age, the enrichment ones too.
                        $cachedAt = $result->fetchedAt();
                    }
                    if ($fetchedAt === null) {
                        // The deciding request.
                        if ($result->isNotFound()) {
                            $notFound[$forge][] = $repo->key();
                            continue 2;
                        }
                        if (!$result->isOk()) {
                            if ($api->isRateLimited($result)) {
                                $rateLimited[$forge] = true;
                            }
                            $failed[$forge][$repo->key()] = $result->error() ?? ('HTTP '.$result->status());
                            continue 2;
                        }
                        if ($decoded === null) {
                            $failed[$forge][$repo->key()] = 'invalid JSON from '.$result->url();
                            continue 2;
                        }
                        $fetchedAt = $result->fetchedAt();
                    }
                    if ($decoded !== null) {
                        $json[$role] = $decoded;
                    }
                }
                $activity[$repo->key()] = $api->activity($repo, $json, $fetchedAt ?? $this->neverFetched(), $cachedAt);
            }
        }

        return new ActivityBatch($activity, $notFound, $failed, $rateLimited);
    }

    /** Unreachable: every api lists at least one request, so a fetch time is always recorded. Typed for PHPStan. */
    private function neverFetched(): \DateTimeImmutable
    {
        throw new \LogicException('a forge api listed no request');
    }

    /**
     * @param list<RepoRef> $repos
     *
     * @return list<non-empty-list<RepoRef>> in first-seen host order
     */
    private static function byHost(array $repos): array
    {
        $groups = [];
        foreach ($repos as $repo) {
            $groups[$repo->host()][] = $repo;
        }

        return array_values($groups);
    }
}
