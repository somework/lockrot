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
 * per-release object. The exceptions are a date per stable tag of a monorepo parent
 * ({@see $releaseDates}) and the stable tags that share a commit ({@see $sharedCommitVersions}).
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

    private string $name;
    private bool $abandoned;
    private ?string $replacement;
    private ?string $abandonedBy;
    private bool $hasStableRelease;
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
     * @var array<array-key, array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable}, dated_by?: string, php: ?string}>
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
     * @param array<array-key, array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable}, dated_by?: string, php: ?string}> $latestStableByBranch
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
        ?string $abandonedBy = null
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
        $this->replaces = $replaces;
        $this->lastStableDatedBy = $lastStableDatedBy;
        $this->releaseDates = $releaseDates;
        $this->releaseDatesBy = $releaseDatesBy;
        $this->sharedCommitVersions = $sharedCommitVersions;
        $this->abandonedBy = $abandonedBy;
    }

    /**
     * The repository URL is the highest stable release's, not an older release's. An older release
     * can name a one-off repository, and the activity check then flags the package `abandoned`.
     * The release is picked by version, not by date or listing order. `time` is optional, and a
     * hand-written packages.json or a Satis build can omit it or reorder releases. When that
     * release names no repository, the analyzer reads the lock entry's URL. When that names none
     * either, the activity check skips the package: a false `abandoned` costs more than a missed one.
     *
     * @param list<BasePackage> $versions the releases of one package, with no AliasPackage
     */
    public static function fromPackages(string $name, array $versions, \DateTimeImmutable $dataDate): self
    {
        $abandoned = false;
        $replacement = null;
        $abandonedBy = null;
        $hasStableRelease = false;
        $lastStableReleaseAt = null;
        $lastStableVersion = null;
        $highestStable = null;
        $type = null;
        $byBranch = [];
        $tagsOnCommit = [];
        $highestCommitByBranch = [];
        $highestCommit = null;
        $replaces = [];
        // Kept past this method only for a monorepo parent, and only for tags whose commit is a
        // release's ({@see $releaseDates}).
        $releaseDates = [];
        $commitByVersion = [];
        // Dated or not, unlike $commitByVersion: the shared-commit tags are read from it.
        $commitOfTag = [];

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
                $hasStableRelease = true;
                if ($highestStable === null || Comparator::greaterThan($version->getVersion(), $highestStable->getVersion())) {
                    $highestStable = $version;
                    $highestCommit = self::commitOf($version);
                }
                $releaseDate = $version->getReleaseDate();
                if ($releaseDate !== null) {
                    $releaseDate = self::toImmutable($releaseDate);
                    if ($lastStableReleaseAt === null || $releaseDate > $lastStableReleaseAt) {
                        $lastStableReleaseAt = $releaseDate;
                        $lastStableVersion = $version->getPrettyVersion();
                    }
                }
                // The branch view keeps the newest dated stable release per branch. A backport on a
                // lower minor is the branch's last word. An alpha on a new major is not a branch
                // that upstream moved to. S2 counts pre-releases too: there a tag can only make the
                // package look younger, here it makes findings. The branch's highest tag travels
                // with it, with its date: with no date, the branch's age is unknown. A branch with
                // no dated release shows that tag as its newest. Compare normalized strings only: a
                // repository can put anything in `version`, and the loader never parses it.
                $normalized = $version->getVersion();
                $branch = ReleaseBranch::of($normalized);
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
                    $tag = ['normalized' => $normalized, 'pretty' => $pretty, 'at' => $releaseDate];
                    // The php requirement stays with the release that the branch names: a project that
                    // moves onto the branch must satisfy it ({@see \Lockrot\Signal\PhpFloor}).
                    $php = self::phpOf($version);
                    $entry = $byBranch[$branch] ?? null;
                    if ($entry === null) {
                        $byBranch[$branch] = ['version' => $pretty, 'at' => $releaseDate, 'highest' => $tag, 'php' => $php];
                        $highestCommitByBranch[$branch] = $commit;
                    } else {
                        if (Comparator::greaterThan($normalized, $entry['highest']['normalized'])) {
                            $entry['highest'] = $tag;
                            $highestCommitByBranch[$branch] = $commit;
                        }
                        if ($releaseDate !== null && ($entry['at'] === null || $releaseDate > $entry['at'])) {
                            $entry['version'] = $pretty;
                            $entry['at'] = $releaseDate;
                            $entry['php'] = $php;
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
                $byBranch[$branch] = ['version' => $entry['version'], 'at' => $entry['at'], 'highest' => ['normalized' => $entry['highest']['normalized'], 'pretty' => $entry['highest']['pretty'], 'at' => null], 'php' => $entry['php'] ?? null];
            }
        }
        // The package's age is its highest tag's age. When that tag has no trusted date, an older
        // dated tag is not the last release, and S2 has nothing to measure.
        if ($highestStable !== null && ($highestStable->getReleaseDate() === null || ($highestCommit !== null && isset($tagsOnCommit[$highestCommit]) && $tagsOnCommit[$highestCommit] >= self::SHARED_COMMIT_TAGS))) {
            $lastStableReleaseAt = null;
            $lastStableVersion = null;
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
        // A package with dev branches only has no version order: read its first branch, which
        // Packagist lists as the default branch.
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
            $byBranch,
            array_keys($replaces),
            null,
            $releaseDates,
            null,
            $sharedCommitVersions,
            $abandonedBy
        );
    }

    /**
     * Whether a monorepo parent could fill a missing date. It can when:
     * - the package's last release is undated
     * - the installed branch's highest tag is undated
     * - the installed version's tag shares its commit and no parent has dated it
     * Other branches do not count: every split package has old branches with undated tags.
     *
     * @param ?string $installedBranch  {@see ReleaseBranch::of()} of the installed version, null for a snapshot
     * @param ?string $installedVersion the installed version as the lock prints it, null to let the branch decide
     */
    public function needsParentDates(?string $installedBranch, ?string $installedVersion = null): bool
    {
        if ($this->hasStableRelease && $this->lastStableReleaseAt === null) {
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
        try {
            return isset($this->sharedCommitVersions[(new VersionParser())->normalize($version)]);
        } catch (\UnexpectedValueException $e) {
            return false;
        }
    }

    /**
     * This package's branches, dated by its monorepo parent's. A branch whose highest tag has no
     * date takes the parent's entry for the same branch and names the parent under `dated_by`. The
     * last release and the per-release dates ({@see releaseDateOf()}) follow. A branch that the
     * parent does not date stays as it was, and a package that the parent does not replace comes
     * back unchanged. Immutable: it returns a new object. See docs/verdicts.md#dates-from-the-monorepo.
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
            if ($entry['highest']['at'] !== null) {
                continue;
            }
            $theirs = $parentBranches[$key] ?? null;
            if ($theirs === null || $theirs['at'] === null || $theirs['highest']['at'] === null) {
                continue;
            }
            // The date is the parent's. The php requirement stays this package's own: the lock
            // installs the split, and the split can require less than its parent.
            $byBranch[$key] = ['version' => $theirs['version'], 'at' => $theirs['at'], 'highest' => $theirs['highest'], 'dated_by' => $parent->name, 'php' => $entry['php'] ?? null];
            $datedBranches[] = (string) $key;
        }
        // A parent that dates no branch can still date releases: the installed version can sit on
        // a shared commit while its branch's highest tag does not.
        if ($datedBranches === [] && $parent->releaseDates === []) {
            return $this;
        }

        // The package's age is its highest branch's. That branch is in $datedBranches only when
        // the package could not date its own highest tag.
        $lastStableReleaseAt = $this->lastStableReleaseAt;
        $lastStableVersion = $this->lastStableVersion;
        $lastStableDatedBy = $this->lastStableDatedBy;
        $highestBranch = self::highestBranch($byBranch);
        if (\in_array($highestBranch, $datedBranches, true)) {
            $lastStableReleaseAt = $byBranch[$highestBranch]['at'];
            $lastStableVersion = $byBranch[$highestBranch]['version'];
            $lastStableDatedBy = $parent->name;
        }

        return new self(
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
            $this->abandonedBy
        );
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

    /** The newest dated stable release. Null when the highest tag has no trusted date. */
    public function lastStableReleaseAt(): ?\DateTimeImmutable
    {
        return $this->lastStableReleaseAt;
    }

    public function lastStableVersion(): ?string
    {
        return $this->lastStableVersion;
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

    /**
     * The branches that {@see $latestStableByBranch} describes. Pre-releases do not count. A branch
     * whose releases carry no `time` shows its highest tag as its newest, with a null date.
     *
     * @return array<array-key, array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable}, dated_by?: string, php: ?string}>
     */
    public function latestStableByBranch(): array
    {
        return $this->latestStableByBranch;
    }
}
