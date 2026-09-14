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
use Lockrot\Data\Packagist\PackageMetadata;
use Lockrot\Data\Packagist\PackagistBatch;
use Lockrot\Data\Packagist\PackagistClient;
use Lockrot\Graph\DependencyGraph;
use Lockrot\Lock\LockedPackage;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalSet;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\VerdictEngine;

final class Analyzer
{
    public const NOTE_NOT_ON_PACKAGIST = 'not on Packagist, not checked';

    private PackagistClient $packagist;
    private GitHubClient $github;
    private GitHubFetchPlanner $planner;
    private Allowlist $allowlist;
    private SignalSet $signals;
    private VerdictEngine $engine;
    private Clock $clock;

    public function __construct(PackagistClient $packagist, GitHubClient $github, GitHubFetchPlanner $planner, Allowlist $allowlist, SignalSet $signals, VerdictEngine $engine, Clock $clock)
    {
        $this->packagist = $packagist;
        $this->github = $github;
        $this->planner = $planner;
        $this->allowlist = $allowlist;
        $this->signals = $signals;
        $this->engine = $engine;
        $this->clock = $clock;
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

    public function analyze(LockFile $lock, ProjectConfig $project, bool $includeDev): Report
    {
        $packages = $lock->packages($includeDev);
        $graph = DependencyGraph::fromLock($lock, $project, $includeDev);
        $now = $this->clock->now();
        $notes = [];

        $batch = $this->fetchMetadata($packages);
        $metadata = $batch->metadata();
        $packagistFailed = $batch->failed();
        if ($packagistFailed !== []) {
            $notes[] = \sprintf('Packagist unreachable for %d packages: %s', \count($packagistFailed), (string) reset($packagistFailed));
        }

        [$allowlisted, $repoByPackage, $candidateByPackage] = $this->classify($packages, $metadata, $now);

        $plan = $this->planner->select($repoByPackage, $candidateByPackage);
        $repos = $plan->repos();
        $githubBatch = $this->fetchGitHub($plan, $repoByPackage !== [], $notes);
        $activity = $githubBatch->activity();

        $findings = [];
        $notOnPackagist = 0;
        foreach ($packages as $package) {
            $meta = $metadata[$package->name()] ?? null;
            $repo = $repoByPackage[$package->name()] ?? null;
            $act = $repo !== null ? ($activity[$repo] ?? null) : null;
            $checked = $repo !== null && \in_array($repo, $repos, true);
            $entry = $allowlisted[$package->name()];
            $findings[] = $this->buildFinding($package, $meta, $act, $checked, $entry, $graph, $batch);
            if (!$package->isOnPackagist()) {
                ++$notOnPackagist;
            }
        }
        $this->notes($notes, $notOnPackagist);

        $hadNetworkFailures = $batch->failed() !== [] || $githubBatch->failed() !== [];

        return new Report($findings, $notes, $now, \count($packages), $notOnPackagist, $hadNetworkFailures);
    }

    /** @param list<LockedPackage> $packages */
    private function fetchMetadata(array $packages): PackagistBatch
    {
        $onPackagist = [];
        foreach ($packages as $package) {
            if ($package->isOnPackagist()) {
                $onPackagist[] = $package->name();
            }
        }

        return $this->packagist->fetch($onPackagist);
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
            if ($repo === null || $allowlisted[$package->name()] !== null || !$package->isOnPackagist()) {
                continue;
            }
            $repoByPackage[$package->name()] = $repo;
            $first = $this->signals->evaluate(new PackageFacts($package, $meta, null, false));
            $candidateByPackage[$package->name()] = $this->hasSignal($first, Signal::S2) && !$this->hasSignal($first, Signal::S1);
        }

        return [$allowlisted, $repoByPackage, $candidateByPackage];
    }

    /**
     * @param bool         $anyGitHubRepo whether any package resolved to a GitHub repository at all
     * @param list<string> $notes
     */
    private function fetchGitHub(GitHubFetchPlan $plan, bool $anyGitHubRepo, array &$notes): GitHubBatch
    {
        $githubBatch = $this->github->fetch($plan->repos());
        // Without a token the planner both filters to candidates and caps the request count; the
        // report states the total left unchecked so a zero-candidate run does not look like a
        // complete one.
        if (!$this->github->hasToken() && $anyGitHubRepo) {
            $notes[] = \sprintf(
                'GitHub token not set: repository activity checked for %d candidate packages, %d packages skipped (set GITHUB_TOKEN to check all)',
                \count($plan->repos()),
                $plan->skippedNoToken() + $plan->skippedBudget()
            );
        }
        $githubFailed = $githubBatch->failed();
        if ($githubBatch->rateLimited()) {
            $notes[] = \sprintf('GitHub API rate limit reached; repository activity missing for %d packages', \count($githubFailed));
        } elseif ($githubFailed !== []) {
            $notes[] = \sprintf('GitHub unreachable for %d repositories: %s', \count($githubFailed), (string) reset($githubFailed));
        }

        return $githubBatch;
    }

    private function buildFinding(LockedPackage $package, ?PackageMetadata $meta, ?RepositoryActivity $activity, bool $checked, ?AllowlistEntry $entry, DependencyGraph $graph, PackagistBatch $batch): Finding
    {
        $facts = new PackageFacts($package, $meta, $activity, $checked);
        $signals = $this->signals->evaluate($facts);
        $verdict = $this->engine->decide($signals, $entry !== null, $meta !== null);

        $note = null;
        if (!$package->isOnPackagist()) {
            $note = self::NOTE_NOT_ON_PACKAGIST;
        } elseif ($meta === null && isset($batch->failed()[$package->name()])) {
            $note = 'Packagist data unavailable: '.$batch->failed()[$package->name()];
        } elseif ($meta === null) {
            $note = 'not found on Packagist';
        }

        return new Finding(
            $package->name(),
            $package->version(),
            $verdict,
            $signals,
            $graph->shortestChain($package->name()),
            $entry !== null ? $entry->reason() : null,
            $this->dataDate($meta, $activity),
            $note
        );
    }

    /** @param list<string> $notes */
    private function notes(array &$notes, int $notOnPackagist): void
    {
        if ($notOnPackagist > 0) {
            $notes[] = \sprintf('%d packages are not on Packagist and were not checked', $notOnPackagist);
        }
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

    private function dataDate(?PackageMetadata $meta, ?RepositoryActivity $activity): ?\DateTimeImmutable
    {
        $metaDate = $meta !== null ? $meta->dataDate() : null;
        $activityDate = $activity !== null ? $activity->fetchedAt() : null;
        if ($metaDate === null) {
            return $activityDate;
        }
        if ($activityDate === null) {
            return $metaDate;
        }

        return $activityDate > $metaDate ? $activityDate : $metaDate;
    }
}
