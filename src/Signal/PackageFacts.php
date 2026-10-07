<?php

declare(strict_types=1);

namespace Lockrot\Signal;

use Lockrot\Data\Abandoned\AbandonedIgnoreMatch;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Advisory\AdvisoryNameCoverage;
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
    private ?AbandonedIgnoreMatch $abandonedIgnore;
    private ?AdvisoryNameCoverage $advisoryCoverage;

    /**
     * @param list<Advisory> $advisories         only those that affect the installed version: S9 does not filter them
     * @param ?string        $activityNotChecked why the repository was never asked about, null when it was
     *                                           ({@see \Lockrot\Analyzer\Analyzer::activityNotCheckedReasons()}).
     *                                           An answer of "no such repository" is a check that ran.
     * @param ?AbandonedIgnoreMatch $abandonedIgnore  the patterns of Composer's abandoned ignore list that match the name
     */
    public function __construct(LockedPackage $package, ?PackageMetadata $metadata, ?RepositoryActivity $activity, array $advisories = [], ?string $activityNotChecked = null, ?AbandonedIgnoreMatch $abandonedIgnore = null, ?AdvisoryNameCoverage $advisoryCoverage = null)
    {
        $this->package = $package;
        $this->metadata = $metadata;
        $this->activity = $activity;
        $this->advisories = $advisories;
        $this->activityNotChecked = $activityNotChecked;
        $this->abandonedIgnore = $abandonedIgnore;
        $this->advisoryCoverage = $advisoryCoverage;
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

    public function hasData(): bool
    {
        return $this->metadata !== null;
    }
}
