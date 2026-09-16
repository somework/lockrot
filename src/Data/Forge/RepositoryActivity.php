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
    private ?\DateTimeImmutable $cachedAt;

    /**
     * @param \DateTimeImmutable  $fetchedAt when the deciding answer was fetched
     * @param ?\DateTimeImmutable $cachedAt  the fetch time of the oldest of this repository's answers that
     *                                       came from lockrot's cache — the deciding one or an enrichment
     *                                       one — or null when every answer was fetched in this run
     */
    public function __construct(RepoRef $ref, bool $archived, ?\DateTimeImmutable $pushedAt, \DateTimeImmutable $fetchedAt, ?\DateTimeImmutable $cachedAt = null)
    {
        $this->ref = $ref;
        $this->archived = $archived;
        $this->pushedAt = $pushedAt;
        $this->fetchedAt = $fetchedAt;
        $this->cachedAt = $cachedAt;
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

    /** When the deciding answer was fetched — in this run, or, when it came from the cache, earlier. */
    public function fetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    /** Whether any of this repository's answers came from lockrot's cache. */
    public function fromCache(): bool
    {
        return $this->cachedAt !== null;
    }

    /** The fetch time of the oldest cached answer behind this record, null when none was cached. */
    public function cachedAt(): ?\DateTimeImmutable
    {
        return $this->cachedAt;
    }
}
