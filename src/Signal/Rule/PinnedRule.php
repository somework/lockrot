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

    /**
     * The S6 data (docs/verdicts.md#what-s6-carries). Without metadata every release fact is null,
     * never `has_stable_release: false`, which claims that the package never released.
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
