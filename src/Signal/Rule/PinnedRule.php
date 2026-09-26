<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Lockrot\Data\Repository\InstalledRelease;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockedPackage;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;

/**
 * S6. Two cases, checked in this order: the installed version is a branch, or it is not and the
 * repository lists no tagged version. The first is checked first, so a snapshot of a package that
 * never released is a `branch_snapshot` — and `has_stable_release` in the data is what says it never
 * released, where the summary alone read like any other snapshot.
 *
 * @internal
 */
final class PinnedRule implements SignalRule
{
    /** The installed version is a branch (`dev-main`, `2.x-dev`). */
    public const REASON_BRANCH_SNAPSHOT = 'branch_snapshot';
    /** The installed version is not a branch, and the repository lists no tagged version. */
    public const REASON_NO_STABLE_RELEASE = 'no_stable_release';

    public function evaluate(PackageFacts $facts): ?Signal
    {
        $package = $facts->package();
        $metadata = $facts->metadata();
        if ($package->isBranchSnapshot()) {
            // The lock dates a snapshot by the commit the branch pointed at, as InstalledRelease
            // reads it for every other surface; it is not a release date.
            $snapshotTime = InstalledRelease::of($package, $metadata)->lockTime();

            return new Signal(Signal::S6, Signal::LEVEL_WARN, 'pinned to branch snapshot '.$package->version(), self::data($package, $metadata, self::REASON_BRANCH_SNAPSHOT, $snapshotTime));
        }
        if ($metadata !== null && !$metadata->hasStableRelease()) {
            return new Signal(Signal::S6, Signal::LEVEL_WARN, 'no tagged release in its repository', self::data($package, $metadata, self::REASON_NO_STABLE_RELEASE, null));
        }

        return null;
    }

    /**
     * What the repository says about the package's releases, as `--explain` names it under
     * `metadata`. Without metadata nothing is known, and every release fact is null — never a
     * `has_stable_release: false` that would claim the package never released.
     *
     * @return array<string, mixed>
     */
    private static function data(LockedPackage $package, ?PackageMetadata $metadata, string $reason, ?\DateTimeImmutable $snapshotTime): array
    {
        return [
            'version' => $package->version(),
            'reason' => $reason,
            'has_stable_release' => $metadata === null ? null : $metadata->hasStableRelease(),
            'last_stable_release' => self::date($metadata === null ? null : $metadata->lastStableReleaseAt()),
            'last_stable_version' => $metadata === null ? null : $metadata->lastStableVersion(),
            'last_stable_dated_by' => $metadata === null ? null : $metadata->lastStableDatedBy(),
            'snapshot_time' => self::date($snapshotTime),
        ];
    }

    private static function date(?\DateTimeImmutable $date): ?string
    {
        return $date === null ? null : $date->format(\DATE_ATOM);
    }
}
