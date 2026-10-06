<?php

declare(strict_types=1);

namespace Lockrot\Signal;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Lockrot\Clock;
use Lockrot\Data\Forge\RepositoryActivity;
use Lockrot\Data\Repository\InstalledRelease;
use Lockrot\Data\Repository\ReleaseBranch;

/**
 * The one producer of every age reading (issue #39.3, SPEC §8.4.1): how long before the run clock
 * the installed release, the package's newest release, the installed branch's newest release and
 * the repository's last push were. S2 judges {@see release()}, S4 {@see push()} and S8
 * {@see branchRelease()}; each copies its reading's date, version, years, `dated_by` and level,
 * so a fired signal quotes the reading it judged. Years are integer tenths on the run clock; a
 * level is decided on the exact ratio, so 4.95 years prints 5.0 and stays `warn` below a 5-year
 * `release-high-years`.
 *
 * A reading that cannot be taken says why. When the repository metadata was not read, that is the
 * metadata's reason, before any reason of the reading's own (SPEC §8.4.3 rule 5).
 *
 * @internal
 */
final class AgeMeasure
{
    /** The installed version is a branch: no release is installed, and no branch is on a release line. */
    public const BRANCH_SNAPSHOT = 'branch_snapshot';
    /** The lock's date for the installed tag is a commit other tags share, and no monorepo parent dates it. */
    public const SHARED_COMMIT = 'shared_commit';
    /** The lock entry carries no date; or the repository answered with no push date. */
    public const UNDATED = 'undated';
    /** The package is not from a Composer repository: no metadata is asked for, no repository activity either. */
    public const NOT_FROM_COMPOSER_REPOSITORY = 'not_from_composer_repository';
    /** The metadata was asked for and did not come. */
    public const UNAVAILABLE = 'unavailable';
    /** The Composer repository does not list the package. */
    public const NOT_FOUND = 'not_found';
    /** The package has no stable release at all. */
    public const NO_STABLE_RELEASE = 'no_stable_release';
    /** The releases that would be read carry no date lockrot trusts. */
    public const UNDATED_RELEASES = 'undated_releases';
    /** The repository lists no stable release on the installed branch. */
    public const NO_BRANCH_ROW = 'no_branch_row';
    /** The installed version is above every tag the repository lists on its branch. */
    public const ABOVE_LISTED_RELEASES = 'above_listed_releases';
    /** No repository the activity lookup could ask. */
    public const NO_REPOSITORY = 'no_repository';
    /** An allowlist entry accepts the whole package, so its repository is not asked. */
    public const ALLOWLISTED = 'allowlisted';
    /** The host answered that the repository does not exist. */
    public const REPOSITORY_NOT_FOUND = 'repository_not_found';

    private Clock $clock;
    private Thresholds $thresholds;
    private VersionParser $parser;

    public function __construct(Clock $clock, Thresholds $thresholds)
    {
        $this->clock = $clock;
        $this->thresholds = $thresholds;
        $this->parser = new VersionParser();
    }

    /**
     * The release date lockrot trusts for the installed version ({@see InstalledRelease}): the
     * lock's `time`, or the monorepo parent's tag of the same version. No threshold applies. It
     * reads the lock, not the metadata, so a snapshot is `branch_snapshot` whatever else is known.
     */
    public function installed(PackageFacts $facts): AgeReading
    {
        if ($facts->package()->isBranchSnapshot()) {
            return AgeReading::unmeasuredBecause(self::BRANCH_SNAPSHOT);
        }
        $installed = InstalledRelease::of($facts->package(), $facts->metadata());
        $at = $installed->at();
        if ($at === null) {
            return AgeReading::unmeasuredBecause($installed->kind() === InstalledRelease::SHARED_COMMIT ? self::SHARED_COMMIT : self::UNDATED);
        }

        return $this->reading($at, null, $installed->datedBy(), null);
    }

    /**
     * The package's newest tag by date, pre-releases counted: what S2 judges against
     * `release-warn-years` and `release-high-years`.
     *
     * @param ?string $metadataStatus why the metadata was not read when the caller knows
     *                                (`not_found`); otherwise it is told from the package's origin
     */
    public function release(PackageFacts $facts, ?string $metadataStatus = null): AgeReading
    {
        $metadata = $facts->metadata();
        if ($metadata === null) {
            return AgeReading::unmeasuredBecause(self::metadataReason($facts, $metadataStatus));
        }
        if (!$metadata->hasStableRelease()) {
            return AgeReading::unmeasuredBecause(self::NO_STABLE_RELEASE);
        }
        $at = $metadata->lastStableReleaseAt();
        if ($at === null) {
            return AgeReading::unmeasuredBecause(self::UNDATED_RELEASES);
        }

        return $this->reading($at, $metadata->lastStableVersion(), $metadata->lastStableDatedBy(), [$this->thresholds->releaseWarnYears(), $this->thresholds->releaseHighYears()]);
    }

    /**
     * The newest dated stable release on the installed version's branch ({@see ReleaseBranch}),
     * dated as S8 dates it: the release S8 judges. An undated highest tag means the branch's newest
     * release is one the repository does not date, so how much younger it is than the newest dated
     * one cannot be known (a subtree split dates tags by the commit they point at, and leaves many
     * undated; a tag sharing its commit with others is handed over undated for the same reason). An
     * installed version above every tag the branch lists — a lock written against a since-removed
     * tag — cannot be measured by that branch's last date either.
     *
     * @param ?string $metadataStatus as for {@see release()}
     */
    public function branchRelease(PackageFacts $facts, ?string $metadataStatus = null): AgeReading
    {
        $metadata = $facts->metadata();
        if ($metadata === null) {
            return AgeReading::unmeasuredBecause(self::metadataReason($facts, $metadataStatus));
        }
        $version = $facts->package()->version();
        if ($facts->package()->isBranchSnapshot()) {
            return AgeReading::unmeasuredBecause(self::BRANCH_SNAPSHOT);
        }
        $branch = ReleaseBranch::of($version);
        $own = $branch === null ? null : ($metadata->latestStableByBranch()[$branch] ?? null);
        if ($own === null) {
            return AgeReading::unmeasuredBecause(self::NO_BRANCH_ROW);
        }
        $at = $own['at'];
        if ($at === null || $own['highest']['at'] === null) {
            return AgeReading::unmeasuredBecause(self::UNDATED_RELEASES);
        }
        if ($this->isAhead($version, $own['highest']['normalized'])) {
            return AgeReading::unmeasuredBecause(self::ABOVE_LISTED_RELEASES);
        }

        return $this->reading($at, $own['version'], $own['dated_by'] ?? null, [$this->thresholds->releaseWarnYears(), $this->thresholds->releaseHighYears()]);
    }

    /**
     * The repository's last push (GitHub) or last commit (GitLab, Bitbucket): what S4 judges
     * against `push-warn-years` and `push-high-years`.
     *
     * @param ?string $notRead why the repository was not read when the caller decided it (SPEC
     *                         §5.8: allowlisted, a 404, a host lockrot cannot ask); otherwise
     *                         the plan cause the facts carry, the package's origin, or
     *                         `no_repository`
     */
    public function push(PackageFacts $facts, ?string $notRead = null): AgeReading
    {
        $activity = $facts->activity();
        if ($activity !== null) {
            return $this->pushOf($activity);
        }
        if ($notRead !== null) {
            return AgeReading::unmeasuredBecause($notRead);
        }
        $notChecked = $facts->activityNotChecked();
        if ($notChecked !== null) {
            return AgeReading::unmeasuredBecause($notChecked);
        }

        return AgeReading::unmeasuredBecause($facts->package()->isFromComposerRepository() ? self::NO_REPOSITORY : self::NOT_FROM_COMPOSER_REPOSITORY);
    }

    /** {@see push()} for a repository that answered: its date, or `undated` when the answer carried none. */
    public function pushOf(RepositoryActivity $activity): AgeReading
    {
        $at = $activity->pushedAt();

        return $at === null
            ? AgeReading::unmeasuredBecause(self::UNDATED)
            : $this->reading($at, null, null, [$this->thresholds->pushWarnYears(), $this->thresholds->pushHighYears()]);
    }

    /** @param array{int, int}|null $thresholds warn and high years, null where no threshold applies */
    private function reading(\DateTimeImmutable $at, ?string $version, ?string $datedBy, ?array $thresholds): AgeReading
    {
        $ratio = $this->clock->yearsSince($at);
        $level = $thresholds === null ? null : Thresholds::levelFor($ratio, $thresholds[0], $thresholds[1]);

        return AgeReading::measured($at, $this->clock->tenthsSince($at), $ratio, $version, $datedBy, $level);
    }

    private static function metadataReason(PackageFacts $facts, ?string $metadataStatus): string
    {
        if (!$facts->package()->isFromComposerRepository()) {
            return self::NOT_FROM_COMPOSER_REPOSITORY;
        }

        return $metadataStatus ?? self::UNAVAILABLE;
    }

    /**
     * Whether the installed version is above the highest stable tag the repository lists on its
     * branch — not above its newest release, which a backport on a lower minor can be.
     *
     * @param string $branchHighest already normalized ({@see \Lockrot\Data\Repository\PackageMetadata::latestStableByBranch()})
     */
    private function isAhead(string $installed, string $branchHighest): bool
    {
        try {
            return Comparator::greaterThan($this->parser->normalize($installed), $branchHighest);
        } catch (\UnexpectedValueException $e) {
            return true;
        }
    }
}
