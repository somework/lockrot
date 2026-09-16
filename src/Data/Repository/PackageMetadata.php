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
    private ?string $repositoryUrl;
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
        ?string $repositoryUrl,
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
        $this->repositoryUrl = $repositoryUrl;
        $this->type = $type;
        $this->dataDate = $dataDate;
    }

    /**
     * Derived directly from Composer's own package objects for one package name. $versions is
     * already unwrapped (no AliasPackage) — that is the caller's job, since only the caller knows
     * how to group loadPackages()'s flat package list by name.
     *
     * The repository URL is the newest stable release's, never an older release's. A package's
     * home is where its *current* code lives: phpstan/phpstan publishes its recent releases with
     * no `source` at all (`support.source` names phpstan/phpstan-src) and three old releases
     * pointing at a one-off, since archived, build repository — reading "the first release with a
     * source" made the package `abandoned`. When the newest release names no repository the lock
     * entry is the next place to look ({@see \Lockrot\Lock\LockedPackage::repositoryUrl()}),
     * and after that the activity check is skipped, because a false `abandoned` costs more than
     * a missed one.
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
        $newestStable = null;
        $firstStable = null;
        $type = null;

        foreach ($versions as $version) {
            if (!$abandoned && $version instanceof CompletePackage && $version->isAbandoned()) {
                $abandoned = true;
                $replacement = $version->getReplacementPackage();
            }
            if (!$version->isDev()) {
                $hasStableRelease = true;
                $firstStable ??= $version;
                $releaseDate = $version->getReleaseDate();
                if ($releaseDate !== null) {
                    $releaseDate = self::toImmutable($releaseDate);
                    if ($lastStableReleaseAt === null || $releaseDate > $lastStableReleaseAt) {
                        $lastStableReleaseAt = $releaseDate;
                        $lastStableVersion = $version->getPrettyVersion();
                        $newestStable = $version;
                    }
                }
            }
            if ($type === null) {
                $type = $version->getType();
            }
        }
        // Without release dates the repository's own order is the only "newest" there is; Packagist
        // lists versions newest first. A package with dev branches only is read the same way.
        $anchor = $newestStable ?? $firstStable ?? ($versions[0] ?? null);

        return new self(
            $name,
            $abandoned,
            $replacement,
            $hasStableRelease,
            $lastStableReleaseAt,
            $lastStableVersion,
            \count($versions),
            $anchor !== null ? self::repositoryOf($anchor) : null,
            $type ?? 'library',
            $dataDate
        );
    }

    /** The release's `source` URL, else its `support.source`, else null. */
    private static function repositoryOf(BasePackage $version): ?string
    {
        $url = $version->getSourceUrl();
        if ($url !== null && $url !== '') {
            return $url;
        }
        $support = $version instanceof CompletePackage ? $version->getSupport() : [];
        $url = $support['source'] ?? null;

        return \is_string($url) && $url !== '' ? $url : null;
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
    /**
     * Where the package's current code lives: the newest release's `source` URL, else its
     * `support.source`. Null when the newest release names neither.
     */
    public function repositoryUrl(): ?string
    {
        return $this->repositoryUrl;
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
