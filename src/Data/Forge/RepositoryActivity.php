<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

/** @internal */
final class RepositoryActivity
{
    private RepoRef $ref;
    private bool $archived;
    private ?\DateTimeImmutable $pushedAt;
    private \DateTimeImmutable $fetchedAt;
    private ?\DateTimeImmutable $cachedAt;

    /**
     * @param \DateTimeImmutable  $fetchedAt when the deciding answer was fetched
     * @param ?\DateTimeImmutable $cachedAt  the oldest fetch time among the answers that came from
     *                                       lockrot's cache, null when none did
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

    public function repo(): string
    {
        return $this->ref->path();
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    /** The last push (GitHub) or the newest commit on any branch (GitLab, Bitbucket). */
    public function pushedAt(): ?\DateTimeImmutable
    {
        return $this->pushedAt;
    }

    public function fetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function fromCache(): bool
    {
        return $this->cachedAt !== null;
    }

    public function cachedAt(): ?\DateTimeImmutable
    {
        return $this->cachedAt;
    }
}
