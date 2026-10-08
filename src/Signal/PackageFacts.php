<?php

declare(strict_types=1);

namespace Lockrot\Signal;

use Lockrot\Data\Abandoned\AbandonedIgnoreMatch;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Advisory\AdvisoryNameCoverage;
use Lockrot\Data\Forge\RepositoryActivity;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockedPackage;
use Lockrot\Security\PackageFixes;

/** @internal */
final class PackageFacts
{
    /** The value of `metadata.status`. */
    public const METADATA_READ = 'read';
    public const METADATA_UNAVAILABLE = 'unavailable';
    public const METADATA_NOT_FOUND = 'not_found';
    public const METADATA_NOT_FROM_COMPOSER_REPOSITORY = 'not_from_composer_repository';

    private LockedPackage $package;
    private ?PackageMetadata $metadata;
    private ?RepositoryActivity $activity;
    /** @var list<Advisory> */
    private array $advisories;
    private ?string $activityNotChecked;
    private ?AbandonedIgnoreMatch $abandonedIgnore;
    private ?AdvisoryNameCoverage $advisoryCoverage;
    private bool $metadataFailed;
    private ?PackageFixes $fixes = null;

    /**
     * @param list<Advisory> $advisories         only those that affect the installed version: S9 does not filter them
     * @param ?string        $activityNotChecked why the repository was never asked about, null when it was
     *                                           ({@see \Lockrot\Analyzer\Analyzer::activityNotCheckedReasons()}).
     *                                           An answer of "no such repository" is a check that ran.
     * @param ?AbandonedIgnoreMatch $abandonedIgnore  the patterns of Composer's abandoned ignore list that match the name
     * @param bool                  $metadataFailed   a repository that lists the package did not serve its metadata
     */
    public function __construct(LockedPackage $package, ?PackageMetadata $metadata, ?RepositoryActivity $activity, array $advisories = [], ?string $activityNotChecked = null, ?AbandonedIgnoreMatch $abandonedIgnore = null, ?AdvisoryNameCoverage $advisoryCoverage = null, bool $metadataFailed = false)
    {
        $this->metadataFailed = $metadataFailed;
        $this->package = $package;
        $this->metadata = $metadata;
        $this->activity = $activity;
        $this->advisories = $advisories;
        $this->activityNotChecked = $activityNotChecked;
        $this->abandonedIgnore = $abandonedIgnore;
        $this->advisoryCoverage = $advisoryCoverage;
    }

    /** A copy that carries what the release scan found for the counted advisories. */
    public function withFixes(PackageFixes $fixes): self
    {
        $copy = clone $this;
        $copy->fixes = $fixes;

        return $copy;
    }

    /** Null when the run counted no advisory, so the release scan did not run. */
    public function fixes(): ?PackageFixes
    {
        return $this->fixes;
    }

    public function abandonedIgnore(): ?AbandonedIgnoreMatch
    {
        return $this->abandonedIgnore;
    }

    /** What the advisory lookup did for this name, null when the run had no lookup. */
    public function advisoryCoverage(): ?AdvisoryNameCoverage
    {
        return $this->advisoryCoverage;
    }

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

    /** @return self::METADATA_* */
    public function metadataStatus(): string
    {
        if (!$this->package->isFromComposerRepository()) {
            return self::METADATA_NOT_FROM_COMPOSER_REPOSITORY;
        }
        if ($this->metadata !== null) {
            return self::METADATA_READ;
        }

        return $this->metadataFailed ? self::METADATA_UNAVAILABLE : self::METADATA_NOT_FOUND;
    }

    public function hasData(): bool
    {
        return $this->metadata !== null;
    }
}
