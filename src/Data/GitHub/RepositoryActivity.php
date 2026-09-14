<?php

declare(strict_types=1);

namespace Lockrot\Data\GitHub;

final class RepositoryActivity
{
    private string $repo;
    private bool $archived;
    private ?\DateTimeImmutable $pushedAt;
    private \DateTimeImmutable $fetchedAt;

    public function __construct(string $repo, bool $archived, ?\DateTimeImmutable $pushedAt, \DateTimeImmutable $fetchedAt)
    {
        $this->repo = $repo;
        $this->archived = $archived;
        $this->pushedAt = $pushedAt;
        $this->fetchedAt = $fetchedAt;
    }

    public function repo(): string
    {
        return $this->repo;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function pushedAt(): ?\DateTimeImmutable
    {
        return $this->pushedAt;
    }

    public function fetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }
}
