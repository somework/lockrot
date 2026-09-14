<?php

declare(strict_types=1);

namespace Lockrot\Data\Packagist;

use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;

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
    private \DateTimeImmutable $dataDate;

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
        \DateTimeImmutable $dataDate
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
        $this->dataDate = $dataDate;
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

    /**
     * Same semantics as fromP2(), derived directly from Composer's own package objects instead of
     * raw p2 JSON. $versions is already unwrapped (no AliasPackage) — that is the caller's job,
     * since only the caller knows how to group loadPackages()'s flat package list by name.
     *
     * @param list<BasePackage> $versions
     */
    public static function fromPackages(string $name, array $versions, \DateTimeImmutable $dataDate): self
    {
        $abandoned = false;
        $replacement = null;
        $hasStableRelease = false;
        $lastStableReleaseAt = null;
        $lastStableVersion = null;
        $sourceUrl = null;
        $type = null;

        foreach ($versions as $version) {
            if (!$abandoned && $version instanceof CompletePackage && $version->isAbandoned()) {
                $abandoned = true;
                $replacement = $version->getReplacementPackage();
            }
            if (!$version->isDev()) {
                $hasStableRelease = true;
                $releaseDate = $version->getReleaseDate();
                if ($releaseDate !== null) {
                    $releaseDate = self::toImmutable($releaseDate);
                    if ($lastStableReleaseAt === null || $releaseDate > $lastStableReleaseAt) {
                        $lastStableReleaseAt = $releaseDate;
                        $lastStableVersion = $version->getPrettyVersion();
                    }
                }
            }
            if ($sourceUrl === null && $version->getSourceUrl() !== null) {
                $sourceUrl = $version->getSourceUrl();
            }
            if ($type === null) {
                $type = $version->getType();
            }
        }

        return new self(
            $name,
            $abandoned,
            $replacement,
            $hasStableRelease,
            $lastStableReleaseAt,
            $lastStableVersion,
            \count($versions),
            $sourceUrl,
            $type ?? 'library',
            $dataDate
        );
    }

    private static function toImmutable(\DateTimeInterface $date): \DateTimeImmutable
    {
        if ($date instanceof \DateTimeImmutable) {
            return $date;
        }

        return \DateTimeImmutable::createFromMutable($date);
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
    public function dataDate(): \DateTimeImmutable
    {
        return $this->dataDate;
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
