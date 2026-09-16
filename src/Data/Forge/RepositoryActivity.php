<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

/** What a forge said about one repository: archived or not, and when it last saw a push or commit. */
final class RepositoryActivity
{
    private RepoRef $ref;
    private bool $archived;
    private ?\DateTimeImmutable $pushedAt;
    private \DateTimeImmutable $fetchedAt;

    public function __construct(RepoRef $ref, bool $archived, ?\DateTimeImmutable $pushedAt, \DateTimeImmutable $fetchedAt)
    {
        $this->ref = $ref;
        $this->archived = $archived;
        $this->pushedAt = $pushedAt;
        $this->fetchedAt = $fetchedAt;
    }

    public function ref(): RepoRef
    {
        return $this->ref;
    }

    /** The repository's path on its host, `owner/repo`. */
    public function repo(): string
    {
        return $this->ref->path();
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    /** The last push (GitHub) or the newest commit on any branch (GitLab, Bitbucket) — see {@see RepoRef::activityWording()}. */
    public function pushedAt(): ?\DateTimeImmutable
    {
        return $this->pushedAt;
    }

    public function fetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }
}
