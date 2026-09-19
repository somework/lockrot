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
    /**
     * How many stable tags have to sit on one source commit before their date is read as the
     * commit's rather than a release's. A subtree split piles them up by the dozen — 83 on the
     * commit behind illuminate/macroable v10.49.0, dated a year and a half before the release.
     * Two on one commit is what an ordinary repository does now and then: a re-tag, or the last
     * two releases of a branch cut with nothing changed in between (symfony/* 3.4.46 and 3.4.47);
     * there the date is at most one release interval off, and reading it keeps a dead branch
     * measurable. The understatement grows with the count, which is the only handle there is —
     * every tag on the commit carries the same date, so the span between them cannot be read.
     */
    public const SHARED_COMMIT_TAGS = 3;

    /**
     * The `replace` constraint a subtree split carries: the component's `vX.Y.Z` is the monorepo's
     * own `vX.Y.Z`. Composer resolves it to an exact-version constraint and keeps the written form
     * on the link, which is the form that can be told apart from a range.
     */
    private const SELF_VERSION = 'self.version';

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
     * compares the installed branch against. A branch this package could not date itself and its
     * monorepo parent did ({@see datedBy()}) names the parent under `dated_by`.
     *
     * @var array<array-key, array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable}, dated_by?: string}>
     */
    private array $latestStableByBranch;
    /**
     * Every package name a version of this one `replace`s at `self.version` — the split packages of
     * a monorepo (`laravel/framework` names `illuminate/*`), empty for an ordinary package. The union
     * over the versions seen: a component that left the monorepo is still one it once carried.
     *
     * @var list<string>
     */
    private array $replaces;
    /** The monorepo parent whose dates `lastStableReleaseAt`/`lastStableVersion` come from, null when they are this package's own. */
    private ?string $lastStableDatedBy;

    /**
     * @param array<array-key, array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable}, dated_by?: string}> $latestStableByBranch
     * @param list<string>                                                                                                                                                   $replaces
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
        ?string $lastStableDatedBy = null
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
        // Stable tags per source commit, and the commit each branch's highest tag points at: a tag
        // that shares its commit with another stable tag was cut without a change in this repository,
        // so its `time` — the commit's — is not the release's. See the pass over $byBranch below.
        $tagsOnCommit = [];
        $highestCommitByBranch = [];
        $highestCommit = null;
        $replaces = [];

        foreach ($versions as $version) {
            foreach ($version->getReplaces() as $link) {
                // Only `self.version`: that is the subtree split's claim that the two tags are one
                // release, which is what makes the parent's dates this package's. A replace with a
                // range (`symplify/easy-coding-standard` replaces `symfony/polyfill-ctype` at `*`)
                // says "do not install that one too" and says nothing about when either released.
                if (self::SELF_VERSION === $link->getPrettyConstraint()) {
                    $replaces[$link->getTarget()] = true;
                }
            }
            if (!$abandoned && $version instanceof CompletePackage && $version->isAbandoned()) {
                $abandoned = true;
                $replacement = $version->getReplacementPackage();
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
                    $commit = self::commitOf($version);
                    if ($commit !== null) {
                        $tagsOnCommit[$commit] = ($tagsOnCommit[$commit] ?? 0) + 1;
                    }
                    $pretty = $version->getPrettyVersion();
                    $tag = ['normalized' => $normalized, 'pretty' => $pretty, 'at' => $releaseDate];
                    $entry = $byBranch[$branch] ?? null;
                    if ($entry === null) {
                        $byBranch[$branch] = ['version' => $pretty, 'at' => $releaseDate, 'highest' => $tag];
                        $highestCommitByBranch[$branch] = $commit;
                    } else {
                        if (Comparator::greaterThan($normalized, $entry['highest']['normalized'])) {
                            $entry['highest'] = $tag;
                            $highestCommitByBranch[$branch] = $commit;
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
        // A subtree split (illuminate/*, symfony/*) cuts a tag on every release of the monorepo
        // whether or not this directory changed, so tags pile up on one commit — illuminate/macroable
        // has 83 stable tags on the commit behind v10.49.0 — and Packagist dates each by that
        // commit: `time` says when the directory last changed, years before the release it names.
        // A tag sharing its commit with SHARED_COMMIT_TAGS - 1 other stable tags or more is therefore
        // dated by no release, and is treated as the undated tag it effectively is: the branch's age
        // is not known. Fewer than that is a re-tag or a branch's last releases cut with nothing
        // changed, where the date is one release interval off at most and stays readable.
        // Dev branches and pre-releases do not count: `dev-main` sits on the newest tag's commit by
        // construction, and a final cut on its release candidate's commit is dated days late, not years.
        foreach ($byBranch as $branch => $entry) {
            $commit = $highestCommitByBranch[$branch] ?? null;
            if ($commit !== null && $tagsOnCommit[$commit] >= self::SHARED_COMMIT_TAGS) {
                $byBranch[$branch] = ['version' => $entry['version'], 'at' => $entry['at'], 'highest' => ['normalized' => $entry['highest']['normalized'], 'pretty' => $entry['highest']['pretty'], 'at' => null]];
            }
        }
        // The package's age is its highest tag's age. When that tag carries no date — or, as above,
        // a date that is the commit's rather than the release's — the newest dated one below it is
        // not "the last release": it is the last release the repository dated, and how much
        // younger the tags above it are cannot be known; S2 then has nothing to measure and stays quiet.
        if ($highestStable !== null && ($highestStable->getReleaseDate() === null || ($highestCommit !== null && isset($tagsOnCommit[$highestCommit]) && $tagsOnCommit[$highestCommit] >= self::SHARED_COMMIT_TAGS))) {
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
            $byBranch,
            array_keys($replaces)
        );
    }

    /**
     * Whether the dates this package's findings read are missing in a way a monorepo parent could
     * fill: the installed branch's highest tag is undated, or the package's own last release is
     * ({@see fromPackages()} hands both over undated when the tag shares its commit with other
     * stable tags, as a subtree split's do). Branches other than the installed one do not count —
     * every split package has old branches with undated tags, and they decide nothing.
     *
     * @param ?string $installedBranch {@see ReleaseBranch::of()} of the installed version, null for a snapshot
     */
    public function needsParentDates(?string $installedBranch): bool
    {
        if ($this->hasStableRelease && $this->lastStableReleaseAt === null) {
            return true;
        }
        if ($installedBranch === null) {
            return false;
        }
        $own = $this->latestStableByBranch[$installedBranch] ?? null;

        return $own !== null && $own['highest']['at'] === null;
    }

    /**
     * This package's branches dated by its monorepo parent's. A split package's `vX.Y.Z` is the
     * parent's `vX.Y.Z` (`replace: {child: self.version}`), and the parent's tag is dated by its own
     * release where the split's is dated by a commit other tags share, or not at all. So every branch
     * whose highest tag carries no date takes the parent's entry for the same branch — newest dated
     * release, highest tag, dates — and is marked `dated_by`; the package's own last release follows
     * when it was undated for the same reason and the parent dates the branch it is on. A branch the
     * parent does not have, or leaves undated too, is left as it was; a package the parent does not
     * replace is returned unchanged. Immutable: a new object, this one untouched.
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
            $byBranch[$key] = ['version' => $theirs['version'], 'at' => $theirs['at'], 'highest' => $theirs['highest'], 'dated_by' => $parent->name];
            $datedBranches[] = (string) $key;
        }
        if ($datedBranches === []) {
            return $this;
        }

        // The package's own age is its highest branch's. That branch is in $datedBranches exactly
        // when this package could not date its own highest tag — which is when fromPackages() left
        // lastStableReleaseAt null — so asking whether the parent dated it is the whole question.
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
            $lastStableDatedBy
        );
    }

    /**
     * The branch the package's highest stable tag is on: the greatest key as versions go
     * (`10` above `9`, `0.3` above `0.0.3`). A package with no branches answers `''`, which is no
     * branch key — every key is a version — and so matches nothing the caller compares it against.
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

    /** The commit the release's `source` points at, null when the repository names none. */
    private static function commitOf(BasePackage $version): ?string
    {
        $reference = $version->getSourceReference();

        return $reference === null || $reference === '' ? null : $reference;
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

    /** The monorepo parent `lastStableReleaseAt()` was read from ({@see datedBy()}), null when it is this package's own date. */
    public function lastStableDatedBy(): ?string
    {
        return $this->lastStableDatedBy;
    }

    /** @return list<string> every package name a version of this one replaces; {@see $replaces} */
    public function replaces(): array
    {
        return $this->replaces;
    }

    /**
     * The newest dated stable release on every release branch (`version`, `at`) and the branch's
     * highest stable tag (`highest`: `normalized`, `pretty`, `at`), keyed by {@see ReleaseBranch} key
     * (an integer where PHP makes one of `"1"`); pre-releases do not count. A branch whose releases
     * carry no `time` at all shows its highest tag as its newest, with a null date.
     *
     * @return array<array-key, array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable}, dated_by?: string}>
     */
    public function latestStableByBranch(): array
    {
        return $this->latestStableByBranch;
    }
}
