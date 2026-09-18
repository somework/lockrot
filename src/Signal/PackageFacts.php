<?php

declare(strict_types=1);

namespace Lockrot\Signal;

use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Forge\RepositoryActivity;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockedPackage;

final class PackageFacts
{
    private LockedPackage $package;
    private ?PackageMetadata $metadata;
    private ?RepositoryActivity $activity;
    /** @var list<Advisory> */
    private array $advisories;

    /** @param list<Advisory> $advisories the security advisories affecting the installed version */
    public function __construct(LockedPackage $package, ?PackageMetadata $metadata, ?RepositoryActivity $activity, array $advisories = [])
    {
        $this->package = $package;
        $this->metadata = $metadata;
        $this->activity = $activity;
        $this->advisories = $advisories;
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
