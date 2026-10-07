<?php

declare(strict_types=1);

namespace Lockrot\Explain;

use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunNote;
use Lockrot\Data\Repository\InstalledRelease;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Data\Repository\RepositoryUrl;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Thresholds;
use Lockrot\Verdict\Finding;

/**
 * What it shows: docs/configuration.md#explaining-one-package. The finding is the report's and the
 * facts are the ones the rules read ({@see \Lockrot\Analyzer\Analysis}).
 *
 * @internal
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
    private ?string $projectPhp;
    /** Must be built from the same target and project php as S8's floor ({@see \Lockrot\Signal\SignalSet::default()}). */
    private PhpFloor $floor;

    /**
     * @param ?string $projectPhp the project's own `require.php` as composer.json writes it, the one
     *                            that S8 was given, null when the manifest has none
     */
    public function __construct(Finding $finding, PackageFacts $facts, Thresholds $thresholds, string $targetPhp, Report $report, ?string $projectPhp = null)
    {
        $this->finding = $finding;
        $this->facts = $facts;
        $this->thresholds = $thresholds;
        $this->targetPhp = $targetPhp;
        $this->report = $report;
        $this->projectPhp = $projectPhp;
        $this->floor = new PhpFloor($targetPhp, $projectPhp);
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

    public function projectPhp(): ?string
    {
        return $this->projectPhp;
    }

    public function installedBranch(): ?string
    {
        return ReleaseBranch::of($this->finding->version());
    }

    /**
     * Highest branch first. `highest_commit_date` is the commit date of a highest tag that shares its
     * commit with other tags, else null. It tells "no date" from "a date that is not the release's".
     * See docs/configuration.md#explaining-one-package.
     *
     * @return list<array{branch: string, installed: bool, highest: string, highest_released: ?\DateTimeImmutable, highest_commit_date: ?\DateTimeImmutable, newest_dated: string, newest_dated_released: ?\DateTimeImmutable, dated_by: ?string, php: ?string}>
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
                'php' => $release['php'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * The monorepo parent that dated some rows, with those branch labels.
     * See docs/verdicts.md#dates-from-the-monorepo.
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

    /** S8 does not measure a branch whose highest tag has no usable date. */
    public function installedBranchIsUndated(): bool
    {
        foreach ($this->branches() as $row) {
            if ($row['installed']) {
                return $row['highest_released'] === null;
            }
        }

        return false;
    }

    /**
     * The floor fields of a branch row (docs/verdicts.md#the-php-test-in-explain) are only here, not in
     * {@see self::branches()}: the text table does not print them and reads the rows more than once.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $package = $this->facts->package();
        $metadata = $this->facts->metadata();
        $activity = $this->facts->activity();
        $installed = InstalledRelease::of($package, $metadata);
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
                'php' => $row['php'],
                'admits_target_php' => $this->floor->admitsTarget($row['php']),
                'admits_project_php' => $this->floor->admitsProject($row['php']),
                'php_blocked_by' => $this->floor->blocking($row['php']),
                'misses_target_php' => $this->floor->missesTarget($row['php']),
                'misses_project_php' => $this->floor->missesProject($row['php']),
            ];
        }

        return [
            'package' => $package->name(),
            'version' => $package->version(),
            'finding' => $this->finding->toArray(),
            'lock' => [
                'php' => $package->requirePhp(),
                'released' => self::date($package->time()),
                'repository' => RepositoryUrl::shown($package->repositoryUrl()),
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
                // The installed end of the libyears, as {@see InstalledRelease::of()} dates it.
                'installed_release' => self::date($installed->at()),
                'installed_release_dated_by' => $installed->datedBy(),
                'repository' => RepositoryUrl::shown($metadata->repositoryUrl()),
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
            'project_php' => $this->projectPhp,
            'generated_at' => self::date($this->report->generatedAt()),
            'notes' => $this->report->notes(),
            'note_details' => array_map(static fn (RunNote $note): array => $note->toArray(), $this->report->runNotes()),
        ];
    }

    private static function date(?\DateTimeImmutable $date): ?string
    {
        return $date === null ? null : $date->format(\DATE_ATOM);
    }
}
