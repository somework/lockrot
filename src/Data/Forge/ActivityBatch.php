<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

/** @internal */
final class ActivityBatch
{
    /** @var array<string, RepositoryActivity> */
    private array $activity;
    /** @var array<string, list<string>> */
    private array $notFound;
    /** @var array<string, array<string, string>> */
    private array $failed;
    /** @var array<string, bool> */
    private array $rateLimited;
    /** @var array<string, \DateTimeImmutable> */
    private array $notFoundCachedAt;

    /**
     * @param array<string, RepositoryActivity>    $activity    keyed by {@see RepoRef::key()}
     * @param array<string, list<string>>          $notFound    {@see RepoRef::forge()} => keys of repositories that it does not know or hides
     * @param array<string, array<string, string>> $failed      {@see RepoRef::forge()} => (key => reason)
     * @param array<string, bool>                  $rateLimited {@see RepoRef::host()} of each host that answered "too many requests"
     * @param array<string, \DateTimeImmutable>    $notFoundCachedAt key => the fetch time of a 404 from lockrot's cache
     */
    public function __construct(array $activity, array $notFound, array $failed, array $rateLimited, array $notFoundCachedAt = [])
    {
        $this->notFoundCachedAt = $notFoundCachedAt;
        $this->activity = $activity;
        $this->notFound = $notFound;
        $this->failed = $failed;
        $this->rateLimited = $rateLimited;
    }

    public static function empty(): self
    {
        return new self([], [], [], []);
    }

    /** @return array<string, RepositoryActivity> keyed by {@see RepoRef::key()} */
    public function activity(): array
    {
        return $this->activity;
    }

    /** @return list<string> keys, across forges */
    public function notFound(): array
    {
        return array_merge([], ...array_values($this->notFound));
    }

    /** @return list<string> keys, for one forge */
    public function notFoundOn(string $forge): array
    {
        return $this->notFound[$forge] ?? [];
    }

    /** @return array<string, string> key => reason, across forges */
    public function failed(): array
    {
        return array_merge([], ...array_values($this->failed));
    }

    /** @return array<string, string> key => reason, for one forge */
    public function failedOn(string $forge): array
    {
        return $this->failed[$forge] ?? [];
    }

    /** The fetch time of the 404 from lockrot's cache that decided $key, null for a live 404 or none. */
    public function notFoundCachedAt(string $key): ?\DateTimeImmutable
    {
        return $this->notFoundCachedAt[$key] ?? null;
    }

    public function rateLimited(string $host): bool
    {
        return $this->rateLimited[$host] ?? false;
    }
}
