<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;

/**
 * Memory: fromPackages() folds the given package objects into scalars in a single pass and
 * retains nothing beyond them. Nothing here retains a per-release object, so a 200-package lock
 * costs kilobytes rather than the tens of megabytes the full release history would.
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
     * Derived directly from Composer's own package objects for one package name. $versions is
     * already unwrapped (no AliasPackage) — that is the caller's job, since only the caller knows
     * how to group loadPackages()'s flat package list by name.
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
    /**
     * Versions seen for this package. Dev branches are only counted for a package with no tagged
     * release, since the `~dev` file is not fetched otherwise.
     */
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
