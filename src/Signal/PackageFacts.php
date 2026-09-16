<?php

declare(strict_types=1);

namespace Lockrot\Signal;

use Lockrot\Data\GitHub\RepositoryActivity;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockedPackage;

final class PackageFacts
{
    private LockedPackage $package;
    private ?PackageMetadata $metadata;
    private ?RepositoryActivity $activity;

    public function __construct(LockedPackage $package, ?PackageMetadata $metadata, ?RepositoryActivity $activity)
    {
        $this->package = $package;
        $this->metadata = $metadata;
        $this->activity = $activity;
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

    public function hasData(): bool
    {
        return $this->metadata !== null;
    }
}
