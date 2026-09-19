<?php

declare(strict_types=1);

namespace Lockrot\Explain;

use Lockrot\Analyzer\Report;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Thresholds;
use Lockrot\Verdict\Finding;

/**
 * Everything one finding was decided on, laid out for a developer asking "why is this flagged?" —
 * or "why is it not?": the finding with every signal's raw data, the lock entry, the repository
 * metadata with the release date of every branch, the repository activity, the thresholds the
 * signals were measured against, and the run's notes. Nothing here is computed anew: the finding
 * is the report's, the facts are the ones the rules read ({@see \Lockrot\Analyzer\Analysis}).
 */
final class Explanation
{
    /** Branch rows printed before the rest is counted: a 0.0.x package has a branch per patch. */
    public const BRANCH_ROWS = 15;

    private Finding $finding;
    private PackageFacts $facts;
    private Thresholds $thresholds;
    private string $targetPhp;
    private Report $report;

    public function __construct(Finding $finding, PackageFacts $facts, Thresholds $thresholds, string $targetPhp, Report $report)
    {
        $this->finding = $finding;
        $this->facts = $facts;
        $this->thresholds = $thresholds;
        $this->targetPhp = $targetPhp;
        $this->report = $report;
    }

    public function finding(): Finding
    {
        return $this->finding;
    }

    public function facts(): PackageFacts
    {
        return $this->facts;
    }

    public function thresholds(): Thresholds
    {
        return $this->thresholds;
    }

    public function targetPhp(): string
    {
        return $this->targetPhp;
    }

    public function report(): Report
    {
        return $this->report;
    }

    /** The installed version's release branch key ({@see ReleaseBranch::of()}), null for a branch snapshot. */
    public function installedBranch(): ?string
    {
        return ReleaseBranch::of($this->finding->version());
    }

    /**
     * The repository's release branches, highest first, each with its highest stable tag and that
     * tag's release date (null when the repository leaves it undated or dates it by a commit other
     * tags share — see {@see \Lockrot\Data\Repository\PackageMetadata::fromPackages()}), and the
     * branch's newest dated release; `installed` marks the branch the locked version is on.
     * `highest_commit_date` is the date such a shared-commit tag carried before it was set aside —
     * the branch's newest dated release *is* that tag, so the date it shows is the commit's — and
     * null for a tag that is dated as a release or not at all; what lets a reader tell "no date"
     * from "a date that is not the release's".
     *
     * @return list<array{branch: string, installed: bool, highest: string, highest_released: ?\DateTimeImmutable, highest_commit_date: ?\DateTimeImmutable, newest_dated: string, newest_dated_released: ?\DateTimeImmutable, dated_by: ?string}>
     */
    public function branches(): array
    {
        $metadata = $this->facts->metadata();
        if ($metadata === null) {
            return [];
        }
        $installed = $this->installedBranch();
        $byBranch = $metadata->latestStableByBranch();
        $keys = [];
        foreach (array_keys($byBranch) as $key) {
            $keys[] = (string) $key;
        }
        // Highest branch first, the keys ordered as the versions they are (`10` above `9`, `0.3` above `0.0.3`).
        usort($keys, static fn (string $a, string $b): int => version_compare($b, $a));
        $rows = [];
        foreach ($keys as $key) {
            $release = $byBranch[$key];
            $sharedCommit = $release['highest']['at'] === null && $release['version'] === $release['highest']['pretty'];
            $rows[] = [
                'branch' => ReleaseBranch::label($key),
                'installed' => $key === $installed,
                'highest' => $release['highest']['pretty'],
                'highest_released' => $release['highest']['at'],
                'highest_commit_date' => $sharedCommit ? $release['at'] : null,
                'newest_dated' => $release['version'],
                'newest_dated_released' => $release['at'],
                'dated_by' => $release['dated_by'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * The monorepo parent whose dates some branch rows carry, null when every row is this
     * package's own ({@see PackageMetadata::datedBy()}); with the branch labels it dated.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public function branchesDatedBy(): ?array
    {
        $parent = null;
        $branches = [];
        foreach ($this->branches() as $row) {
            if ($row['dated_by'] !== null) {
                $parent = $row['dated_by'];
                $branches[] = $row['branch'];
            }
        }

        return $parent === null ? null : [$parent, $branches];
    }

    /** Whether the installed branch's highest tag carries no usable date — the reason S8 does not measure it. */
    public function installedBranchIsUndated(): bool
    {
        foreach ($this->branches() as $row) {
            if ($row['installed']) {
                return $row['highest_released'] === null;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $package = $this->facts->package();
        $metadata = $this->facts->metadata();
        $activity = $this->facts->activity();
        $branches = [];
        foreach ($this->branches() as $row) {
            $branches[] = [
                'branch' => $row['branch'],
                'installed' => $row['installed'],
                'highest' => $row['highest'],
                'highest_released' => self::date($row['highest_released']),
                'highest_commit_date' => self::date($row['highest_commit_date']),
                'newest_dated' => $row['newest_dated'],
                'newest_dated_released' => self::date($row['newest_dated_released']),
                'dated_by' => $row['dated_by'],
            ];
        }

        return [
            'package' => $package->name(),
            'version' => $package->version(),
            'finding' => $this->finding->toArray(),
            'lock' => [
                'php' => $package->requirePhp(),
                'released' => self::date($package->time()),
                'repository' => $package->repositoryUrl(),
                'from_composer_repository' => $package->isFromComposerRepository(),
                'dev' => $package->isDev(),
                'branch_snapshot' => $package->isBranchSnapshot(),
                'type' => $package->type(),
            ],
            'metadata' => $metadata === null ? null : [
                'abandoned' => $metadata->isAbandoned(),
                'replacement' => $metadata->replacement(),
                'releases_listed' => $metadata->releaseCount(),
                'has_stable_release' => $metadata->hasStableRelease(),
                'last_stable_release' => self::date($metadata->lastStableReleaseAt()),
                'last_stable_version' => $metadata->lastStableVersion(),
                'last_stable_dated_by' => $metadata->lastStableDatedBy(),
                'repository' => $metadata->repositoryUrl(),
                'type' => $metadata->type(),
                'data_date' => self::date($metadata->dataDate()),
                'branches' => $branches,
            ],
            'activity' => $activity === null ? null : [
                'forge' => $activity->ref()->forgeLabel(),
                'repository' => $activity->repo(),
                'archived' => $activity->isArchived(),
                'pushed_at' => self::date($activity->pushedAt()),
                'fetched_at' => self::date($activity->fetchedAt()),
                'from_cache' => $activity->fromCache(),
            ],
            'thresholds' => [
                'release-warn-years' => $this->thresholds->releaseWarnYears(),
                'release-high-years' => $this->thresholds->releaseHighYears(),
                'push-warn-years' => $this->thresholds->pushWarnYears(),
                'push-high-years' => $this->thresholds->pushHighYears(),
            ],
            'target_php' => $this->targetPhp,
            'generated_at' => self::date($this->report->generatedAt()),
            'notes' => $this->report->notes(),
        ];
    }

    private static function date(?\DateTimeImmutable $date): ?string
    {
        return $date === null ? null : $date->format(\DATE_ATOM);
    }
}
