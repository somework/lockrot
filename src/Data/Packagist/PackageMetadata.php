<?php

declare(strict_types=1);

namespace Lockrot\Data\Packagist;

/**
 * Memory: the expanded p2 version list is folded into scalars in a single pass inside fromP2() and
 * then dropped. Nothing here retains a per-release object, so a 200-package lock costs kilobytes
 * rather than the tens of megabytes the full release history would.
 */
final class PackageMetadata
{
    private string $name;
    private bool $abandoned;
    private ?string $replacement;
    private bool $hasStableRelease;
    private ?\DateTimeImmutable $lastStableReleaseAt;
    private ?string $lastStableVersion;
    private int $releaseCount;
    private ?string $sourceUrl;
    private string $type;
    private \DateTimeImmutable $fetchedAt;

    public function __construct(
        string $name,
        bool $abandoned,
        ?string $replacement,
        bool $hasStableRelease,
        ?\DateTimeImmutable $lastStableReleaseAt,
        ?string $lastStableVersion,
        int $releaseCount,
        ?string $sourceUrl,
        string $type,
        \DateTimeImmutable $fetchedAt
    ) {
        $this->name = $name;
        $this->abandoned = $abandoned;
        $this->replacement = $replacement;
        $this->hasStableRelease = $hasStableRelease;
        $this->lastStableReleaseAt = $lastStableReleaseAt;
        $this->lastStableVersion = $lastStableVersion;
        $this->releaseCount = $releaseCount;
        $this->sourceUrl = $sourceUrl;
        $this->type = $type;
        $this->fetchedAt = $fetchedAt;
    }

    /**
     * @param list<array<string, mixed>> $stableVersions expanded p2 versions from {name}.json
     * @param list<array<string, mixed>> $devVersions expanded p2 versions from {name}~dev.json
     */
    public static function fromP2(string $name, array $stableVersions, array $devVersions, \DateTimeImmutable $fetchedAt): self
    {
        $first = $stableVersions[0] ?? $devVersions[0] ?? [];
        $abandonedRaw = $first['abandoned'] ?? false;
        $abandoned = $abandonedRaw === true || (\is_string($abandonedRaw) && $abandonedRaw !== '');
        $replacement = \is_string($abandonedRaw) && $abandonedRaw !== '' ? $abandonedRaw : null;
        $source = \is_array($first['source'] ?? null) ? ($first['source']['url'] ?? null) : null;
        $type = $first['type'] ?? 'library';

        $hasStableRelease = false;
        $lastStableReleaseAt = null;
        $lastStableVersion = null;
        $releaseCount = 0;
        foreach ([$stableVersions, $devVersions] as $versions) {
            foreach ($versions as $version) {
                ++$releaseCount;
                $release = Release::fromP2Version($version);
                if ($release->isDev()) {
                    continue;
                }
                $hasStableRelease = true;
                $time = $release->time();
                if ($time !== null && ($lastStableReleaseAt === null || $time > $lastStableReleaseAt)) {
                    $lastStableReleaseAt = $time;
                    $lastStableVersion = $release->version();
                }
            }
        }

        return new self(
            $name,
            $abandoned,
            $replacement,
            $hasStableRelease,
            $lastStableReleaseAt,
            $lastStableVersion,
            $releaseCount,
            \is_string($source) ? $source : null,
            \is_string($type) ? $type : 'library',
            $fetchedAt
        );
    }

    public function name(): string
    {
        return $this->name;
    }
    public function isAbandoned(): bool
    {
        return $this->abandoned;
    }
    public function replacement(): ?string
    {
        return $this->replacement;
    }
    public function releaseCount(): int
    {
        return $this->releaseCount;
    }
    public function sourceUrl(): ?string
    {
        return $this->sourceUrl;
    }
    public function type(): string
    {
        return $this->type;
    }
    public function fetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function hasStableRelease(): bool
    {
        return $this->hasStableRelease;
    }

    public function lastStableReleaseAt(): ?\DateTimeImmutable
    {
        return $this->lastStableReleaseAt;
    }

    public function lastStableVersion(): ?string
    {
        return $this->lastStableVersion;
    }
}
