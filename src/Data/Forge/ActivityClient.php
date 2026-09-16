<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Http\HttpResult;

/**
 * Fetches repository activity from every forge in one go: the repositories are grouped by host
 * (headers differ per forge, and lockrot's GitLab token goes to gitlab.com alone), each group is
 * one parallel `fetchAll`, and each forge's {@see ForgeApi} reads its own answers.
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
                $results = [];
                foreach ($requests[$repo->key()] as $role => $url) {
                    $results[$role] = $responses[$url];
                }
                $problem = self::problem($results);
                if ($problem !== null) {
                    if ($problem->isNotFound()) {
                        $notFound[] = $repo->key();
                        continue;
                    }
                    if ($api->isRateLimited($problem)) {
                        $rateLimited[$repo->forge()] = true;
                    }
                    $failed[$repo->forge()][$repo->key()] = $problem->error() ?? ('HTTP '.$problem->status());
                    continue;
                }
                $json = [];
                $fetchedAt = null;
                foreach ($results as $role => $result) {
                    $decoded = $result->json();
                    if ($decoded === null) {
                        $failed[$repo->forge()][$repo->key()] = 'invalid JSON from '.$result->url();
                        continue 2;
                    }
                    $json[$role] = $decoded;
                    $fetchedAt ??= $result->fetchedAt();
                }
                $activity[$repo->key()] = $api->activity($repo, $json, $fetchedAt ?? $this->neverFetched());
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
     * The first answer that is not a success — a 404 ahead of any other failure, so a repository
     * the forge does not know is "not found" whatever its second call said.
     *
     * @param array<string, HttpResult> $results
     */
    private static function problem(array $results): ?HttpResult
    {
        $problem = null;
        foreach ($results as $result) {
            if ($result->isNotFound()) {
                return $result;
            }
            if (!$result->isOk() && $problem === null) {
                $problem = $result;
            }
        }

        return $problem;
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
