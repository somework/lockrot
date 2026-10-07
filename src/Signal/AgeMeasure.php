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
 * The one producer of every age reading. S2 judges {@see release()}, S4 {@see push()} and S8
 * {@see branchRelease()}, and each copies its reading, so a fired signal quotes what it judged.
 * Years are integer tenths. A level is decided on the exact ratio, so 4.95 years prints `5.0` and
 * stays `warn` below a `release-high-years` of 5.
 *
 * A reading that cannot be taken says why. When the repository metadata was not read, the reading
 * gives that reason before any reason of its own.
 *
 * @internal
 */
final class AgeMeasure
{
    /** The installed version is a branch: no release is installed, and no branch is on a release line. */
    public const BRANCH_SNAPSHOT = 'branch_snapshot';
    /** The lock's date for the installed tag is a commit other tags share, and no monorepo parent dates it. */
    public const SHARED_COMMIT = 'shared_commit';
    /** The lock entry carries no date, or the repository answered with no push date. */
    public const UNDATED = 'undated';
    /** The package is not from a Composer repository: no metadata is asked for, no repository activity either. */
    public const NOT_FROM_COMPOSER_REPOSITORY = 'not_from_composer_repository';
    /** The metadata was asked for and did not come. */
    public const UNAVAILABLE = 'unavailable';
    /** The Composer repository does not list the package. */
    public const NOT_FOUND = 'not_found';
    public const NO_STABLE_RELEASE = 'no_stable_release';
    /** The releases to read carry no date that lockrot trusts. */
    public const UNDATED_RELEASES = 'undated_releases';
    /** The repository lists no stable release on the installed branch. */
    public const NO_BRANCH_ROW = 'no_branch_row';
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
     * @param ?string $metadataStatus why the metadata was not read, when the caller knows it
     *                                (`not_found`). Else the package's origin gives the reason.
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
     * The newest dated stable release on the installed version's branch ({@see ReleaseBranch}):
     * the release S8 judges. An undated highest tag hides how much newer the branch's last release
     * is, so that branch has no reading, and neither has an installed version above every listed tag. See docs/verdicts.md#left-behind.
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
     * The repository's last activity, which S4 judges. What each host reports:
     * docs/internals.md#repository-hosts-and-credentials.
     *
     * @param ?string $notRead why the caller did not read the repository (allowlisted, a 404, a
     *                         host lockrot cannot ask). Else the reason comes from
     *                         {@see PackageFacts::activityNotChecked()}, the package's origin or
     *                         `no_repository`.
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
     * Whether the installed version is above the highest stable tag on its branch. Compare with
     * that tag, not with the newest release: a backport on a lower minor can be the newest.
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
