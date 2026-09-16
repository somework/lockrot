<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

/** The outcome of one {@see ActivityClient::fetch()}: activity per repository, and what went wrong, per forge. */
final class ActivityBatch
{
    /** @var array<string, RepositoryActivity> */
    private array $activity;
    /** @var array<string, list<string>> */
    private array $notFound;
    /** @var array<string, array<string, string>> */
    private array $failed;
    /** @var array<string, true> */
    private array $rateLimited;

    /**
     * @param array<string, RepositoryActivity>    $activity    keyed by {@see RepoRef::key()}
     * @param array<string, list<string>>          $notFound    forge => keys of repositories the forge does not know (or hides)
     * @param array<string, array<string, string>> $failed      forge => (key => reason)
     * @param array<string, true>                  $rateLimited forges that answered "too many requests"
     */
    public function __construct(array $activity, array $notFound, array $failed, array $rateLimited)
    {
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

    public function rateLimited(string $forge): bool
    {
        return isset($this->rateLimited[$forge]);
    }
}
