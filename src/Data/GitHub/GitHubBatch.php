<?php

declare(strict_types=1);

namespace Lockrot\Data\GitHub;

final class GitHubBatch
{
    /** @var array<string, RepositoryActivity> */
    private array $activity;
    /** @var list<string> */
    private array $notFound;
    /** @var array<string, string> */
    private array $failed;
    private bool $rateLimited;

    /**
     * @param array<string, RepositoryActivity> $activity
     * @param list<string>                       $notFound
     * @param array<string, string>              $failed
     */
    public function __construct(array $activity, array $notFound, array $failed, bool $rateLimited)
    {
        $this->activity = $activity;
        $this->notFound = $notFound;
        $this->failed = $failed;
        $this->rateLimited = $rateLimited;
    }

    /** @return array<string, RepositoryActivity> */
    public function activity(): array
    {
        return $this->activity;
    }

    /** @return list<string> */
    public function notFound(): array
    {
        return $this->notFound;
    }

    /** @return array<string, string> */
    public function failed(): array
    {
        return $this->failed;
    }

    public function rateLimited(): bool
    {
        return $this->rateLimited;
    }

    public static function empty(): self
    {
        return new self([], [], [], false);
    }
}
