<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

/**
 * The tag a `tag`, `test` or pinned `blocked` move names (`next_step.latest`).
 *
 * @internal
 */
final class Latest implements \JsonSerializable
{
    private string $version;
    private ?string $released;
    private bool $isInstalled;
    private ?string $relation;
    private ?string $snapshotTime;

    /**
     * @param ?string $released     ISO 8601, null for an undated tag
     * @param ?string $relation     `older`, `newer` or `same` against the snapshot, null on a move not from `pinned`
     * @param ?string $snapshotTime ISO 8601, null on a move not from `pinned`
     */
    public function __construct(string $version, ?string $released, bool $isInstalled, ?string $relation, ?string $snapshotTime)
    {
        $this->version = $version;
        $this->released = $released;
        $this->isInstalled = $isInstalled;
        $this->relation = $relation;
        $this->snapshotTime = $snapshotTime;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function released(): ?string
    {
        return $this->released;
    }

    public function isInstalled(): bool
    {
        return $this->isInstalled;
    }

    public function relation(): ?string
    {
        return $this->relation;
    }

    public function snapshotTime(): ?string
    {
        return $this->snapshotTime;
    }

    /** @return array{version: string, released: ?string, is_installed: bool, relation: ?string, snapshot_time: ?string} */
    public function jsonSerialize(): array
    {
        return ['version' => $this->version, 'released' => $this->released, 'is_installed' => $this->isInstalled, 'relation' => $this->relation, 'snapshot_time' => $this->snapshotTime];
    }
}
