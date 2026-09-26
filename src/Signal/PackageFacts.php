<?php

declare(strict_types=1);

namespace Lockrot\Signal;

use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Forge\RepositoryActivity;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockedPackage;

/** @internal */
final class PackageFacts
{
    private LockedPackage $package;
    private ?PackageMetadata $metadata;
    private ?RepositoryActivity $activity;
    /** @var list<Advisory> */
    private array $advisories;
    private ?string $activityNotChecked;

    /**
     * @param list<Advisory> $advisories         the security advisories affecting the installed version
     * @param ?string        $activityNotChecked why the repository was never asked about, null when it was
     *                                           ({@see \Lockrot\Analyzer\Analyzer::activityNotCheckedReasons()});
     *                                           an answer of "no such repository" is a check that ran
     */
    public function __construct(LockedPackage $package, ?PackageMetadata $metadata, ?RepositoryActivity $activity, array $advisories = [], ?string $activityNotChecked = null)
    {
        $this->package = $package;
        $this->metadata = $metadata;
        $this->activity = $activity;
        $this->advisories = $advisories;
        $this->activityNotChecked = $activityNotChecked;
    }

    /** Why the repository activity behind S3 and S4 was never fetched, null when it was. */
    public function activityNotChecked(): ?string
    {
        return $this->activityNotChecked;
    }

    public function package(): LockedPackage
    {
        return $this->package;
    }

    public function metadata(): ?PackageMetadata
    {
        return $this->metadata;
    }

    public function activity(): ?RepositoryActivity
    {
        return $this->activity;
    }

    /** @return list<Advisory> */
    public function advisories(): array
    {
        return $this->advisories;
    }

    public function hasData(): bool
    {
        return $this->metadata !== null;
    }
}
