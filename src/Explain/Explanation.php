<?php

declare(strict_types=1);

namespace Lockrot\Explain;

use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunNote;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Clock;
use Lockrot\Data\Repository\InstalledRelease;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Data\Repository\RepositoryUrl;
use Lockrot\Signal\BranchRow;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Thresholds;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FindingDetails;

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
        $rows = [];
        foreach ($this->rows() as $row) {
            $rows[] = [
                'branch' => $row->branch(),
                'installed' => $row->installed(),
                'highest' => $row->highest(),
                'highest_released' => $row->highestReleased(),
                'highest_commit_date' => $row->highestCommitDate(),
                'newest_dated' => $row->newestDated(),
                'newest_dated_released' => $row->newestDatedReleased(),
                'dated_by' => $row->datedBy(),
                'php' => $row->php(),
            ];
        }

        return $rows;
    }

    /** @return list<BranchRow> */
    private function rows(): array
    {
        return BranchRow::all($this->facts, $this->floor, new Clock($this->report->generatedAt()));
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
        foreach ($this->rows() as $row) {
            $branches[] = [
                'branch' => $row->branch(),
                'installed' => $row->installed(),
                'highest' => $row->highest(),
                'highest_released' => self::date($row->highestReleased()),
                'highest_commit_date' => self::date($row->highestCommitDate()),
                'newest_dated' => $row->newestDated(),
                'newest_dated_released' => self::date($row->newestDatedReleased()),
                'dated_by' => $row->datedBy(),
                'php' => $row->php(),
                'admits_target_php' => $row->admitsTargetPhp(),
                'admits_project_php' => $row->admitsProjectPhp(),
                'php_blocked_by' => $row->phpBlockedBy(),
                'misses_target_php' => $row->missesTargetPhp(),
                'misses_project_php' => $row->missesProjectPhp(),
            ] + $this->rowExtras($row);
        }

        $run = $this->report->run() ?? new RunSettings(null, null, $this->targetPhp, null, null, $this->thresholds, $this->projectPhp);
        $runKeys = ['target_php', 'target_php_source', 'project_php', 'project_php_lowest', 'include_dev', 'thresholds', 'flag_ids', 'verdicts', 'graded_verdicts', 'signal_ids', 'fail_on', 'fail_on_source', 'gates', 'fix_model', 'text_grammar', 'score_model'];
        $runArray = $run->toArray();
        $basis = $this->finding->priorityBasis();

        return [
            'package' => $package->name(),
            'version' => $package->version(),
            'finding' => $this->report->findingRows()[$package->name()] ?? $this->finding->toArray(),
            'lock' => [
                'php' => $package->requirePhp(),
                'released' => self::date($package->time()),
                'repository' => RepositoryUrl::shown($package->repositoryUrl()),
                'from_composer_repository' => $package->isFromComposerRepository(),
                'dev' => $package->isDev(),
                'branch_snapshot' => $package->isBranchSnapshot(),
                'type' => $package->type(),
            ],
            'metadata' => $metadata === null || $this->finding->details()->metadataStatus() !== PackageFacts::METADATA_READ ? null : [
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
                'installed_branch' => $this->installedBranch() === null ? null : ReleaseBranch::label((string) $this->installedBranch()),
            ],
            'activity' => $activity === null ? null : [
                'forge' => $activity->ref()->forgeLabel(),
                'repository' => $activity->repo(),
                'archived' => $activity->isArchived(),
                'pushed_at' => self::date($activity->pushedAt()),
                'fetched_at' => self::date($activity->fetchedAt()),
                'from_cache' => $activity->fromCache(),
            ],
            'run' => array_intersect_key($runArray, array_flip($runKeys)),
            'legacy' => ['verdict' => $this->finding->verdict(), 'priority' => $basis->priority(), 'basis' => $basis->toArray()],
            'generated_at' => self::date($this->report->generatedAt()),
            'notes' => $this->report->notes(),
            'note_details' => array_map(static fn (RunNote $note): array => $note->toArray(), $this->report->runNotes()),
        ];
    }

    /**
     * What a branch fixes of the counted advisories, null when none counts. The years are the age
     * of the branch's newest release on the run clock.
     *
     * @return array{released_years: int|float|null, fixes: array<string, mixed>|null}
     */
    private function rowExtras(BranchRow $row): array
    {
        $fixes = $this->finding->details()->fixes();
        $released = $row->highestReleased();
        $out = ['released_years' => $released === null ? null : (new Clock($this->report->generatedAt()))->tenthsSince($released) / 10, 'fixes' => null];
        if ($fixes !== null) {
            foreach ($fixes->branches() as $branch) {
                if ($branch->branch() === $row->branch()) {
                    $out['fixes'] = FindingDetails::branchFixes($branch);
                }
            }
        }

        return $out;
    }

    private static function date(?\DateTimeImmutable $date): ?string
    {
        return $date === null ? null : $date->format(\DATE_ATOM);
    }
}
