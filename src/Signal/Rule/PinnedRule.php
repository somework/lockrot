<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Lockrot\Data\Repository\InstalledRelease;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockedPackage;
use Lockrot\Signal\Data\PinnedData;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;

/**
 * S6: docs/verdicts.md#what-s6-carries. A branch snapshot is checked before a missing tagged
 * release, so a snapshot of a package that never released is a `branch_snapshot`. Its
 * `has_stable_release` says that it never released.
 *
 * @internal
 */
final class PinnedRule implements SignalRule
{
    public const REASON_BRANCH_SNAPSHOT = 'branch_snapshot';
    public const REASON_NO_STABLE_RELEASE = 'no_stable_release';

    public function evaluate(PackageFacts $facts): ?Signal
    {
        $package = $facts->package();
        $metadata = $facts->metadata();
        if ($package->isBranchSnapshot()) {
            // The lock dates a snapshot by the commit the branch pointed at. That is not a release date.
            $snapshotTime = InstalledRelease::of($package, $metadata)->lockTime();

            return new Signal(Signal::S6, Signal::LEVEL_WARN, 'pinned to branch snapshot '.$package->version(), self::data($package, $metadata, self::REASON_BRANCH_SNAPSHOT, $snapshotTime));
        }
        if ($metadata !== null && !$metadata->hasStableRelease()) {
            return new Signal(Signal::S6, Signal::LEVEL_WARN, 'no tagged release in its repository', self::data($package, $metadata, self::REASON_NO_STABLE_RELEASE, null));
        }

        return null;
    }

    private static function data(LockedPackage $package, ?PackageMetadata $metadata, string $reason, ?\DateTimeImmutable $snapshotTime): PinnedData
    {
        return new PinnedData(
            $package->version(),
            $reason,
            $metadata === null ? null : $metadata->hasStableRelease(),
            self::date($metadata === null ? null : $metadata->lastStableReleaseAt()),
            $metadata === null ? null : $metadata->lastStableVersion(),
            $metadata === null ? null : $metadata->lastStableDatedBy(),
            self::date($snapshotTime),
            self::tagRelation($metadata === null ? null : $metadata->lastStableReleaseAt(), $snapshotTime)
        );
    }

    private static function date(?\DateTimeImmutable $date): ?string
    {
        return $date === null ? null : $date->format(\DATE_ATOM);
    }

    /** How the newest tag's date relates to the snapshot's commit date; null when either is missing. */
    private static function tagRelation(?\DateTimeImmutable $tag, ?\DateTimeImmutable $snapshot): ?string
    {
        if ($tag === null || $snapshot === null) {
            return null;
        }
        if ($tag < $snapshot) {
            return 'older';
        }

        return $tag > $snapshot ? 'newer' : 'same';
    }
}
