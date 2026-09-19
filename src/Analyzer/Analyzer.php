<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Allowlist\Allowlist;
use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Clock;
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
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Deadline;
use Lockrot\Graph\DependencyGraph;
use Lockrot\Lock\LockedPackage;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalSet;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\VerdictEngine;

/** The analysis itself: metadata, repository activity, signals and verdicts for a set of locked packages. */
final class Analyzer
{
    public const NOTE_NOT_IN_REPOSITORY = 'not from a Composer repository, not checked';

    /**
     * The note for a forge whose anonymous request cap shaped the run, so a zero-candidate run
     * does not read as a complete one. GitLab has no cap and no note.
     */
    private const ANONYMOUS_CAP_NOTE = [
        RepoRef::GITHUB => 'GitHub token not set: repository activity checked for %d candidate packages, %d packages skipped (set GITHUB_TOKEN to check all)',
        RepoRef::BITBUCKET => 'Bitbucket credentials not set: repository activity checked for %d candidate packages, %d packages skipped (add bitbucket.org credentials to auth.json to check all)',
    ];

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
    /** Null when the run has no advisory source at all; then nothing is asked and nothing is noted. */
    private ?AdvisoryLoaderInterface $advisories;

    public function __construct(MetadataLoaderInterface $metadata, ActivityClient $activity, ActivityFetchPlanner $planner, RepoLocator $locator, Allowlist $allowlist, SignalSet $signals, VerdictEngine $engine, Clock $clock, bool $offline, ?AdvisoryLoaderInterface $advisories = null)
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
        // Only the install-time path sets a budget; `composer lockrot` runs unbounded.
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
     * The budget that bounds an install-time run ({@see Deadline}). It reaches the activity half of
     * the analysis here and the repository half through the metadata loader, which takes its own
     * copy; both are given the same instance by {@see \Lockrot\Composer\ServiceFactory}.
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
     * Checks only $packages — the install-time path passes the transaction's packages, not the whole
     * lock. $lock, $project and $includeDev are still the full picture: they build the dependency
     * graph, so a package's "via" chain is resolved through every locked package, not only the
     * changed ones.
     *
     * @param list<LockedPackage> $packages
     */
    public function analyzePackages(array $packages, LockFile $lock, ProjectConfig $project, bool $includeDev): Report
    {
        return $this->analyzeWithFacts($packages, $lock, $project, $includeDev)->report();
    }

    /**
     * {@see analyzePackages()}, keeping the facts each finding was decided on ({@see Analysis}).
     * The same run, the same report: `--explain` prints exactly what the report would.
     *
     * @param list<LockedPackage> $packages
     */
    public function analyzeWithFacts(array $packages, LockFile $lock, ProjectConfig $project, bool $includeDev): Analysis
    {
        $graph = DependencyGraph::fromLock($lock, $project, $includeDev);
        $now = $this->clock->now();
        $notes = [];
        if ($this->offline) {
            $notes[] = "offline: repository metadata served from Composer's cache";
        }

        $batch = $this->fetchMetadata($packages);
        $metadata = $batch->metadata();
        $metadataFailed = $batch->failed();
        if ($metadataFailed !== []) {
            $notes[] = $this->metadataUnavailableNote($metadataFailed);
        }

        $advisories = $this->fetchAdvisories($packages);
        $notes = array_merge($notes, $advisories->notes());

        [$allowlisted, $repoByPackage, $candidateByPackage] = $this->classify($packages, $metadata, $now);

        if ($this->deadline->isPast()) {
            // The metadata pass already used the whole budget. Starting the forge round-trips now
            // would push the install past it, so the activity signals are dropped and the report
            // says so rather than reading as "checked, nothing found". Planning waits too: it may
            // exchange Bitbucket credentials over the network ({@see ForgeAuth}).
            $activityBatch = ActivityBatch::empty();
            $activityNotes = ['repository activity not checked: install-time budget exhausted'];
        } else {
            [$activityBatch, $activityNotes] = $this->fetchActivity($this->planner->select($repoByPackage, $candidateByPackage));
        }
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
            $facts = new PackageFacts($package, $meta, $act, $advisories->for($package->name()));
            $factsByPackage[$package->name()] = $facts;
            $findings[] = $this->buildFinding($facts, $entry, $graph, $batch);
            if (!$package->isFromComposerRepository()) {
                ++$notInRepository;
            }
        }
        $notes = array_merge($notes, $this->notInRepositoryNotes($notInRepository));
        $findings = TransitiveExposure::attach($findings, $graph);

        $hadNetworkFailures = $batch->failed() !== [] || $activityBatch->failed() !== [] || $advisories->hadNetworkFailure();

        return new Analysis(
            new Report($findings, $notes, $now, \count($packages), $notInRepository, $hadNetworkFailures, null, self::oldestCachedActivity($activity), $includeDev),
            $factsByPackage
        );
    }

    /**
     * The fetch time of the oldest activity answer that came from lockrot's cache, or null when
     * every answer was fetched in this run: what the report's footer states as the data's age.
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
     * The same names the metadata pass asks for, each with its locked version: a package outside
     * every Composer repository has no advisory feed either.
     *
     * @param list<LockedPackage> $packages
     */
    private function fetchAdvisories(array $packages): AdvisoryBatch
    {
        if ($this->advisories === null) {
            return AdvisoryBatch::empty();
        }
        $versionByName = [];
        foreach ($packages as $package) {
            if ($package->isFromComposerRepository()) {
                $versionByName[$package->name()] = $package->version();
            }
        }

        return $this->advisories->load($versionByName);
    }

    /**
     * @param list<LockedPackage>              $packages
     * @param array<string, PackageMetadata>   $metadata
     *
     * @return array{0: array<string, AllowlistEntry|null>, 1: array<string, RepoRef>, 2: array<string, bool>}
     */
    private function classify(array $packages, array $metadata, \DateTimeImmutable $now): array
    {
        $allowlisted = [];
        $repoByPackage = [];
        $candidateByPackage = [];
        foreach ($packages as $package) {
            $meta = $metadata[$package->name()] ?? null;
            $allowlisted[$package->name()] = $this->allowlist->match($package, $meta, $now);
            $repo = $this->locator->locate(($meta !== null ? $meta->repositoryUrl() : null) ?? $package->repositoryUrl());
            if ($repo === null || $allowlisted[$package->name()] !== null || !$package->isFromComposerRepository()) {
                continue;
            }
            $repoByPackage[$package->name()] = $repo;
            $first = $this->signals->evaluate(new PackageFacts($package, $meta, null));
            $candidateByPackage[$package->name()] = $this->hasSignal($first, Signal::S2) && !$this->hasSignal($first, Signal::S1);
        }

        return [$allowlisted, $repoByPackage, $candidateByPackage];
    }

    /** @return array{0: ActivityBatch, 1: list<string>} */
    private function fetchActivity(ActivityFetchPlan $plan): array
    {
        $batch = $this->activity->fetch($plan->repos());
        $notes = [];
        // Anonymously the planner both filters to candidates and caps the request count on the
        // forges that need it; the report states the total left unchecked per forge.
        foreach ($plan->cappedForges() as $forge) {
            $notes[] = \sprintf(self::ANONYMOUS_CAP_NOTE[$forge], $plan->checkedPackages($forge), $plan->skippedNoToken($forge) + $plan->skippedBudget($forge));
        }
        foreach (RepoRef::FORGES as $forge) {
            $failed = $batch->failedOn($forge);
            if ($batch->rateLimited($forge)) {
                $notes[] = \sprintf('%s API rate limit reached; repository activity missing for %d repositories', RepoRef::label($forge), \count($failed));
            } elseif ($failed !== []) {
                $notes[] = \sprintf('%s unreachable for %d repositories: %s', RepoRef::label($forge), \count($failed), (string) reset($failed));
            }
            // A 404 is an answer, not a failure — but a private repository looks exactly like a
            // healthy one without this line, so the report says it was not answered for.
            $notFound = $batch->notFoundOn($forge);
            if ($notFound !== []) {
                $notes[] = \sprintf('%s did not answer for %d repositories (private, renamed or removed); repository activity missing', RepoRef::label($forge), \count($notFound));
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
        $verdict = $this->engine->decide($signals, $entry !== null, $meta !== null);

        $note = null;
        if (!$package->isFromComposerRepository()) {
            $note = self::NOTE_NOT_IN_REPOSITORY;
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
            array_keys($graph->chainsTo($package->name()))
        );
    }

    /**
     * One reason across every failed package reads as "Repository metadata unavailable for N
     * packages: <reason>". Several distinct reasons each get their own count instead of only ever
     * naming the first one reached — reasons appear in the order {@see MetadataBatch::failed()}
     * reports them.
     *
     * @param array<string, string> $metadataFailed package name => reason
     */
    private function metadataUnavailableNote(array $metadataFailed): string
    {
        $label = \count($metadataFailed) === 1 ? '1 package' : \count($metadataFailed).' packages';

        $countByReason = [];
        foreach ($metadataFailed as $reason) {
            $countByReason[$reason] = ($countByReason[$reason] ?? 0) + 1;
        }
        if (\count($countByReason) === 1) {
            return \sprintf('Repository metadata unavailable for %s: %s', $label, (string) array_key_first($countByReason));
        }

        $parts = [];
        foreach ($countByReason as $reason => $reasonCount) {
            $parts[] = \sprintf('%s (%d)', $reason, $reasonCount);
        }

        return \sprintf('Repository metadata unavailable for %s: %s', $label, implode('; ', $parts));
    }

    /**
     * The offline and budget reasons already state why the metadata is missing, so prefixing them
     * would read as "Repository metadata unavailable: offline: ...". Every other reason is a bare
     * transport or repository message that needs the prefix to make sense on a finding.
     */
    private function metadataFailureNote(string $reason): string
    {
        $selfExplanatory = [MetadataLoaderInterface::OFFLINE_NOT_FOUND_REASON, MetadataLoaderInterface::BUDGET_REASON];

        return \in_array($reason, $selfExplanatory, true)
            ? $reason
            : 'Repository metadata unavailable: '.$reason;
    }

    /** @return list<string> */
    private function notInRepositoryNotes(int $notInRepository): array
    {
        if ($notInRepository === 0) {
            return [];
        }

        return [$notInRepository === 1
            ? '1 package is not from a Composer repository and was not checked'
            : \sprintf('%d packages are not from a Composer repository and were not checked', $notInRepository)];
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

    /** The newer of the two dates, or the one there is: null compares below any object, so max() is exactly that. */
    private function dataDate(?PackageMetadata $meta, ?RepositoryActivity $activity): ?\DateTimeImmutable
    {
        return max($meta !== null ? $meta->dataDate() : null, $activity !== null ? $activity->fetchedAt() : null);
    }
}
