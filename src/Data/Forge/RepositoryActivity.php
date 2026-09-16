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
    private bool $fromCache;

    /** @param bool $fromCache whether the answer came from lockrot's cache, so $fetchedAt may be up to a day before the run */
    public function __construct(RepoRef $ref, bool $archived, ?\DateTimeImmutable $pushedAt, \DateTimeImmutable $fetchedAt, bool $fromCache = false)
    {
        $this->ref = $ref;
        $this->archived = $archived;
        $this->pushedAt = $pushedAt;
        $this->fetchedAt = $fetchedAt;
        $this->fromCache = $fromCache;
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

    /** When the forge answered — in this run, or, when {@see fromCache()}, up to a day earlier. */
    public function fetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function fromCache(): bool
    {
        return $this->fromCache;
    }
}
