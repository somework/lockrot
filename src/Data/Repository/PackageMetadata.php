<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Lockrot\Data\Forge\SupportSource;
use Lockrot\Lock\PackageOrigin;

/**
 * Keeps scalars only, so that a large lock costs little memory: fromPackages() retains no
 * Composer object. The exceptions are a date per stable tag of a monorepo parent
 * ({@see $releaseDates}), the stable tags that share a commit ({@see $sharedCommitVersions}) and
 * a {@see StableRelease} per release above the installed one ({@see $releasesAbove}).
 *
 * @internal
 */
final class PackageMetadata
{
    /**
     * The number of stable tags on one source commit from which their date counts as the commit's,
     * not a release's. See docs/verdicts.md#dates-from-the-monorepo. Two tags on one commit (a
     * re-tag, two releases with no change between them) stay trusted: the date is at most one
     * release interval off, and the branch stays measurable.
     */
    public const SHARED_COMMIT_TAGS = 3;

    /**
     * The `replace` constraint of a subtree split: the component's `vX.Y.Z` is the monorepo's own.
     * Composer resolves it to an exact version, so only the written form tells it from a range.
     */
    private const SELF_VERSION = 'self.version';

    private ?string $composerRepositoryUrl = null;

    private string $name;
    private bool $abandoned;
    private ?string $replacement;
    private ?string $abandonedBy;
    private bool $hasStableRelease;

    /** Any tag that is not a dev branch, a pre-release included: S2 and S6 read it (ruling W17-X1). */
    private bool $hasTag;
    private ?\DateTimeImmutable $lastStableReleaseAt;
    private ?string $lastStableVersion;
    private int $releaseCount;
    private ?string $repositoryUrl;
    private string $type;
    private \DateTimeImmutable $dataDate;
    /**
     * By {@see ReleaseBranch} key, an integer where PHP makes one of `"1"`: the newest dated stable
     * release (`version`, `at`) and the branch's highest stable tag (`highest`). A branch that a
     * monorepo parent dated ({@see datedBy()}) names the parent under `dated_by`.
     *
     * @var array<array-key, array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable, php: ?string}, dated_by?: string, php: ?string}>
     */
    private array $latestStableByBranch;
    /**
     * Every package name that a version of this package `replace`s at `self.version`: the split
     * packages of a monorepo. The union over the versions seen, so a component that left the
     * monorepo stays in the set.
     *
     * @var list<string>
     */
    private array $replaces;
    private ?string $lastStableDatedBy;
    /**
     * The release date of every stable tag that the repository dates by a release, by normalized
     * version. A monorepo parent keeps its own, and each split package that it dates
     * ({@see datedBy()}) takes them. The lock copies a shared commit's date as the installed
     * version's `time`, and this map holds the real release date. Empty for an ordinary package.
     *
     * @var array<string, \DateTimeImmutable>
     */
    private array $releaseDates;
    private ?string $releaseDatesBy;
    /**
     * Every stable tag, by normalized version, on a commit that SHARED_COMMIT_TAGS or more stable
     * tags share. The lock copies that commit's date as the installed version's `time`, so such a
     * version needs its parent's date ({@see needsParentDates()}). Empty for an ordinary package.
     *
     * @var array<string, true>
     */
    private array $sharedCommitVersions;
    /**
     * A commit that SHARED_COMMIT_TAGS or more stable tags share dates the highest non-dev
     * version, or the newest dated version under a dated highest one. Then S10 names the shared
     * commit, not an undated tag, as the cause of a null {@see $lastStableReleaseAt}.
     */
    private bool $lastStableSharesItsCommit;
    /**
     * The stable releases above the installed version, ascending: every stable release for a
     * branch snapshot. Null when the load named no installed version (a monorepo parent) or it
     * does not parse.
     *
     * @var list<StableRelease>|null
     */
    private ?array $releasesAbove = null;
    /** @var list<string>|null the installed release's `require` names, null when that release is not among those read */
    private ?array $installedRequires = null;
    /** @var list<string>|null */
    private ?array $newestRequires = null;
    /** The highest stable release not dated after the run clock: the one {@see $newestRequires} reads. */
    private ?StableRelease $newestStable = null;
    private ?\DateTimeImmutable $releaseReadingAt;
    private ?string $releaseReadingVersion;
    private ?string $releaseReadingDatedBy;
    /** @var array{0: \DateTimeImmutable, 1: string, 2: string, 3: bool}|null the newest dated pre-release: its date, its pretty and its normalized version, and whether its commit is a shared one */
    private ?array $newestPreRelease = null;

    /** {@see lastStableVersion()} with pre-releases counted: S2 names it when its age stays unknown, and a pinned move with no branch row targets it. */
    private ?string $newestVersion = null;

    private ?\DateTimeImmutable $newestAt = null;

    private ?string $newestPhp = null;
    private bool $highestBranchDatedByParent = false;

    /**
     * @param array<array-key, array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable, php: ?string}, dated_by?: string, php: ?string}> $latestStableByBranch
     * @param list<string>                                                                                                                                                   $replaces
     * @param array<string, \DateTimeImmutable>                                                                                                                              $releaseDates
     * @param array<string, true>                                                                                                                                            $sharedCommitVersions
     */
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
        array $latestStableByBranch = [],
        array $replaces = [],
        ?string $lastStableDatedBy = null,
        array $releaseDates = [],
        ?string $releaseDatesBy = null,
        array $sharedCommitVersions = [],
        ?string $abandonedBy = null,
        bool $lastStableSharesItsCommit = false
    ) {
        $this->name = $name;
        $this->abandoned = $abandoned;
        $this->replacement = $replacement;
        $this->hasStableRelease = $hasStableRelease;
        $this->hasTag = $hasStableRelease;
        $this->lastStableReleaseAt = $lastStableReleaseAt;
        $this->lastStableVersion = $lastStableVersion;
        $this->releaseCount = $releaseCount;
        $this->repositoryUrl = $repositoryUrl;
        $this->type = $type;
        $this->dataDate = $dataDate;
        $this->latestStableByBranch = $latestStableByBranch;
        $this->replaces = $replaces;
        $this->lastStableDatedBy = $lastStableDatedBy;
        $this->releaseDates = $releaseDates;
        $this->releaseDatesBy = $releaseDatesBy;
        $this->sharedCommitVersions = $sharedCommitVersions;
        $this->abandonedBy = $abandonedBy;
        $this->lastStableSharesItsCommit = $lastStableSharesItsCommit;
        $this->releaseReadingAt = $lastStableReleaseAt;
        $this->releaseReadingVersion = $lastStableVersion;
        $this->releaseReadingDatedBy = $lastStableDatedBy;
    }

    /**
     * The repository URL is the highest stable release's, not an older release's. An older release
     * can name a one-off repository, and the activity check then flags the package `abandoned`.
     * The release is picked by version, not by date or listing order. `time` is optional, and a
     * hand-written packages.json or a Satis build can omit it or reorder releases. When that
     * release names no repository, {@see repositoryUrl()} is null, not an older release's URL, and
     * {@see \Lockrot\Lock\LockedPackage::repositoryUrl()} is the fallback.
     *
     * @param list<BasePackage> $versions         the releases of one package, with no AliasPackage
     * @param \DateTimeImmutable $dataDate         the run clock: a release dated after it is not the newest stable one
     * @param ?string           $installedVersion the version the lock installs, null to keep dates only (a monorepo parent)
     */
    public static function fromPackages(string $name, array $versions, \DateTimeImmutable $dataDate, ?string $installedVersion = null): self
    {
        $abandoned = false;
        $replacement = null;
        $abandonedBy = null;
        $hasStableRelease = false;
        $hasTag = false;
        $lastStableReleaseAt = null;
        $lastStableVersion = null;
        $lastStableNormalized = null;
        $lastStableCommit = null;
        $highestStable = null;
        $type = null;
        $byBranch = [];
        $tagsOnCommit = [];
        $highestCommitByBranch = [];
        /** @var array<array-key, string> $newestDatedByBranch the normalized version of each branch's dated `version` */
        $newestDatedByBranch = [];
        $highestCommit = null;
        $replaces = [];
        // Kept past this method only for a monorepo parent, and only for tags whose commit is a
        // release's ({@see $releaseDates}).
        $releaseDates = [];
        $commitByVersion = [];
        // Dated or not, unlike $commitByVersion: the shared-commit tags are read from it.
        $commitOfTag = [];
        $newestPreRelease = null;
        /** @var array<array-key, array{0: BasePackage, 1: ?\DateTimeImmutable, 2: ?string}> $stable by normalized version: the release, its date, its php. PHP makes an all-digit key, such as the date version 20200101, an integer: cast each key that a loop reads. */
        $stable = [];

        foreach ($versions as $version) {
            foreach ($version->getReplaces() as $link) {
                // Only `self.version`: it says that the two tags are one release, so the parent's
                // dates are this package's. A replace with a range says "do not install both" and
                // nothing about release dates.
                if (self::SELF_VERSION === $link->getPrettyConstraint()) {
                    $replaces[$link->getTarget()] = true;
                }
            }
            if (!$abandoned && $version instanceof CompletePackage && $version->isAbandoned()) {
                $abandoned = true;
                $replacement = $version->getReplacementPackage();
                $abandonedBy = PackageOrigin::registryOf($version->getNotificationUrl());
            }
            if (!$version->isDev()) {
                $hasTag = true;
                $hasStableRelease = $hasStableRelease || VersionParser::parseStability($version->getVersion()) === 'stable';
                if ($highestStable === null || Comparator::greaterThan($version->getVersion(), $highestStable->getVersion())) {
                    $highestStable = $version;
                    $highestCommit = self::commitOf($version);
                }
                $releaseDate = $version->getReleaseDate();
                if ($releaseDate !== null) {
                    $releaseDate = self::toImmutable($releaseDate);
                    $preRelease = VersionParser::parseStability($version->getVersion()) !== 'stable';
                    if ($preRelease && self::newer($releaseDate, $version->getVersion(), $newestPreRelease[0] ?? null, $newestPreRelease[2] ?? null)) {
                        $newestPreRelease = [$releaseDate, $version->getPrettyVersion(), $version->getVersion(), self::commitOf($version)];
                    }
                    if (self::newer($releaseDate, $version->getVersion(), $lastStableReleaseAt, $lastStableNormalized)) {
                        $lastStableReleaseAt = $releaseDate;
                        $lastStableVersion = $version->getPrettyVersion();
                        $lastStableNormalized = $version->getVersion();
                        $lastStableCommit = self::commitOf($version);
                    }
                }
                // The branch view keeps the newest dated stable release per branch. A backport on a
                // lower minor is the branch's last word. An alpha on a new major is not a branch
                // that upstream moved to. S2 counts pre-releases too: there a tag can only make the
                // package look younger, here it makes findings. The branch's highest tag travels
                // with it, with its date: with no date, the branch's age is unknown. Compare
                // normalized strings only: the loader never parses `version`.
                $normalized = $version->getVersion();
                $branch = ReleaseBranch::of($normalized);
                // A stable tag with no release branch, such as the date version 20200101, is a stable release.
                if ($branch === null && VersionParser::parseStability($normalized) === 'stable') {
                    $stable[$normalized] ??= [$version, $releaseDate, self::phpOf($version)];
                }
                if ($branch !== null && VersionParser::parseStability($normalized) === 'stable') {
                    $commit = self::commitOf($version);
                    if ($commit !== null) {
                        $tagsOnCommit[$commit] = ($tagsOnCommit[$commit] ?? 0) + 1;
                        $commitOfTag[$normalized] = $commit;
                    }
                    if ($releaseDate !== null) {
                        $releaseDates[$normalized] = $releaseDate;
                        $commitByVersion[$normalized] = $commit;
                    }
                    $pretty = $version->getPrettyVersion();
                    // The php requirement stays with the release that the branch names: a project that
                    // moves onto the branch must satisfy it ({@see \Lockrot\Signal\PhpFloor}).
                    $php = self::phpOf($version);
                    $tag = ['normalized' => $normalized, 'pretty' => $pretty, 'at' => $releaseDate, 'php' => $php];
                    $stable[$normalized] ??= [$version, $releaseDate, $php];
                    $entry = $byBranch[$branch] ?? null;
                    if ($entry === null) {
                        $byBranch[$branch] = ['version' => $pretty, 'at' => $releaseDate, 'highest' => $tag, 'php' => $php];
                        $highestCommitByBranch[$branch] = $commit;
                        $newestDatedByBranch[$branch] = $normalized;
                    } else {
                        if (Comparator::greaterThan($normalized, $entry['highest']['normalized'])) {
                            $entry['highest'] = $tag;
                            $highestCommitByBranch[$branch] = $commit;
                        }
                        if ($releaseDate !== null && self::newer($releaseDate, $normalized, $entry['at'], $newestDatedByBranch[$branch] ?? null)) {
                            $entry['version'] = $pretty;
                            $entry['at'] = $releaseDate;
                            $entry['php'] = $php;
                            $newestDatedByBranch[$branch] = $normalized;
                        } elseif ($entry['at'] === null && $entry['highest']['normalized'] === $normalized) {
                            $entry['version'] = $pretty;
                            $entry['php'] = $php;
                        }
                        $byBranch[$branch] = $entry;
                    }
                }
            }
            if ($type === null) {
                $type = $version->getType();
            }
        }
        // A subtree split cuts a tag on every release of the monorepo, so tags pile up on one
        // commit, and Packagist dates each by that commit. A tag on a commit that SHARED_COMMIT_TAGS
        // or more stable tags share is dated by no release: it counts as undated, and the branch's
        // age is unknown. Dev branches and pre-releases do not count: `dev-main` sits on the newest
        // tag's commit, and a final cut on its candidate's commit is dated days late.
        // See docs/verdicts.md#dates-from-the-monorepo.
        foreach ($byBranch as $branch => $entry) {
            $commit = $highestCommitByBranch[$branch] ?? null;
            if ($commit !== null && $tagsOnCommit[$commit] >= self::SHARED_COMMIT_TAGS) {
                $byBranch[$branch] = ['version' => $entry['version'], 'at' => $entry['at'], 'highest' => array_merge($entry['highest'], ['at' => null]), 'php' => $entry['php'] ?? null];
            }
        }
        // The package's age is its highest tag's age. When that tag has no trusted date, an older
        // dated tag is not the last release, and S2 has nothing to measure. S2 never reads a
        // shared commit's date, also when a lower tag than the highest one carries the newest date.
        // The version stays set while a tag exists: S6 and the moves name it (SPEC I18b).
        $highestShared = $highestCommit !== null && isset($tagsOnCommit[$highestCommit]) && $tagsOnCommit[$highestCommit] >= self::SHARED_COMMIT_TAGS;
        $newestShared = $lastStableCommit !== null && isset($tagsOnCommit[$lastStableCommit]) && $tagsOnCommit[$lastStableCommit] >= self::SHARED_COMMIT_TAGS;
        // An undated highest tag stays the cause that S10 names.
        $lastStableShared = $highestShared || ($newestShared && $highestStable !== null && $highestStable->getReleaseDate() !== null);
        if ($highestStable !== null && ($highestStable->getReleaseDate() === null || $highestShared)) {
            $lastStableReleaseAt = null;
            $lastStableVersion = $highestStable->getPrettyVersion();
        } elseif ($lastStableShared) {
            $lastStableReleaseAt = null;
        }
        $releaseReading = [$lastStableReleaseAt, $lastStableVersion];
        if ($newestPreRelease !== null) {
            $commit = $newestPreRelease[3];
            $newestPreRelease[3] = $commit !== null && ($tagsOnCommit[$commit] ?? 0) >= self::SHARED_COMMIT_TAGS;
        }
        // A monorepo parent keeps a date per release for its children, minus the tags on a shared
        // commit. Any other package keeps none: it has no children, and the map holds one entry
        // per release.
        if ($replaces === []) {
            $releaseDates = [];
        } else {
            foreach ($commitByVersion as $normalized => $commit) {
                if ($commit !== null && $tagsOnCommit[$commit] >= self::SHARED_COMMIT_TAGS) {
                    unset($releaseDates[$normalized]);
                }
            }
        }
        // The same rule, per tag: the installed version can be a shared-commit tag under a branch
        // whose highest tag has a commit of its own.
        $sharedCommitVersions = [];
        foreach ($commitOfTag as $normalized => $commit) {
            if ($tagsOnCommit[$commit] >= self::SHARED_COMMIT_TAGS) {
                $sharedCommitVersions[$normalized] = true;
            }
        }
        // Only S2 reads a newest date on a line below the highest stable line. S6, the
        // moves, libyears and the metadata fields read the highest line's row, so a branch snapshot
        // never moves down a line. A highest tag with no trusted date keeps its own reading.
        $highestUndated = $highestStable !== null && ($highestStable->getReleaseDate() === null || $highestShared);
        [$newestAt, $newestVersion] = self::onTheHighestLine($lastStableReleaseAt, $lastStableVersion, $lastStableNormalized, $highestUndated, $byBranch, $newestDatedByBranch, $sharedCommitVersions);
        // Ruling W17-X1: S6, the moves and the metadata fields read stable tags only, as libyears do
        // (O5). S2 keeps the reading above, pre-releases included.
        [$stableAt, $stableVersion, $stableNormalized, $stableUndated] = self::lastStableOf($stable, $commitOfTag, $tagsOnCommit);
        [$lastStableReleaseAt, $lastStableVersion] = self::onTheHighestLine($stableAt, $stableVersion, $stableNormalized, $stableUndated, $byBranch, $newestDatedByBranch, $sharedCommitVersions);
        // A package with dev branches only has no version order: read its first branch, which
        // Packagist lists as the default branch.
        $anchor = $highestStable ?? ($versions[0] ?? null);

        $metadata = new self(
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
            $byBranch,
            array_keys($replaces),
            null,
            $releaseDates,
            null,
            $sharedCommitVersions,
            $abandonedBy,
            $lastStableShared
        );
        [$metadata->releaseReadingAt, $metadata->releaseReadingVersion] = $releaseReading;
        $metadata->newestPreRelease = $newestPreRelease;
        $metadata->newestVersion = $newestVersion;
        $metadata->newestAt = $newestAt;
        $metadata->hasTag = $hasTag;
        if ($installedVersion !== null) {
            $metadata->keepReleases($versions, $stable, $sharedCommitVersions, $dataDate, $installedVersion);
        }

        return $metadata;
    }

    /**
     * The release scan's lists: the stable releases above the installed one, and the `require`
     * names of the installed release and of the newest stable release on the run clock. Nothing
     * else per release.
     *
     * @param list<BasePackage>                                                     $versions
     * @param array<array-key, array{0: BasePackage, 1: ?\DateTimeImmutable, 2: ?string}> $stable
     * @param array<string, true>                                                      $sharedCommit
     */
    private function keepReleases(array $versions, array $stable, array $sharedCommit, \DateTimeImmutable $now, string $installedVersion): void
    {
        foreach ($versions as $version) {
            if ($version->getPrettyVersion() === $this->newestVersion) {
                $this->newestPhp = self::phpOf($version);
            }
        }
        $newest = null;
        foreach ($stable as $normalized => [$version, $at]) {
            if (($at === null || $at <= $now) && ($newest === null || Comparator::greaterThan((string) $normalized, $newest->getVersion()))) {
                $newest = $version;
            }
        }
        if ($newest !== null) {
            $this->newestRequires = self::requireNames($newest);
            // The dependent check asks only whether the release is out on the run date, so a shared commit's date is enough.
            [, $at, $php] = $stable[$newest->getVersion()];
            $this->newestStable = new StableRelease($newest->getVersion(), $newest->getPrettyVersion(), $at, $php, isset($sharedCommit[$newest->getVersion()]));
        }
        try {
            $installed = (new VersionParser())->normalize($installedVersion);
        } catch (\UnexpectedValueException $e) {
            return;
        }
        foreach ($versions as $version) {
            if ($version->getVersion() === $installed) {
                $this->installedRequires = self::requireNames($version);
                break;
            }
        }
        $snapshot = VersionParser::parseStability($installed) === 'dev';
        $above = [];
        foreach ($stable as $normalized => $release) {
            $normalized = (string) $normalized;
            if ($snapshot || Comparator::greaterThan($normalized, $installed)) {
                $above[] = $this->stableRelease($normalized, $release, $sharedCommit);
            }
        }
        usort($above, static fn (StableRelease $a, StableRelease $b): int => version_compare($a->normalized(), $b->normalized()));
        $this->releasesAbove = $above;
    }

    /**
     * @param array{0: BasePackage, 1: ?\DateTimeImmutable, 2: ?string} $release
     * @param array<string, true>                                     $sharedCommit
     */
    private function stableRelease(string $normalized, array $release, array $sharedCommit): StableRelease
    {
        $shared = isset($sharedCommit[$normalized]);

        return new StableRelease($normalized, $release[0]->getPrettyVersion(), $shared ? null : $release[1], $release[2], $shared);
    }

    /** @return list<string> in lower case: Composer's ArrayLoader lowercases every link target */
    private static function requireNames(BasePackage $version): array
    {
        return array_keys($version->getRequires());
    }

    /**
     * Whether a monorepo parent could fill a missing date. It can when:
     * - the package's last release is undated
     * - a shared commit's date that no parent resolves keeps the S2 reading unknown
     * - the installed branch's highest tag is undated
     * - the installed version's tag shares its commit and no parent has dated it
     * Other branches do not count: every split package has old branches with undated tags.
     *
     * @param ?string $installedBranch  {@see ReleaseBranch::of()} of the installed version, null for a snapshot
     * @param ?string $installedVersion the installed version as the lock prints it, null to let the branch decide
     */
    public function needsParentDates(?string $installedBranch, ?string $installedVersion = null): bool
    {
        if (($this->hasStableRelease && $this->lastStableReleaseAt === null) || ($this->hasTag && $this->releaseReadingAt === null)) {
            return true;
        }
        if ($installedBranch === null) {
            return false;
        }
        $own = $this->latestStableByBranch[$installedBranch] ?? null;
        if ($own !== null && $own['highest']['at'] === null) {
            return true;
        }

        return $installedVersion !== null && $this->releaseDatesBy === null && $this->sharesItsCommit($installedVersion);
    }

    /**
     * Whether the version, in any form the parser takes, is in {@see $sharedCommitVersions}. Asked
     * per version: a newest release on a shared commit says nothing about an older installed tag.
     */
    public function sharesItsCommit(string $version): bool
    {
        $normalized = self::normalized($version);

        return $normalized !== null && isset($this->sharedCommitVersions[$normalized]);
    }

    /**
     * Whether the date of a {@see latestStableByBranch()} row is only that of a commit its newest
     * tag shares with other stable tags. The tag was released on or after that date. A row that a
     * monorepo parent dates ({@see datedBy()}) carries a release's date.
     *
     * @param array{version: string, dated_by?: string} $branch
     */
    public function sharedCommitDates(array $branch): bool
    {
        return !isset($branch['dated_by']) && $this->sharesItsCommit($branch['version']);
    }

    /**
     * The monorepo parent's release date of the version, in any form the parser takes. Only a
     * parent's dates count: {@see fromPackages()} keeps the own release dates of any package with a
     * `self.version` replace too. Null when no parent dated this package ({@see datedBy()}) or the
     * parent has no trusted date for the version.
     */
    public function parentDateOf(string $version): ?\DateTimeImmutable
    {
        return $this->releaseDatesBy === null ? null : self::dateIn($this->releaseDates, $version);
    }

    /** @param array<string, \DateTimeImmutable> $dates by normalized version */
    private static function dateIn(array $dates, string $version): ?\DateTimeImmutable
    {
        $normalized = self::normalized($version);

        return $normalized === null ? null : $dates[$normalized] ?? null;
    }

    private static function normalized(string $version): ?string
    {
        try {
            return (new VersionParser())->normalize($version);
        } catch (\UnexpectedValueException $e) {
            return null;
        }
    }

    /**
     * This package's branches, dated by its monorepo parent's. A branch whose highest tag has no
     * date takes the parent's entry for the same branch and names the parent under `dated_by`. A
     * branch whose newest tag shares its commit takes the parent's date of that tag. The last
     * release and the per-release dates ({@see releaseDateOf()}) follow. A branch that the
     * parent does not date stays as it was, and a package that the parent does not replace comes
     * back unchanged. Immutable: this object stays unchanged. See docs/verdicts.md#dates-from-the-monorepo.
     */
    public function datedBy(self $parent): self
    {
        if (!\in_array($this->name, $parent->replaces, true)) {
            return $this;
        }
        $byBranch = $this->latestStableByBranch;
        $parentBranches = $parent->latestStableByBranch();
        $datedBranches = [];
        foreach ($byBranch as $key => $entry) {
            $theirs = $entry['highest']['at'] === null ? $parentBranches[$key] ?? null : null;
            if ($theirs === null || $theirs['at'] === null || $theirs['highest']['at'] === null) {
                $byBranch[$key] = $this->withParentDateOfNewest($entry, $parent);
                continue;
            }
            // The date is the parent's. The php requirement stays this package's own: the lock
            // installs the split, and the split can require less than its parent.
            $byBranch[$key] = ['version' => $theirs['version'], 'at' => $theirs['at'], 'highest' => array_merge($theirs['highest'], ['php' => $entry['highest']['php']]), 'dated_by' => $parent->name, 'php' => $entry['php'] ?? null];
            $datedBranches[] = (string) $key;
        }
        // A parent that dates no branch can still date releases: the installed version can sit on
        // a shared commit while its branch's highest tag does not.
        if ($datedBranches === [] && $parent->releaseDates === []) {
            return $this;
        }

        // The package's newest release is its highest branch's. That branch is in $datedBranches
        // only when the package could not date its own highest tag.
        $lastStableReleaseAt = $this->lastStableReleaseAt;
        $lastStableVersion = $this->lastStableVersion;
        $lastStableDatedBy = $this->lastStableDatedBy;
        $highestBranch = self::highestBranch($byBranch);
        $line = $this->highestLineAfterParent($byBranch, $highestBranch, $datedBranches, $this->lastStableVersion);
        if ($line !== null) {
            [$lastStableReleaseAt, $lastStableVersion, , $ownHighestNewest] = $line;
            $lastStableDatedBy = $ownHighestNewest ? null : $parent->name;
        }
        // The newest tag, a pre-release included, takes the same parent date as the last stable release.
        $newestVersion = $this->newestVersion;
        $newestAt = $this->newestAt;
        $newestPhp = $this->newestPhp;
        $newestLine = $this->highestLineAfterParent($byBranch, $highestBranch, $datedBranches, $this->newestVersion);
        if ($newestLine !== null) {
            [$newestAt, $newestVersion, $newestPhp] = $newestLine;
        }
        // SPEC §8.4.1: S2 reads the newest tag by trusted date over every branch row, so a lower
        // branch that the parent dates counts too. A shared commit's date is only a lower bound of
        // its release: a newer one that the parent does not date leaves the age unknown.
        $releaseReadingAt = $this->releaseReadingAt;
        $releaseReadingVersion = $this->releaseReadingVersion;
        $releaseReadingDatedBy = $this->releaseReadingDatedBy;
        $readingSharesItsCommit = $this->lastStableSharesItsCommit;
        // Ruling R17-D1: the parent dates stable tags only, so an undated pre-release above them keeps S2 unknown.
        // A dated pre-release on a shared commit is not undated: it can only block a newer reading (ruling R16-26).
        $undatedPreRelease = $releaseReadingAt === null && $releaseReadingVersion !== null
            && VersionParser::parseStability($releaseReadingVersion) !== 'stable'
            && ($this->newestPreRelease === null || $this->newestPreRelease[1] !== $releaseReadingVersion);
        if (!$undatedPreRelease && \in_array($highestBranch, $datedBranches, true)) {
            $releaseReadingAt = $lastStableReleaseAt;
            $releaseReadingVersion = $lastStableVersion;
            $releaseReadingDatedBy = $lastStableDatedBy;
        }
        $highest = $byBranch[$highestBranch] ?? null;
        // Read the highest row's own date only when a shared commit hid it. An undated highest tag, a
        // pre-release included, keeps the reading unknown.
        if (!$undatedPreRelease && $releaseReadingAt === null && $this->lastStableSharesItsCommit && $highest !== null && $highest['highest']['at'] !== null) {
            $releaseReadingAt = $highest['highest']['at'];
            $releaseReadingVersion = $highest['highest']['pretty'];
            // A parent-dated row dates its highest tag too, or the walk below reads the row's newer parent date.
            $releaseReadingDatedBy = $highest['dated_by'] ?? null;
        }
        if ($releaseReadingAt !== null) {
            // A pre-release on a shared commit has the commit's date, not a release's, so it can only block the reading.
            $unresolved = $this->newestPreRelease !== null && $this->newestPreRelease[3] ? $this->newestPreRelease[0] : null;
            foreach ($byBranch as $row) {
                $datedByParent = isset($row['dated_by']);
                if ($row['at'] === null) {
                    continue;
                }
                if ($this->sharedCommitDates($row)) {
                    $unresolved = $unresolved === null || $row['at'] > $unresolved ? $row['at'] : $unresolved;
                    continue;
                }
                if (self::newerTag($row['at'], $row['version'], $releaseReadingAt, $releaseReadingVersion)) {
                    $releaseReadingAt = $row['at'];
                    $releaseReadingVersion = $row['version'];
                    $releaseReadingDatedBy = $datedByParent ? $parent->name : null;
                }
            }
            // The branch rows hold stable tags only. S2 counts a dated pre-release too (SPEC §8.4.1).
            if ($this->newestPreRelease !== null && !$this->newestPreRelease[3] && self::newerTag($this->newestPreRelease[0], $this->newestPreRelease[1], $releaseReadingAt, $releaseReadingVersion)) {
                [$releaseReadingAt, $releaseReadingVersion] = $this->newestPreRelease;
                $releaseReadingDatedBy = null;
            }
            if ($unresolved !== null && $unresolved > $releaseReadingAt) {
                $releaseReadingAt = null;
                $releaseReadingVersion = $newestVersion;
                $releaseReadingDatedBy = null;
                $readingSharesItsCommit = true;
            }
        }

        $dated = new self(
            $this->name,
            $this->abandoned,
            $this->replacement,
            $this->hasStableRelease,
            $lastStableReleaseAt,
            $lastStableVersion,
            $this->releaseCount,
            $this->repositoryUrl,
            $this->type,
            $this->dataDate,
            $byBranch,
            $this->replaces,
            $lastStableDatedBy,
            $parent->releaseDates,
            $parent->name,
            $this->sharedCommitVersions,
            $this->abandonedBy,
            $readingSharesItsCommit
        );
        $dated->installedRequires = $this->installedRequires;
        $dated->newestRequires = $this->newestRequires;
        $dated->newestStable = $this->newestStable;
        $dated->releaseReadingAt = $releaseReadingAt;
        $dated->releaseReadingVersion = $releaseReadingVersion;
        $dated->releaseReadingDatedBy = $releaseReadingDatedBy;
        $dated->newestPreRelease = $this->newestPreRelease;
        $dated->newestVersion = $newestVersion;
        $dated->newestAt = $newestAt;
        $dated->newestPhp = $newestPhp;
        $dated->hasTag = $this->hasTag;
        $dated->highestBranchDatedByParent = \in_array($highestBranch, $datedBranches, true);
        $dated->releasesAbove = $this->releasesAbove === null ? null : array_map(
            static fn (StableRelease $release): StableRelease => $release->sharedCommit() ? $release->withDate($parent->releaseDateOf($release->normalized())) : $release,
            $this->releasesAbove
        );

        return $dated;
    }

    /**
     * The highest branch row after the parent dated it, when it replaces $version as the package's
     * newest release: the parent dates that row, or names the shared tag $version, or the highest
     * tag's own date beats the parent's (ruling R16-16h1). Null when $version stays.
     *
     * @param array<array-key, array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable, php: ?string}, dated_by?: string, php: ?string}> $byBranch
     * @param list<string>                                                                                                                                                                             $datedBranches
     *
     * @return ?array{0: ?\DateTimeImmutable, 1: string, 2: ?string, 3: bool} the date, the version, the php requirement, and whether the date is the package's own
     */
    private function highestLineAfterParent(array $byBranch, string $highestBranch, array $datedBranches, ?string $version): ?array
    {
        $row = $byBranch[$highestBranch] ?? null;
        if ($row === null) {
            return null;
        }
        $datedByParent = isset($row['dated_by']) && $row['version'] === $version;
        $ownHighestNewest = !isset($row['dated_by'])
            && ($this->latestStableByBranch[$highestBranch]['version'] ?? null) === $version
            && $row['version'] !== $version;
        if (!$datedByParent && !$ownHighestNewest && !\in_array($highestBranch, $datedBranches, true)) {
            return null;
        }

        return [$row['at'], $row['version'], $row['php'], $ownHighestNewest];
    }

    /**
     * The branch row with the parent's date of its newest tag, when that tag shares its commit.
     * When that tag is the branch's highest tag, the highest tag takes the date too. Else the
     * highest tag stays the newest when its own date is not older than the parent's.
     * See docs/verdicts.md#dates-from-the-monorepo.
     *
     * @param array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable, php: ?string}, dated_by?: string, php: ?string} $entry
     *
     * @return array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable, php: ?string}, dated_by?: string, php: ?string}
     */
    private function withParentDateOfNewest(array $entry, self $parent): array
    {
        $at = $this->sharesItsCommit($entry['version']) ? self::dateIn($parent->releaseDates, $entry['version']) : null;
        if ($at === null) {
            return $entry;
        }
        $highest = $entry['highest'];
        if ($highest['pretty'] === $entry['version']) {
            return ['at' => $at, 'highest' => ['at' => $at] + $highest, 'dated_by' => $parent->name] + $entry;
        }
        if ($highest['at'] !== null && $highest['at'] >= $at) {
            return ['version' => $highest['pretty'], 'at' => $highest['at'], 'highest' => $highest, 'php' => $highest['php']];
        }

        return ['at' => $at, 'dated_by' => $parent->name] + $entry;
    }

    /**
     * The greatest branch key in version order (`10` is greater than `9`, `0.3` than `0.0.3`). A
     * package with no branch gives `''`, which is no branch key and matches nothing.
     *
     * @param array<array-key, mixed> $byBranch
     */
    private static function highestBranch(array $byBranch): string
    {
        $highest = '';
        foreach (array_keys($byBranch) as $key) {
            $key = (string) $key;
            if (version_compare($key, $highest, '>')) {
                $highest = $key;
            }
        }

        return $highest;
    }

    private static function phpOf(BasePackage $version): ?string
    {
        $link = $version->getRequires()['php'] ?? null;

        return $link === null ? null : $link->getPrettyConstraint();
    }

    /**
     * The newest stable tag by trusted date (ruling R13-A). A highest tag with no date, or with a
     * shared commit's date, is the last release with no date.
     *
     * @param array<array-key, array{0: BasePackage, 1: ?\DateTimeImmutable, 2: ?string}> $stable       by normalized version
     * @param array<string, string>                                                    $commitOfTag  by normalized version
     * @param array<string, int>                                                       $tagsOnCommit by commit
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?string, 2: ?string, 3: bool} the date, the pretty and the normalized version, whether the highest tag has no trusted date
     */
    private static function lastStableOf(array $stable, array $commitOfTag, array $tagsOnCommit): array
    {
        $highest = null;
        $newest = null;
        foreach ($stable as $normalized => [, $at]) {
            $normalized = (string) $normalized;
            if ($highest === null || Comparator::greaterThan($normalized, $highest)) {
                $highest = $normalized;
            }
            if ($at !== null && self::newer($at, $normalized, $newest === null ? null : $stable[$newest][1], $newest)) {
                $newest = $normalized;
            }
        }
        $shared = static fn (string $normalized): bool => isset($commitOfTag[$normalized]) && $tagsOnCommit[$commitOfTag[$normalized]] >= self::SHARED_COMMIT_TAGS;
        if ($highest === null) {
            return [null, null, null, false];
        }
        if ($newest === null || $stable[$highest][1] === null || $shared($highest)) {
            return [null, $stable[$highest][0]->getPrettyVersion(), $newest, true];
        }

        return [$shared($newest) ? null : $stable[$newest][1], $stable[$newest][0]->getPrettyVersion(), $newest, false];
    }

    /**
     * A newest release on a line below the highest stable line gives way to the highest line's row.
     *
     * @param array<array-key, array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable, php: ?string}, dated_by?: string, php: ?string}> $byBranch
     * @param array<array-key, string>                                                                                     $newestDatedByBranch
     * @param array<string, true>                                                                                          $sharedCommitVersions
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?string} the date and the version
     */
    private static function onTheHighestLine(?\DateTimeImmutable $at, ?string $version, ?string $normalized, bool $highestUndated, array $byBranch, array $newestDatedByBranch, array $sharedCommitVersions): array
    {
        $newestKey = $normalized === null ? null : ReleaseBranch::of($normalized);
        if ($highestUndated || $newestKey === null || $byBranch === []) {
            return [$at, $version];
        }
        $highestKey = self::highestBranch($byBranch);
        if (!version_compare((string) $newestKey, $highestKey, '<')) {
            return [$at, $version];
        }
        $row = $byBranch[$highestKey];

        return [isset($sharedCommitVersions[$newestDatedByBranch[$highestKey] ?? '']) ? null : $row['at'], $row['version']];
    }

    /**
     * Whether a release at $at is newer than the one at $than: a later date, or on the same date a
     * higher version. The answer must not depend on the order in which a repository lists its
     * versions.
     */
    private static function newer(\DateTimeImmutable $at, string $normalized, ?\DateTimeImmutable $than, ?string $thanNormalized): bool
    {
        if ($than === null || $at > $than) {
            return true;
        }

        return $at >= $than && $thanNormalized !== null && Comparator::greaterThan($normalized, $thanNormalized);
    }

    /** {@see newer()} for two tags as a repository prints them. A tag that does not parse wins only by a later date. */
    private static function newerTag(\DateTimeImmutable $at, string $version, \DateTimeImmutable $than, ?string $thanVersion): bool
    {
        $parser = new VersionParser();
        try {
            $normalized = $parser->normalize($version);
            $thanNormalized = $thanVersion === null ? null : $parser->normalize($thanVersion);
        } catch (\UnexpectedValueException $e) {
            return $at > $than;
        }

        return self::newer($at, $normalized, $than, $thanNormalized);
    }

    private static function commitOf(BasePackage $version): ?string
    {
        $reference = $version->getSourceReference();

        return $reference === null || $reference === '' ? null : $reference;
    }

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
     * The registry that marked the package abandoned, from the notification-url of the version that
     * says so. The replacement is a name that this registry gave. Null when lockrot names no registry.
     */
    public function abandonedBy(): ?string
    {
        return $this->abandonedBy;
    }
    /**
     * The number of versions seen. Dev branches count only for a package with no tagged release,
     * because the `~dev` file is not fetched otherwise (docs/internals.md#two-passes).
     */
    public function releaseCount(): int
    {
        return $this->releaseCount;
    }
    /**
     * The URL of the Composer repository that answered for this name, null when none did. Never
     * written to a report: `accept-unchecked` compares it with a `repository` entry.
     */
    public function composerRepositoryUrl(): ?string
    {
        return $this->composerRepositoryUrl;
    }

    /** A copy that knows which Composer repository answered for it. */
    public function withComposerRepositoryUrl(?string $url): self
    {
        $clone = clone $this;
        $clone->composerRepositoryUrl = $url;

        return $clone;
    }

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

    /** Whether the package has a stable tag (ruling W17-X1). A tag with build metadata is stable. */
    public function hasStableRelease(): bool
    {
        return $this->hasStableRelease;
    }

    public function hasTag(): bool
    {
        return $this->hasTag;
    }

    /** The newest tag by trusted date, a pre-release included (ruling R13-A), null with no tag. */
    public function newestTagVersion(): ?string
    {
        return $this->newestVersion;
    }

    /** The date of {@see newestTagVersion()}, null when it has no trusted date. */
    public function newestTagAt(): ?\DateTimeImmutable
    {
        return $this->newestAt;
    }

    /** The `require.php` of {@see newestTagVersion()}, null when it requires none or when the release scan did not run. */
    public function newestTagPhp(): ?string
    {
        return $this->newestPhp;
    }

    /**
     * The newest trusted date of a stable tag, or the parent's date of the highest branch
     * ({@see datedBy()}). A pre-release never counts (ruling W17-X1). A newest date on a line below
     * the highest stable line gives way to that line's newest date. Null when the highest stable tag
     * has no trusted date, or when the date is that of a commit its tags share.
     */
    public function lastStableReleaseAt(): ?\DateTimeImmutable
    {
        return $this->lastStableReleaseAt;
    }

    /**
     * The trusted date of the highest stable tag, the newest end of libyears. A pre-release never
     * ends libyears, and a lower tag with a later date does not count (rulings R16-16c, R16-16g,
     * O5). Null when that tag has no trusted date. Without a branch row, {@see lastStableReleaseAt()}.
     */
    public function highestStableTagAt(): ?\DateTimeImmutable
    {
        $row = $this->latestStableByBranch[self::highestBranch($this->latestStableByBranch)] ?? null;

        return $row === null ? $this->lastStableReleaseAt : $row['highest']['at'];
    }

    /**
     * True when the version is the highest stable tag, or a pre-release above it: libyears end
     * there (rulings R17-13b, O5). A stable version above that tag is a tag that the repository
     * does not list, and stays measured.
     */
    public function isAtOrAboveTheHighestStableTag(string $version): bool
    {
        $row = $this->latestStableByBranch[self::highestBranch($this->latestStableByBranch)] ?? null;
        $normalized = self::normalized($version);

        if ($row === null || $normalized === null) {
            return false;
        }

        return $normalized === $row['highest']['normalized']
            || (VersionParser::parseStability($normalized) !== 'stable' && Comparator::greaterThan($normalized, $row['highest']['normalized']));
    }

    /**
     * The date of the release that S2 judges: the newest trusted date of a non-dev tag, pre-releases
     * included, also a parent date of a lower branch (SPEC §8.4.1 `age.release`). A tie goes to the
     * higher version. Null when the highest tag has no trusted date, or when a newer date is a shared
     * commit's.
     */
    public function releaseReadingAt(): ?\DateTimeImmutable
    {
        return $this->releaseReadingAt;
    }

    /** The tag that {@see releaseReadingAt()} dates. */
    public function releaseReadingVersion(): ?string
    {
        return $this->releaseReadingVersion;
    }

    /** The monorepo parent that dated {@see releaseReadingAt()}, null when the date is the package's own. */
    public function releaseReadingDatedBy(): ?string
    {
        return $this->releaseReadingDatedBy;
    }

    /**
     * The stable tag that {@see lastStableReleaseAt()} dates. With no trusted date, the highest
     * stable tag when that one has none, else the newest-dated stable tag. Never a pre-release
     * (ruling W17-X1), and never a tag on a line below the highest stable line. Null only when no
     * stable tag exists (ruling R17-X1a).
     */
    public function lastStableVersion(): ?string
    {
        return $this->lastStableVersion;
    }

    /** Whether a commit that other stable tags share dates the version that `releaseReadingAt()` reads. */
    public function lastStableSharesItsCommit(): bool
    {
        return $this->lastStableSharesItsCommit;
    }

    /**
     * Whether the monorepo parent's row replaced the row of the highest branch ({@see datedBy()}):
     * the split's highest tag had no trusted date. A parent date of one shared tag does not count.
     */
    public function highestBranchDatedByParent(): bool
    {
        return $this->highestBranchDatedByParent;
    }

    /** The monorepo parent that dated `lastStableReleaseAt()` ({@see datedBy()}). Null when the date is the package's own. */
    public function lastStableDatedBy(): ?string
    {
        return $this->lastStableDatedBy;
    }

    /** @return list<string> */
    public function replaces(): array
    {
        return $this->replaces;
    }

    /**
     * The release date of the version, as a monorepo parent's tag of it gives it: this package's
     * own tags for a parent, the parent's for a split package that it dated ({@see datedBy()}).
     * Null for a version that the parent does not list or dates by a shared commit, and for every
     * version of an ordinary package.
     *
     * @param string $normalizedVersion as Composer normalizes it (`8.83.27.0`)
     */
    public function releaseDateOf(string $normalizedVersion): ?\DateTimeImmutable
    {
        return $this->releaseDates[$normalizedVersion] ?? null;
    }

    /** The monorepo parent that dated {@see releaseDateOf()}. Null when the dates are the package's own or absent. */
    public function releaseDatesBy(): ?string
    {
        return $this->releaseDatesBy;
    }

    /** @return list<StableRelease>|null {@see $releasesAbove} */
    public function releasesAbove(): ?array
    {
        return $this->releasesAbove;
    }

    /** @return list<string>|null lowercased, null when the installed release is not among the releases read */
    public function installedRequires(): ?array
    {
        return $this->installedRequires;
    }

    /** @return list<string>|null lowercased: the `require` names of the highest stable release not dated after the run clock */
    public function newestRequires(): ?array
    {
        return $this->newestRequires;
    }

    public function newestStable(): ?StableRelease
    {
        return $this->newestStable;
    }

    /**
     * The branches that {@see $latestStableByBranch} describes. Pre-releases do not count. A branch
     * whose releases carry no `time` shows its highest tag as its newest, with a null date.
     *
     * @return array<array-key, array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable, php: ?string}, dated_by?: string, php: ?string}>
     */
    public function latestStableByBranch(): array
    {
        return $this->latestStableByBranch;
    }
}
