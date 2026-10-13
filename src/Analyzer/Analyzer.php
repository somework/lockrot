<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Allowlist\AcceptedCheck;
use Lockrot\Allowlist\Allowlist;
use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Allowlist\UncheckedAcceptance;
use Lockrot\Clock;
use Lockrot\Data\Abandoned\AbandonedIgnore;
use Lockrot\Data\Abandoned\AbandonedIgnoreMatch;
use Lockrot\Data\Abandoned\AbandonedIgnoreRule;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Advisory\AdvisoryBatch;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\AdvisoryLoaderInterface;
use Lockrot\Data\Advisory\IgnoredAdvisory;
use Lockrot\Data\Forge\ActivityBatch;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlan;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ActivityLookup;
use Lockrot\Data\Forge\LocatedRepository;
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
use Lockrot\Score\CountedAdvisory;
use Lockrot\Security\FixFinder;
use Lockrot\Security\LinkIndex;
use Lockrot\Security\PackageFixes;
use Lockrot\Signal\AgeMeasure;
use Lockrot\Signal\BranchRow;
use Lockrot\Signal\Data\AbandonedData;
use Lockrot\Signal\Data\NotCheckedData;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Rule\AbandonedRule;
use Lockrot\Signal\Rule\AdvisoryRule;
use Lockrot\Signal\Rule\NotCheckedRule;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalSet;
use Lockrot\Verdict\Age;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FindingDetails;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\GradedPackage;
use Lockrot\Verdict\MoveContext;
use Lockrot\Verdict\Moves;
use Lockrot\Verdict\MoveSubject;
use Lockrot\Verdict\NextStep;
use Lockrot\Verdict\Score;
use Lockrot\Verdict\ThroughCheck;
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
    private UncheckedAcceptance $acceptance;

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
        $this->acceptance = UncheckedAcceptance::none();
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

    /** The project's `accept-unchecked` list, which buildFinding() applies after the S10 filter. */
    public function uncheckedAcceptance(): UncheckedAcceptance
    {
        return $this->acceptance;
    }

    public function withUncheckedAcceptance(UncheckedAcceptance $acceptance): self
    {
        $clone = clone $this;
        $clone->acceptance = $acceptance;

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
        [$allowlisted, $repoByPackage, $candidateByPackage, $decided] = $this->classify($packages, $metadata, $abandonedIgnore, $now);

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
        $lookups = $decided + $this->answers($repoByPackage, $activityBatch, $plan);
        $notes = array_merge($notes, $activityNotes, self::hostNotes($lookups));
        $activity = $activityBatch->activity();

        $finder = new FixFinder($this->signals->phpFloor(), LinkIndex::of($lock, $project));
        $fixes = $advisories->byName() === [] ? null : $finder;
        $findings = [];
        $factsByPackage = [];
        $notInRepository = 0;
        foreach ($packages as $package) {
            $meta = $metadata[$package->name()] ?? null;
            $entry = $allowlisted[$package->name()];
            $facts = (new PackageFacts($package, $meta, null, $advisories->for($package->name()), null, $abandonedIgnore[$package->name()], $advisories->coverage()->for($package->name()), isset($batch->failed()[$package->name()])))
                ->withActivityLookup($lookups[$package->name()]);
            $factsByPackage[$package->name()] = $facts;
            $every = $advisories->every($package->name());
            $finding = $this->buildFinding($facts, $entry, $graph, $project->isRead(), $batch, $fixes, $advisories->ignored($package->name()), $every);
            $findings[] = $finding->withMoves($this->moves($finding, $facts, $finder, $lock, $metadata, $every));
            if (!$package->isFromComposerRepository()) {
                ++$notInRepository;
            }
        }
        if ($notInRepository > 0) {
            $notes[] = RunNote::notFromComposerRepository($notInRepository, $advisories->coverage()->scope() === AdvisoryCoverage::SCOPE_ALL);
        }
        $uncovered = UncoveredAdvisories::of($findings);
        $notes = array_merge($notes, $uncovered === [] ? [] : [RunNote::ignoreDoesNotCoverAdvisories($uncovered)], $this->abandonedNotes($findings));
        $findings = TransitiveExposure::attach($findings, $graph);
        $graded = [];
        foreach ($findings as $finding) {
            $graded[$finding->package()] = GradedPackage::of($finding);
        }
        $findings = array_map(static fn (Finding $finding): Finding => $finding->withHolders($graded), $findings);

        return new Analysis(
            new Report($findings, $notes, $now, \count($packages), $notInRepository, null, self::oldestCachedActivity($activity), $includeDev, $advisories->coverage()),
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
            $batch = $this->metadata->load(array_fill_keys($missing, null));
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
        $installed = [];
        foreach ($packages as $package) {
            if ($package->isFromComposerRepository()) {
                $installed[$package->name()] = $package->normalizedVersion() ?? $package->version();
            }
        }

        return $this->metadata->load($installed);
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
        $aliasVersions = [];
        foreach ($packages as $package) {
            $versionByName[$package->name()] = $package->normalizedVersion() ?? $package->version();
            if (!$package->isFromComposerRepository()) {
                $outside[] = $package->name();
            }
            $aliasVersions[$package->name()] = $package->aliasVersions();
        }

        return $this->advisories->load($versionByName, $outside, $aliasVersions);
    }

    /**
     * Which repository each package's activity lookup asks, and the lookups that lockrot decides
     * before any request, in the precedence of docs/verdicts.md#what-was-not-checked. A candidate
     * for a tokenless activity lookup raised no S1, and either S2 fired or Composer's list ignores
     * its marking: then S3 is the only abandonment fact left for it.
     *
     * @param list<LockedPackage>                        $packages
     * @param array<string, PackageMetadata>             $metadata
     * @param array<string, AbandonedIgnoreMatch|null>   $abandonedIgnore
     *
     * @return array{0: array<string, AllowlistEntry|null>, 1: array<string, RepoRef>, 2: array<string, bool>, 3: array<string, ActivityLookup>}
     */
    private function classify(array $packages, array $metadata, array $abandonedIgnore, \DateTimeImmutable $now): array
    {
        $allowlisted = [];
        $repoByPackage = [];
        $candidateByPackage = [];
        $decided = [];
        foreach ($packages as $package) {
            $meta = $metadata[$package->name()] ?? null;
            $entry = $this->allowlist->match($package, $meta, $now);
            $allowlisted[$package->name()] = $entry;
            $where = $this->locator->classify(($meta !== null ? $meta->repositoryUrl() : null) ?? $package->repositoryUrl());
            $repo = $where->ref();
            if ($entry !== null && $entry->acceptsAll()) {
                $decided[$package->name()] = ActivityLookup::notRead(ActivityLookup::ALLOWLISTED, $where);
            } elseif (!$package->isFromComposerRepository()) {
                $decided[$package->name()] = ActivityLookup::notRead(ActivityLookup::NOT_FROM_COMPOSER_REPOSITORY, $where);
            } elseif ($repo === null) {
                $decided[$package->name()] = ActivityLookup::notRead($where->kind(), $where);
            } else {
                $repoByPackage[$package->name()] = $repo;
                $facts = new PackageFacts($package, $meta, null, [], null, $abandonedIgnore[$package->name()] ?? null);
                $first = $this->signals->evaluate($facts);
                $candidateByPackage[$package->name()] = !$this->hasSignal($first, Signal::S1) && ($this->hasSignal($first, Signal::S2) || AbandonedRule::ignored($facts) !== null);
            }
        }

        return [$allowlisted, $repoByPackage, $candidateByPackage, $decided];
    }

    /**
     * What the lookup gave each package it asked about: a read, a 404, or the plan or network
     * cause, in that precedence. An answer beats a network cause.
     *
     * @param array<string, RepoRef> $repoByPackage
     *
     * @return array<string, ActivityLookup>
     */
    private function answers(array $repoByPackage, ActivityBatch $batch, ?ActivityFetchPlan $plan): array
    {
        $activity = $batch->activity();
        $lookups = [];
        foreach ($repoByPackage as $name => $repo) {
            $read = $activity[$repo->key()] ?? null;
            if ($read !== null) {
                $lookups[$name] = ActivityLookup::read($read);
            } elseif (\in_array($repo->key(), $batch->notFoundOn($repo->forge()), true)) {
                $lookups[$name] = ActivityLookup::notFound($repo);
            } else {
                $lookups[$name] = ActivityLookup::notRead($this->networkCause((string) $name, $repo, $batch, $plan), LocatedRepository::located($repo));
            }
        }

        return $lookups;
    }

    /** Why lockrot got no answer about an asked repository: S10 ({@see NotCheckedRule}) carries it. */
    private function networkCause(string $name, RepoRef $repo, ActivityBatch $batch, ?ActivityFetchPlan $plan): string
    {
        if ($this->offline) {
            return NotCheckedRule::OFFLINE;
        }
        if ($plan === null) {
            return NotCheckedRule::BUDGET;
        }
        $skipped = $plan->skippedPackages()[$name] ?? null;
        if ($skipped !== null) {
            return $skipped === ActivityFetchPlan::BUDGET ? NotCheckedRule::RATE_BUDGET : NotCheckedRule::NO_TOKEN;
        }

        return $batch->rateLimited($repo->host()) ? NotCheckedRule::RATE_LIMIT : NotCheckedRule::FETCH_FAILED;
    }

    /**
     * @param array<string, ActivityLookup> $lookups
     *
     * @return list<RunNote> the one note that names each host lockrot cannot ask, or none
     */
    private static function hostNotes(array $lookups): array
    {
        $hosts = [];
        foreach ($lookups as $lookup) {
            if ($lookup->reason() === ActivityLookup::HOST_NOT_SUPPORTED) {
                $hosts[] = $lookup->where();
            }
        }

        return $hosts === [] ? [] : [RunNote::repositoryActivityHostNotSupported($hosts)];
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
            $limited = [];
            $unreachable = [];
            foreach ($batch->failedOn($forge) as $key => $message) {
                if ($batch->rateLimited($repoByKey[$key]->host())) {
                    $limited[] = [$repoByKey[$key], $message];
                } else {
                    $unreachable[] = [$repoByKey[$key], $message];
                }
            }
            if ($limited !== []) {
                $notes[] = RunNote::repositoryActivityRateLimited($forge, $limited);
            }
            if ($unreachable !== []) {
                $notes[] = RunNote::repositoryActivityUnreachable($forge, $unreachable);
            }
            $notFound = array_map(static fn (string $key): RepoRef => $repoByKey[$key], $batch->notFoundOn($forge));
            if ($notFound !== []) {
                $notes[] = RunNote::repositoryActivityNotFound($forge, $notFound);
            }
        }

        return [$batch, $notes];
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<RunNote> what Composer's abandoned ignore list and audit setting did in this run
     */
    private function abandonedNotes(array $findings): array
    {
        $notes = [];
        $patterns = [];
        $by = null;
        $marked = false;
        // The note names the packages in the order that the report writes them.
        usort($findings, [Report::class, 'compareGraded']);
        foreach ($findings as $finding) {
            $ignored = $finding->details()->abandonedIgnored();
            if ($ignored !== null) {
                $by = $ignored->by();
                $patterns[$finding->package()] = array_map(static fn (AbandonedIgnoreRule $rule): string => $rule->pattern(), $ignored->rules());
            }
            $marked = $marked || $finding->signalData(AbandonedData::class) !== null;
        }
        if ($by !== null) {
            $notes[] = RunNote::abandonedMarkingsIgnored($by, $patterns);
        }
        $notAudited = $this->abandonedIgnore->notAuditedBy();
        if ($notAudited !== null && $marked) {
            $notes[] = RunNote::abandonedNotAuditedByComposer($notAudited->policyKey(), $notAudited->value());
        }
        $unreadable = $this->abandonedIgnore->whyUnreadable();
        if ($unreadable !== null) {
            $notes[] = RunNote::abandonedIgnoreUnreadable($unreadable);
        }

        return $notes;
    }

    /**
     * @param list<IgnoredAdvisory> $ignored
     * @param list<Advisory>        $every   every advisory of the package on any version
     */
    private function buildFinding(PackageFacts $facts, ?AllowlistEntry $entry, DependencyGraph $graph, bool $composerJsonRead, MetadataBatch $batch, ?FixFinder $fixes, array $ignored, array $every): Finding
    {
        $counted = [];
        if ($fixes !== null && $facts->advisories() !== []) {
            $found = $fixes->find($facts, $every);
            $facts = $facts->withFixes($found);
            $counted = self::countedAdvisories($facts->advisories(), $found);
        }
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
        [$signals, $accepted] = $this->acceptUnchecked($signals, $facts);
        $verdict = $this->engine->decide($signals, $entry !== null && $entry->acceptsAll(), $meta !== null);

        $note = null;
        if (!$package->isFromComposerRepository()) {
            $note = Finding::NOTE_NOT_IN_REPOSITORY;
        } elseif ($meta === null && isset($batch->failed()[$package->name()])) {
            $note = $this->metadataFailureNote($batch->failed()[$package->name()]);
        } elseif ($meta === null) {
            $note = 'not found in the repository';
        }

        $finding = new Finding(
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
            Libyears::measure($package, $meta, $facts->metadataStatus()),
            $package->origin(),
            AbandonedRule::replacementNamedBy($facts),
            AdvisoryRule::legacy($facts)[1]
        );

        return $finding
            ->withComposerJson($composerJsonRead, $graph->namedIn($package->name()))
            ->withFlags(FlagSet::fromSignals($signals, $entry, $counted), $facts->metadataStatus() === PackageFacts::METADATA_READ)
            ->withDetails(FindingDetails::of($facts, $entry, $this->signals->phpFloor(), $ignored, $signals, $batch->failed()[$package->name()] ?? null, Age::of(new AgeMeasure($this->clock, $this->signals->thresholds()), $facts), $accepted));
    }

    /**
     * After the S10 filter, each S10 entry that the project's `accept-unchecked` list accepts
     * leaves S10 (docs/configuration.md#accept-unchecked). S10 goes when no entry is left.
     *
     * @param list<Signal> $signals
     *
     * @return array{list<Signal>, list<AcceptedCheck>}
     */
    private function acceptUnchecked(array $signals, PackageFacts $facts): array
    {
        $s10 = null;
        foreach ($signals as $signal) {
            $data = $signal->data();
            $s10 = $data instanceof NotCheckedData ? $data : $s10;
        }
        if ($s10 === null) {
            return [$signals, []];
        }
        $package = $facts->package();
        $where = $facts->activityLookup()->where();
        $ref = $where->ref();
        $meta = $facts->metadata();
        $now = $this->clock->now();
        // A named registry is compared by its name, an unnamed one by the URL that answered. A
        // package of no Composer repository kind has neither: only its source or its name matches.
        $origin = $package->origin();
        $registry = $origin->registry();
        $composer = !$origin->isComposerRepository() ? null : ($registry !== null ? 'https://'.$registry : ($meta === null ? null : $meta->composerRepositoryUrl()));
        $kept = [];
        $accepted = [];
        foreach ($s10->checks() as $check) {
            $by = $this->acceptance->acceptedBy($check, $package->name(), $where->host(), $ref === null ? null : $ref->path(), $package->repositoryUrl(), $composer, $now);
            if ($by === null) {
                $kept[] = $check;
                continue;
            }
            $accepted[] = new AcceptedCheck($check, $by);
        }
        // With nothing accepted, keeping() gives S10 back with the words that it had.
        return [NotCheckedRule::keeping($signals, $kept, $where->host(), $package->origin()->kind()), $accepted];
    }

    /**
     * The finding's moves (`next_step`) from its facts, its release scan and the run's floors.
     *
     * @param array<string, PackageMetadata> $metadata by package name, what the run read
     * @param list<Advisory>                 $every    every advisory of the package on any version
     */
    private function moves(Finding $finding, PackageFacts $facts, FixFinder $finder, LockFile $lock, array $metadata, array $every): Moves
    {
        if (!$finding->isGraded()) {
            return Moves::none();
        }
        $floor = $this->signals->phpFloor();
        $details = $finding->details();
        $ranges = [];
        foreach ($facts->advisories() as $advisory) {
            if ($advisory->affectedRange() !== null) {
                $ranges[$advisory->id()] = $advisory->affectedRange();
            }
        }
        $subject = new MoveSubject($finding->package(), $finding->version(), $finding->isDirect(), $finding->directDependents(), $floor->php(PhpFloor::TARGET), $floor->lowestAsString());
        $context = new MoveContext(
            $finding,
            $subject,
            $facts->metadata(),
            BranchRow::all($facts, $floor, $this->clock, null, $details->fixes()),
            $details->fixes(),
            $finder,
            $floor,
            $this->clock->now(),
            $this->signals->thresholds()->releaseWarnYears(),
            $this->signals->targetMajorGa(),
            $ranges,
            FixFinder::otherVersionRanges($facts->advisories(), $every),
            $details->advisoriesComplete()
        );

        return NextStep::plan($context, ThroughCheck::of($finding->package(), $finding->isDirect(), $finding->directDependents(), $lock, $metadata));
    }

    /**
     * The advisories that affect the installed version, as the score reads them.
     *
     * @param list<Advisory> $advisories
     *
     * @return list<CountedAdvisory>
     */
    private static function countedAdvisories(array $advisories, PackageFixes $found): array
    {
        $counted = [];
        foreach ($advisories as $advisory) {
            $counted[] = new CountedAdvisory($advisory->id(), $advisory->severity() ?? '', $found->forAdvisory($advisory->id())->kind());
        }

        return $counted;
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
