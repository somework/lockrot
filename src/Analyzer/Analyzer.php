<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Allowlist\Allowlist;
use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Clock;
use Lockrot\Data\GitHub\GitHubBatch;
use Lockrot\Data\GitHub\GitHubClient;
use Lockrot\Data\GitHub\GitHubFetchPlan;
use Lockrot\Data\GitHub\GitHubFetchPlanner;
use Lockrot\Data\GitHub\RepoLocator;
use Lockrot\Data\GitHub\RepositoryActivity;
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

    private MetadataLoaderInterface $metadata;
    private GitHubClient $github;
    private GitHubFetchPlanner $planner;
    private Allowlist $allowlist;
    private SignalSet $signals;
    private VerdictEngine $engine;
    private Clock $clock;
    private bool $offline;
    private Deadline $deadline;

    public function __construct(MetadataLoaderInterface $metadata, GitHubClient $github, GitHubFetchPlanner $planner, Allowlist $allowlist, SignalSet $signals, VerdictEngine $engine, Clock $clock, bool $offline)
    {
        $this->metadata = $metadata;
        $this->github = $github;
        $this->planner = $planner;
        $this->allowlist = $allowlist;
        $this->signals = $signals;
        $this->engine = $engine;
        $this->clock = $clock;
        $this->offline = $offline;
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
     * The budget that bounds an install-time run ({@see Deadline}). It reaches the GitHub half of
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

        [$allowlisted, $repoByPackage, $candidateByPackage] = $this->classify($packages, $metadata, $now);

        $plan = $this->planner->select($repoByPackage, $candidateByPackage);
        if ($this->deadline->isPast()) {
            // The metadata pass already used the whole budget. Starting the GitHub round-trips now
            // would push the install past it, so the activity signals are dropped and the report
            // says so rather than reading as "checked, nothing found".
            $githubBatch = GitHubBatch::empty();
            $githubNotes = ['repository activity not checked: install-time budget exhausted'];
        } else {
            [$githubBatch, $githubNotes] = $this->fetchGitHub($plan, $repoByPackage !== []);
        }
        $notes = array_merge($notes, $githubNotes);
        $activity = $githubBatch->activity();

        $findings = [];
        $notInRepository = 0;
        foreach ($packages as $package) {
            $meta = $metadata[$package->name()] ?? null;
            $repo = $repoByPackage[$package->name()] ?? null;
            $act = $repo !== null ? ($activity[$repo] ?? null) : null;
            $entry = $allowlisted[$package->name()];
            $findings[] = $this->buildFinding($package, $meta, $act, $entry, $graph, $batch);
            if (!$package->isFromComposerRepository()) {
                ++$notInRepository;
            }
        }
        $notes = array_merge($notes, $this->notInRepositoryNotes($notInRepository));
        $findings = TransitiveExposure::attach($findings, $graph);

        $hadNetworkFailures = $batch->failed() !== [] || $githubBatch->failed() !== [];

        return new Report($findings, $notes, $now, \count($packages), $notInRepository, $hadNetworkFailures);
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
     * @param list<LockedPackage>              $packages
     * @param array<string, PackageMetadata>   $metadata
     *
     * @return array{0: array<string, AllowlistEntry|null>, 1: array<string, string>, 2: array<string, bool>}
     */
    private function classify(array $packages, array $metadata, \DateTimeImmutable $now): array
    {
        $allowlisted = [];
        $repoByPackage = [];
        $candidateByPackage = [];
        foreach ($packages as $package) {
            $meta = $metadata[$package->name()] ?? null;
            $allowlisted[$package->name()] = $this->allowlist->match($package, $meta, $now);
            $repo = RepoLocator::github($meta !== null ? ($meta->sourceUrl() ?? $package->sourceUrl()) : $package->sourceUrl());
            if ($repo === null || $allowlisted[$package->name()] !== null || !$package->isFromComposerRepository()) {
                continue;
            }
            $repoByPackage[$package->name()] = $repo;
            $first = $this->signals->evaluate(new PackageFacts($package, $meta, null));
            $candidateByPackage[$package->name()] = $this->hasSignal($first, Signal::S2) && !$this->hasSignal($first, Signal::S1);
        }

        return [$allowlisted, $repoByPackage, $candidateByPackage];
    }

    /**
     * @param bool $anyGitHubRepo whether any package resolved to a GitHub repository at all
     *
     * @return array{0: GitHubBatch, 1: list<string>}
     */
    private function fetchGitHub(GitHubFetchPlan $plan, bool $anyGitHubRepo): array
    {
        $githubBatch = $this->github->fetch($plan->repos());
        $notes = [];
        // Without a token the planner both filters to candidates and caps the request count; the
        // report states the total left unchecked so a zero-candidate run does not look like a
        // complete one.
        if (!$this->github->hasToken() && $anyGitHubRepo) {
            $notes[] = \sprintf(
                'GitHub token not set: repository activity checked for %d candidate packages, %d packages skipped (set GITHUB_TOKEN to check all)',
                $plan->checkedPackages(),
                $plan->skippedNoToken() + $plan->skippedBudget()
            );
        }
        $githubFailed = $githubBatch->failed();
        if ($githubBatch->rateLimited()) {
            $notes[] = \sprintf('GitHub API rate limit reached; repository activity missing for %d packages', \count($githubFailed));
        } elseif ($githubFailed !== []) {
            $notes[] = \sprintf('GitHub unreachable for %d repositories: %s', \count($githubFailed), (string) reset($githubFailed));
        }

        return [$githubBatch, $notes];
    }

    private function buildFinding(LockedPackage $package, ?PackageMetadata $meta, ?RepositoryActivity $activity, ?AllowlistEntry $entry, DependencyGraph $graph, MetadataBatch $batch): Finding
    {
        $facts = new PackageFacts($package, $meta, $activity);
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
