<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Allowlist\Allowlist;
use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Clock;
use Lockrot\Data\Abandoned\AbandonedIgnore;
use Lockrot\Data\Abandoned\AbandonedIgnoreMatch;
use Lockrot\Data\Advisory\AdvisoryBatch;
use Lockrot\Data\Advisory\AdvisoryLoaderInterface;
use Lockrot\Data\Forge\ActivityBatch;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlan;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Data\Forge\RepositoryActivity;
use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataFailure;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\MonorepoParents;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Deadline;
use Lockrot\Graph\DependencyGraph;
use Lockrot\Lock\LockedPackage;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Rule\AbandonedRule;
use Lockrot\Signal\Rule\NotCheckedRule;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalSet;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\VerdictEngine;

/** @internal */
final class Analyzer
{
    private MetadataLoaderInterface $metadata;
    private ActivityClient $activity;
    private ActivityFetchPlanner $planner;
    private RepoLocator $locator;
    private Allowlist $allowlist;
    private SignalSet $signals;
    private VerdictEngine $engine;
    private Clock $clock;
    private bool $offline;
    private Deadline $deadline;
    /** Null when the run has no advisory source. Then the run asks nothing and notes nothing. */
    private ?AdvisoryLoaderInterface $advisories;
    private MonorepoParents $parents;
    private AbandonedIgnore $abandonedIgnore;

    public function __construct(MetadataLoaderInterface $metadata, ActivityClient $activity, ActivityFetchPlanner $planner, RepoLocator $locator, Allowlist $allowlist, SignalSet $signals, VerdictEngine $engine, Clock $clock, bool $offline, ?AdvisoryLoaderInterface $advisories = null, ?MonorepoParents $parents = null, ?AbandonedIgnore $abandonedIgnore = null)
    {
        $this->metadata = $metadata;
        $this->activity = $activity;
        $this->planner = $planner;
        $this->locator = $locator;
        $this->allowlist = $allowlist;
        $this->signals = $signals;
        $this->engine = $engine;
        $this->clock = $clock;
        $this->offline = $offline;
        $this->advisories = $advisories;
        $this->parents = $parents ?? MonorepoParents::load();
        $this->abandonedIgnore = $abandonedIgnore ?? AbandonedIgnore::none();
        $this->deadline = Deadline::never();
    }

    public function allowlist(): Allowlist
    {
        return $this->allowlist;
    }

    public function withAllowlist(Allowlist $allowlist): self
    {
        $clone = clone $this;
        $clone->allowlist = $allowlist;

        return $clone;
    }

    /**
     * Give the metadata loader the same {@see Deadline} instance: it keeps its own copy of the
     * budget ({@see \Lockrot\Composer\ServiceFactory}).
     */
    public function withDeadline(Deadline $deadline): self
    {
        $clone = clone $this;
        $clone->deadline = $deadline;

        return $clone;
    }

    public function analyze(LockFile $lock, ProjectConfig $project, bool $includeDev): Report
    {
        return $this->analyzePackages($lock->packages($includeDev), $lock, $project, $includeDev);
    }

    /**
     * Analyses only $packages. $lock, $project and $includeDev still describe the whole lock: they
     * build the dependency graph, so a `via` chain runs through every locked package.
     *
     * @param list<LockedPackage> $packages
     */
    public function analyzePackages(array $packages, LockFile $lock, ProjectConfig $project, bool $includeDev): Report
    {
        return $this->analyzeWithFacts($packages, $lock, $project, $includeDev)->report();
    }

    /**
     * The same run and report as {@see analyzePackages()}, with the facts behind each finding
     * ({@see Analysis}).
     *
     * @param list<LockedPackage> $packages
     */
    public function analyzeWithFacts(array $packages, LockFile $lock, ProjectConfig $project, bool $includeDev): Analysis
    {
        $repositories = $project->repositories();
        $packages = array_map(static fn (LockedPackage $package): LockedPackage => $package->withRepositories($repositories), $packages);
        $graph = DependencyGraph::fromLock($lock, $project, $includeDev);
        $now = $this->clock->now();
        $notes = [];
        if ($this->offline) {
            $notes[] = RunNote::offline();
        }

        $batch = $this->fetchMetadata($packages);
        if ($batch->failed() !== []) {
            $notes[] = RunNote::metadataUnavailable($batch->failed());
        }
        [$metadata, $parentsFailed] = $this->dateSplitPackages($packages, $batch->metadata());
        foreach ($parentsFailed as $parent => $reason) {
            $notes[] = RunNote::monorepoParentUnavailable((string) $parent, $reason);
        }

        $advisories = $this->fetchAdvisories($packages);
        $notes = array_merge($notes, $advisories->notes());

        $abandonedIgnore = [];
        foreach ($packages as $package) {
            $abandonedIgnore[$package->name()] = $this->abandonedIgnore->match($package->name());
        }
        [$allowlisted, $repoByPackage, $candidateByPackage] = $this->classify($packages, $metadata, $abandonedIgnore, $now);

        $activityBatch = ActivityBatch::empty();
        if ($this->deadline->isPast()) {
            // The metadata pass used the whole budget. Requests to the repository hosts will push
            // the install past it. So the run drops the activity signals, and a run note says so:
            // the report must not read as "checked, nothing found". Planning waits too: it can
            // exchange Bitbucket credentials over the network ({@see \Lockrot\Data\Forge\ForgeAuth}).
            $activityNotes = [RunNote::repositoryActivityNotChecked()];
            $plan = null;
        } else {
            $plan = $this->planner->select($repoByPackage, $candidateByPackage);
            [$activityBatch, $activityNotes] = $this->fetchActivity($plan);
        }
        $notChecked = $this->activityNotCheckedReasons($repoByPackage, $activityBatch, $plan);
        $notes = array_merge($notes, $activityNotes);
        $activity = $activityBatch->activity();

        $findings = [];
        $factsByPackage = [];
        $notInRepository = 0;
        foreach ($packages as $package) {
            $meta = $metadata[$package->name()] ?? null;
            $repo = $repoByPackage[$package->name()] ?? null;
            $act = $repo !== null ? ($activity[$repo->key()] ?? null) : null;
            $entry = $allowlisted[$package->name()];
            $facts = new PackageFacts($package, $meta, $act, $advisories->for($package->name()), $notChecked[$package->name()] ?? null, $abandonedIgnore[$package->name()], $advisories->coverage()->for($package->name()));
            $factsByPackage[$package->name()] = $facts;
            $findings[] = $this->buildFinding($facts, $entry, $graph, $batch);
            if (!$package->isFromComposerRepository()) {
                ++$notInRepository;
            }
        }
        if ($notInRepository > 0) {
            $notes[] = RunNote::notFromComposerRepository($notInRepository);
        }
        $findings = TransitiveExposure::attach($findings, $graph);

        return new Analysis(
            new Report($findings, $notes, $now, \count($packages), $notInRepository, null, self::oldestCachedActivity($activity), $includeDev),
            $factsByPackage
        );
    }

    /**
     * Fetch time of the oldest activity answer from lockrot's cache, null when every answer was
     * fetched in this run.
     *
     * @param array<string, RepositoryActivity> $activity
     */
    private static function oldestCachedActivity(array $activity): ?\DateTimeImmutable
    {
        $cachedAt = [];
        foreach ($activity as $record) {
            if ($record->cachedAt() !== null) {
                $cachedAt[] = $record->cachedAt();
            }
        }

        return $cachedAt === [] ? null : min($cachedAt);
    }

    /**
     * The batch with each split package's branches dated by its monorepo parent
     * ({@see MonorepoParents}). A parent missing from the batch is loaded in one request, unless
     * the install-time budget is gone. A parent that the repositories do not list is no failure. A
     * parent that they could not deliver is returned as failed, so the report does not read as
     * measured. A budget failure is dropped: the branch stays undated.
     *
     * @param list<LockedPackage>            $packages
     * @param array<string, PackageMetadata> $metadata
     *
     * @return array{0: array<string, PackageMetadata>, 1: array<string, string>} the metadata, and the parents that failed to load with the reason
     */
    private function dateSplitPackages(array $packages, array $metadata): array
    {
        $children = $this->parents->children($packages, $metadata);
        if ($children === []) {
            return [$metadata, []];
        }
        $failed = [];
        $missing = $this->parents->missingCandidates($children, $metadata);
        if ($missing !== [] && !$this->deadline->isPast()) {
            $batch = $this->metadata->load($missing);
            $metadata += $batch->metadata();
            foreach ($batch->failed() as $name => $reason) {
                if (MetadataFailure::reason($reason) !== MetadataFailure::INSTALL_TIME_BUDGET) {
                    $failed[$name] = $reason;
                }
            }
        }

        return [$this->parents->date($children, $metadata), $failed];
    }

    /** @param list<LockedPackage> $packages */
    private function fetchMetadata(array $packages): MetadataBatch
    {
        $names = [];
        foreach ($packages as $package) {
            if ($package->isFromComposerRepository()) {
                $names[] = $package->name();
            }
        }

        return $this->metadata->load($names);
    }

    /**
     * Every package the run checks, whatever its origin, as `composer audit` asks: a packages-dev
     * one only when the run reads packages-dev. The loader applies the scope.
     *
     * @param list<LockedPackage> $packages
     */
    private function fetchAdvisories(array $packages): AdvisoryBatch
    {
        if ($this->advisories === null) {
            return AdvisoryBatch::empty();
        }
        $versionByName = [];
        $outside = [];
        foreach ($packages as $package) {
            $versionByName[$package->name()] = $package->version();
            if (!$package->isFromComposerRepository()) {
                $outside[] = $package->name();
            }
        }

        return $this->advisories->load($versionByName, $outside);
    }

    /**
     * A candidate for a tokenless activity lookup raised no S1, and either S2 fired or Composer's
     * list ignores its marking: then S3 is the only abandonment fact left for it.
     *
     * @param list<LockedPackage>                        $packages
     * @param array<string, PackageMetadata>             $metadata
     * @param array<string, AbandonedIgnoreMatch|null>   $abandonedIgnore
     *
     * @return array{0: array<string, AllowlistEntry|null>, 1: array<string, RepoRef>, 2: array<string, bool>}
     */
    private function classify(array $packages, array $metadata, array $abandonedIgnore, \DateTimeImmutable $now): array
    {
        $allowlisted = [];
        $repoByPackage = [];
        $candidateByPackage = [];
        foreach ($packages as $package) {
            $meta = $metadata[$package->name()] ?? null;
            $entry = $this->allowlist->match($package, $meta, $now);
            $allowlisted[$package->name()] = $entry;
            $repo = $this->locator->locate(($meta !== null ? $meta->repositoryUrl() : null) ?? $package->repositoryUrl());
            if ($repo === null || ($entry !== null && $entry->acceptsAll()) || !$package->isFromComposerRepository()) {
                continue;
            }
            $repoByPackage[$package->name()] = $repo;
            $facts = new PackageFacts($package, $meta, null, [], null, $abandonedIgnore[$package->name()] ?? null);
            $first = $this->signals->evaluate($facts);
            $candidateByPackage[$package->name()] = !$this->hasSignal($first, Signal::S1) && ($this->hasSignal($first, Signal::S2) || AbandonedRule::ignored($facts) !== null);
        }

        return [$allowlisted, $repoByPackage, $candidateByPackage];
    }

    /**
     * Why a package's repository was never asked about, by package name. A check that ran and came
     * back empty (a 404, a hidden repository) is not listed: S10
     * ({@see \Lockrot\Signal\Rule\NotCheckedRule}) marks only a check that never happened.
     *
     * @param array<string, RepoRef> $repoByPackage
     *
     * @return array<string, string>
     */
    private function activityNotCheckedReasons(array $repoByPackage, ActivityBatch $batch, ?ActivityFetchPlan $plan): array
    {
        $activity = $batch->activity();
        $reasons = [];
        $skipped = $plan === null ? [] : $plan->skippedPackages();
        foreach ($repoByPackage as $name => $repo) {
            if (isset($activity[$repo->key()])) {
                continue;
            }
            if ($this->offline) {
                $reasons[$name] = NotCheckedRule::OFFLINE;
                continue;
            }
            if ($plan === null) {
                $reasons[$name] = NotCheckedRule::BUDGET;
                continue;
            }
            if (isset($skipped[$name])) {
                $reasons[$name] = $skipped[$name] === ActivityFetchPlan::BUDGET ? NotCheckedRule::RATE_BUDGET : NotCheckedRule::NO_TOKEN;
                continue;
            }
            if ($batch->rateLimited($repo->forge())) {
                $reasons[$name] = NotCheckedRule::RATE_LIMIT;
                continue;
            }
            if (isset($batch->failedOn($repo->forge())[$repo->key()])) {
                $reasons[$name] = NotCheckedRule::FETCH_FAILED;
            }
        }

        return $reasons;
    }

    /** @return array{ActivityBatch, list<RunNote>} the batch, and the notes the round produced */
    private function fetchActivity(ActivityFetchPlan $plan): array
    {
        $batch = $this->activity->fetch($plan->repos());
        $repoByKey = [];
        foreach ($plan->repos() as $repo) {
            $repoByKey[$repo->key()] = $repo;
        }
        $notes = [];
        foreach ($plan->cappedForges() as $forge) {
            $notes[] = RunNote::repositoryActivityAnonymousCap($forge, $plan->checkedPackages($forge), $plan->skippedNoToken($forge), $plan->skippedBudget($forge));
        }
        foreach (RepoRef::FORGES as $forge) {
            $failed = [];
            foreach ($batch->failedOn($forge) as $key => $message) {
                $failed[] = [$repoByKey[$key], $message];
            }
            if ($batch->rateLimited($forge)) {
                $notes[] = RunNote::repositoryActivityRateLimited($forge, $failed);
            } elseif ($failed !== []) {
                $notes[] = RunNote::repositoryActivityUnreachable($forge, $failed);
            }
            $notFound = array_map(static fn (string $key): RepoRef => $repoByKey[$key], $batch->notFoundOn($forge));
            if ($notFound !== []) {
                $notes[] = RunNote::repositoryActivityNotFound($forge, $notFound);
            }
        }

        return [$batch, $notes];
    }

    private function buildFinding(PackageFacts $facts, ?AllowlistEntry $entry, DependencyGraph $graph, MetadataBatch $batch): Finding
    {
        $package = $facts->package();
        $meta = $facts->metadata();
        $activity = $facts->activity();
        $signals = $this->signals->evaluate($facts);
        // A maintenance gap cannot change a verdict that an entry accepts whole, or one that a
        // raised S1 already makes `abandoned`. It reads the raised S1, never the metadata's
        // abandoned bit: a marking that Composer's list ignores leaves S3 to decide.
        if (($entry !== null && $entry->acceptsAll()) || $this->hasSignal($signals, Signal::S1)) {
            $signals = NotCheckedRule::withoutMaintenanceGaps($signals);
        }
        $verdict = $this->engine->decide($signals, $entry !== null, $meta !== null);

        $note = null;
        if (!$package->isFromComposerRepository()) {
            $note = Finding::NOTE_NOT_IN_REPOSITORY;
        } elseif ($meta === null && isset($batch->failed()[$package->name()])) {
            $note = $this->metadataFailureNote($batch->failed()[$package->name()]);
        } elseif ($meta === null) {
            $note = 'not found in the repository';
        }

        return new Finding(
            $package->name(),
            $package->version(),
            $verdict,
            $signals,
            $graph->shortestChain($package->name()),
            $entry !== null ? $entry->reason() : null,
            $this->dataDate($meta, $activity),
            $note,
            $package->isDev(),
            array_keys($graph->chainsTo($package->name())),
            Libyears::measure($package, $meta),
            $package->origin(),
            AbandonedRule::replacementNamedBy($facts)
        );
    }

    /**
     * The offline and budget reasons state why the metadata is missing, so they get no prefix: with
     * it they read "Repository metadata unavailable: offline: ...". Every other reason is a bare
     * message that needs the prefix to make sense on a finding.
     */
    private function metadataFailureNote(string $reason): string
    {
        return \in_array(MetadataFailure::reason($reason), [MetadataFailure::OFFLINE, MetadataFailure::INSTALL_TIME_BUDGET], true)
            ? $reason
            : 'Repository metadata unavailable: '.$reason;
    }

    /** @param list<Signal> $signals */
    private function hasSignal(array $signals, string $id): bool
    {
        foreach ($signals as $signal) {
            if ($signal->id() === $id) {
                return true;
            }
        }

        return false;
    }

    /** Null compares below any object, so max() returns the newer date, or the only one. */
    private function dataDate(?PackageMetadata $meta, ?RepositoryActivity $activity): ?\DateTimeImmutable
    {
        return max($meta !== null ? $meta->dataDate() : null, $activity !== null ? $activity->fetchedAt() : null);
    }
}
