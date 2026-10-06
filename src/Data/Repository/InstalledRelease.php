<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

use Composer\Semver\VersionParser;
use Lockrot\Lock\LockedPackage;

/**
 * Which date lockrot reads as the installed version's release date. The lock's `time` is a
 * release date only where the repository dated the version by a release. A branch snapshot
 * carries its commit's date. A subtree split's tag carries the date of a commit that its other
 * tags share. See docs/verdicts.md#dates-from-the-monorepo.
 *
 * Every surface that prints or measures the installed version's date reads this one object, so
 * the same date is never a release in one line and a commit in the next.
 *
 * @internal
 */
final class InstalledRelease
{
    /** The lock's date is the release's, and nothing says otherwise. */
    public const RELEASE = 'release';
    /** The monorepo parent's tag of the same version dates the release. {@see datedBy()} names it. */
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

    /** Without metadata nothing tells a release date from a commit's, so the lock's date stands. */
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
        // The parent dates the version whether or not the lock has a date. Only a parent counts:
        // fromPackages() keeps release dates for any package that declares `replace: <other>
        // self.version`, else guzzlehttp/guzzle dates itself and reads its own release
        // as a shared commit.
        $datedBy = $metadata->releaseDatesBy();
        $parent = $datedBy === null ? null : self::parentDateOf($package, $metadata);
        if ($parent !== null) {
            return new self(self::DATED_BY_PARENT, $parent, $time, $datedBy);
        }
        if ($time === null) {
            return new self(self::UNDATED, null, null, null);
        }
        // Either reading sets the lock's date aside. First: fromPackages() marked the installed tag
        // as dated by a commit its neighbours share. Second: the package took a parent's date for
        // its newest release, so it is a split and all its tags are dated that way, even one that
        // the repository does not list. The second alone misses a split that no parent dates.
        if ($metadata->sharesItsCommit($package->version()) || $metadata->lastStableDatedBy() !== null) {
            return new self(self::SHARED_COMMIT, null, $time, null);
        }

        return new self(self::RELEASE, $time, $time, null);
    }

    /** One of the kind constants of this class. */
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
