<?php

declare(strict_types=1);

namespace Lockrot\Explain;

use Composer\Semver\Comparator;
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
     * tag's date (null when the repository leaves it undated or dates it by a commit other tags
     * share — see {@see \Lockrot\Data\Repository\PackageMetadata::fromPackages()}), and the branch's
     * newest dated release; `installed` marks the branch the locked version is on.
     *
     * @return list<array{branch: string, installed: bool, highest: string, highest_released: ?\DateTimeImmutable, newest_dated: string, newest_dated_released: ?\DateTimeImmutable}>
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
        // Highest branch first: Comparator orders the keys as the versions they are (`10` above `9`, `0.3` above `0.0.3`).
        usort($keys, static fn (string $a, string $b): int => Comparator::greaterThan($a, $b) ? -1 : (Comparator::greaterThan($b, $a) ? 1 : 0));
        $rows = [];
        foreach ($keys as $key) {
            $release = $byBranch[$key];
            $rows[] = [
                'branch' => ReleaseBranch::label($key),
                'installed' => $key === $installed,
                'highest' => $release['highest']['pretty'],
                'highest_released' => $release['highest']['at'],
                'newest_dated' => $release['version'],
                'newest_dated_released' => $release['at'],
            ];
        }

        return $rows;
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
                'newest_dated' => $row['newest_dated'],
                'newest_dated_released' => self::date($row['newest_dated_released']),
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
