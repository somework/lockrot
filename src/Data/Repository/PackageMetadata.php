<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Lockrot\Data\Forge\SupportSource;

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
    /**
     * Per release branch ({@see ReleaseBranch}), by branch key — an integer key where PHP makes one
     * of `"1"`: the newest dated stable release (`version`, `at`) and the branch's highest stable
     * tag (`highest`: as Composer normalizes it, as the repository prints it, and when it was
     * released, null when the repository does not say). What {@see \Lockrot\Signal\Rule\LeftBehindRule}
     * compares the installed branch against.
     *
     * @var array<array-key, array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable}}>
     */
    private array $latestStableByBranch;

    /** @param array<array-key, array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable}}> $latestStableByBranch */
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
        \DateTimeImmutable $dataDate,
        array $latestStableByBranch = []
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
        $this->latestStableByBranch = $latestStableByBranch;
    }

    /**
     * Derived directly from Composer's own package objects for one package name. $versions is
     * already unwrapped (no AliasPackage) — that is the caller's job, since only the caller knows
     * how to group loadPackages()'s flat package list by name.
     *
     * The repository URL is the highest stable release's, never an older release's. A package's
     * home is where its *current* code lives: phpstan/phpstan publishes its recent releases with
     * no `source` at all (`support.source` names phpstan/phpstan-src) and three old releases
     * pointing at a one-off, since archived, build repository — reading "the first release with a
     * source" made the package `abandoned`. The release is picked by version, not by date or by
     * the order the repository lists it in: `time` is optional and a hand-written packages.json or
     * a Satis build can leave it out or mix it up. When that release names no repository the lock
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
        $highestStable = null;
        $type = null;
        $byBranch = [];

        foreach ($versions as $version) {
            if (!$abandoned && $version instanceof CompletePackage && $version->isAbandoned()) {
                $abandoned = true;
                $replacement = $version->getReplacementPackage();
            }
            if (!$version->isDev()) {
                $hasStableRelease = true;
                if ($highestStable === null || Comparator::greaterThan($version->getVersion(), $highestStable->getVersion())) {
                    $highestStable = $version;
                }
                $releaseDate = $version->getReleaseDate();
                if ($releaseDate !== null) {
                    $releaseDate = self::toImmutable($releaseDate);
                    if ($lastStableReleaseAt === null || $releaseDate > $lastStableReleaseAt) {
                        $lastStableReleaseAt = $releaseDate;
                        $lastStableVersion = $version->getPrettyVersion();
                    }
                }
                // The branch view keeps the newest *dated stable* release per branch: a backport on a
                // lower minor released later is the branch's last word, and an alpha on a new major
                // is not a branch the upstream moved on to. (S2 above counts every tag, pre-releases
                // included: there a tag can only make the package look younger, here it would make
                // findings.) The branch's highest tag travels next to it, with its date: it is the
                // "installed version above everything listed" check, the release a fix on the branch
                // is looked for in, and — when it carries no date — the sign that the branch's age is
                // not known (Packagist dates a tag by its commit, and a subtree split repository has
                // tags without one). A branch with no dated release shows that tag as its newest.
                // Only normalized strings are compared — a repository that sends both `version` and
                // `version_normalized` is trusted on the latter and may put anything in the former,
                // which the loader never parses; the pretty form is only ever printed.
                $normalized = $version->getVersion();
                $branch = ReleaseBranch::of($normalized);
                if ($branch !== null && VersionParser::parseStability($normalized) === 'stable') {
                    $pretty = $version->getPrettyVersion();
                    $tag = ['normalized' => $normalized, 'pretty' => $pretty, 'at' => $releaseDate];
                    $entry = $byBranch[$branch] ?? null;
                    if ($entry === null) {
                        $byBranch[$branch] = ['version' => $pretty, 'at' => $releaseDate, 'highest' => $tag];
                    } else {
                        if (Comparator::greaterThan($normalized, $entry['highest']['normalized'])) {
                            $entry['highest'] = $tag;
                        }
                        if ($releaseDate !== null && ($entry['at'] === null || $releaseDate > $entry['at'])) {
                            $entry['version'] = $pretty;
                            $entry['at'] = $releaseDate;
                        } elseif ($entry['at'] === null && $entry['highest']['normalized'] === $normalized) {
                            $entry['version'] = $pretty;
                        }
                        $byBranch[$branch] = $entry;
                    }
                }
            }
            if ($type === null) {
                $type = $version->getType();
            }
        }
        // The package's age is its highest tag's age. When that tag carries no date, the newest
        // dated one below it is not "the last release" — it is the last release the repository
        // dated, and how much younger the undated tags above it are cannot be known; S2 then has
        // nothing to measure and stays quiet.
        if ($highestStable !== null && $highestStable->getReleaseDate() === null) {
            $lastStableReleaseAt = null;
            $lastStableVersion = null;
        }
        // A package with dev branches only has no version order worth the name; its first branch
        // (Packagist lists the default branch first) is read.
        $anchor = $highestStable ?? ($versions[0] ?? null);

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
            $dataDate,
            $byBranch
        );
    }

    /** The release's `source` URL, else its `support.source` reduced to the repository, else null. */
    private static function repositoryOf(BasePackage $version): ?string
    {
        $url = $version->getSourceUrl();
        if ($url !== null && $url !== '') {
            return $url;
        }

        return $version instanceof CompletePackage ? SupportSource::url($version->getSupport()) : null;
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
     * Where the package's current code lives: the highest stable release's `source` URL, else its
     * `support.source`. Null when that release names neither.
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

    /** The highest non-dev tag's release date; null when the repository dates none, or not that one. */
    public function lastStableReleaseAt(): ?\DateTimeImmutable
    {
        return $this->lastStableReleaseAt;
    }

    public function lastStableVersion(): ?string
    {
        return $this->lastStableVersion;
    }

    /**
     * The newest dated stable release on every release branch (`version`, `at`) and the branch's
     * highest stable tag (`highest`: `normalized`, `pretty`, `at`), keyed by {@see ReleaseBranch} key
     * (an integer where PHP makes one of `"1"`); pre-releases do not count. A branch whose releases
     * carry no `time` at all shows its highest tag as its newest, with a null date.
     *
     * @return array<array-key, array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable}}>
     */
    public function latestStableByBranch(): array
    {
        return $this->latestStableByBranch;
    }
}
