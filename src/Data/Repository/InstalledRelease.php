<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

use Composer\Semver\VersionParser;
use Lockrot\Lock\LockedPackage;

/**
 * What the lock's `time` for the installed version is a date of, and which date lockrot reads as
 * the release's. Composer writes the date the repository gave the version, and that is a release's
 * only where the repository dated it by one:
 *
 *  - a branch snapshot carries its commit's date, and no release of it exists;
 *  - a subtree split's tag is dated by a commit its other tags share — illuminate/macroable
 *    v10.48.28 is locked at 2023-06-05 for a release of 2024-11-21 — so the lock's date is a year
 *    and a half early, and the monorepo parent's tag of the same version is what dates it;
 *  - a lock entry can carry no date at all.
 *
 * Every surface that prints or measures the installed version's date reads this one object, so the
 * same date cannot be called a release in one line and a commit in the next.
 */
final class InstalledRelease
{
    /** The lock's date is the release's, and nothing says otherwise. */
    public const RELEASE = 'release';
    /** The monorepo parent's tag of the same version dates the release; {@see datedBy()} names it. */
    public const DATED_BY_PARENT = 'dated_by_parent';
    /** The lock's date is a commit this package's tags share, and no parent dates the version. */
    public const SHARED_COMMIT = 'shared_commit';
    /** The installed version is a branch, and the lock's date is the commit it points at. */
    public const BRANCH_SNAPSHOT = 'branch_snapshot';
    /** The lock entry carries no date. */
    public const UNDATED = 'undated';

    private string $kind;
    private ?\DateTimeImmutable $at;
    private ?\DateTimeImmutable $lockTime;
    private ?string $datedBy;

    private function __construct(string $kind, ?\DateTimeImmutable $at, ?\DateTimeImmutable $lockTime, ?string $datedBy)
    {
        $this->kind = $kind;
        $this->at = $at;
        $this->lockTime = $lockTime;
        $this->datedBy = $datedBy;
    }

    /**
     * Read off the lock entry and the repository metadata. Without metadata there is nothing to
     * tell a release date from a commit's, and the lock is read as it reads itself.
     */
    public static function of(LockedPackage $package, ?PackageMetadata $metadata): self
    {
        $time = $package->time();
        if ($package->isBranchSnapshot()) {
            // A branch is not a release, so the parent's map — stable releases only — cannot date it.
            return new self($time === null ? self::UNDATED : self::BRANCH_SNAPSHOT, null, $time, null);
        }
        if ($metadata === null) {
            return new self($time === null ? self::UNDATED : self::RELEASE, $time, $time, null);
        }
        // The parent dates the version whether or not the lock carries a date of its own.
        $parent = self::parentDateOf($package, $metadata);
        if ($parent !== null) {
            return new self(self::DATED_BY_PARENT, $parent, $time, $metadata->releaseDatesBy());
        }
        if ($time === null) {
            return new self(self::UNDATED, null, null, null);
        }
        // Two readings of the same fact, and either is enough to set the lock's date aside. The
        // first is the installed tag itself: fromPackages() marks every tag it dates by a commit
        // the tag's neighbours share. The second is the package — one that could not date its own
        // newest release and took a parent's is a split, and a split's tags are all dated that
        // way, including a tag the repository no longer lists for the first reading to mark.
        // Asking only the second read a commit's date as a release's wherever no parent was
        // involved: illuminate/contracts v8.83.27 without one, and wallabag's pagerfanta/twig
        // v4.8.0, whose newest release nothing dates either.
        if ($metadata->sharesItsCommit($package->version()) || $metadata->lastStableDatedBy() !== null) {
            return new self(self::SHARED_COMMIT, null, $time, null);
        }

        return new self(self::RELEASE, $time, $time, null);
    }

    /** One of the constants above. */
    public function kind(): string
    {
        return $this->kind;
    }

    /** The release date lockrot trusts for the installed version, null when it trusts none. */
    public function at(): ?\DateTimeImmutable
    {
        return $this->at;
    }

    /** What the lock itself carries, whatever kind of date that is. */
    public function lockTime(): ?\DateTimeImmutable
    {
        return $this->lockTime;
    }

    /** The monorepo parent whose tag dates the version, null when the date is the lock's own or there is none. */
    public function datedBy(): ?string
    {
        return $this->datedBy;
    }

    /** Whether a release date was found at all — the one question every measurement asks first. */
    public function isDated(): bool
    {
        return $this->at !== null;
    }

    /** The parent's date for the installed version, null when it has none or the version does not parse. */
    private static function parentDateOf(LockedPackage $package, PackageMetadata $metadata): ?\DateTimeImmutable
    {
        try {
            return $metadata->releaseDateOf((new VersionParser())->normalize($package->version()));
        } catch (\UnexpectedValueException $e) {
            return null;
        }
    }
}
