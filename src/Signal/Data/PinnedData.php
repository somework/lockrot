<?php

declare(strict_types=1);

namespace Lockrot\Signal\Data;

use Lockrot\Signal\Signal;

/**
 * Without metadata every release fact is null, never `has_stable_release: false`, which claims
 * that the package never released (docs/verdicts.md#what-s6-carries).
 *
 * @internal
 */
final class PinnedData implements SignalData
{
    private string $version;
    private string $reason;
    private ?bool $hasStableRelease;
    private ?string $lastStableRelease;
    private ?string $lastStableVersion;
    private ?string $lastStableDatedBy;
    private ?string $snapshotTime;
    private ?string $tagRelation;

    /**
     * @param ?string $lastStableRelease an ISO 8601 date and time
     * @param ?string $snapshotTime      an ISO 8601 date and time
     * @param ?string $tagRelation       `older`, `newer` or `same`: the newest tag against the snapshot's commit
     */
    public function __construct(string $version, string $reason, ?bool $hasStableRelease, ?string $lastStableRelease, ?string $lastStableVersion, ?string $lastStableDatedBy, ?string $snapshotTime, ?string $tagRelation)
    {
        $this->version = $version;
        $this->reason = $reason;
        $this->hasStableRelease = $hasStableRelease;
        $this->lastStableRelease = $lastStableRelease;
        $this->lastStableVersion = $lastStableVersion;
        $this->lastStableDatedBy = $lastStableDatedBy;
        $this->snapshotTime = $snapshotTime;
        $this->tagRelation = $tagRelation;
    }

    public function signalId(): string
    {
        return Signal::S6;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** @return array{version: string, reason: string, has_stable_release: ?bool, last_stable_release: ?string, last_stable_version: ?string, last_stable_dated_by: ?string, snapshot_time: ?string, tag_relation: ?string} */
    public function jsonSerialize(): array
    {
        return [
            'version' => $this->version,
            'reason' => $this->reason,
            'has_stable_release' => $this->hasStableRelease,
            'last_stable_release' => $this->lastStableRelease,
            'last_stable_version' => $this->lastStableVersion,
            'last_stable_dated_by' => $this->lastStableDatedBy,
            'snapshot_time' => $this->snapshotTime,
            'tag_relation' => $this->tagRelation,
        ];
    }
}
