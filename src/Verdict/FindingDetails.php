<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Data\Advisory\IgnoredAdvisory;
use Lockrot\Data\Repository\MetadataFailure;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Security\BranchFixes;
use Lockrot\Security\Fix;
use Lockrot\Security\Holder;
use Lockrot\Security\PackageFixes;
use Lockrot\Security\Severity;
use Lockrot\Signal\Data\AdvisoryRow;
use Lockrot\Signal\Data\Check;
use Lockrot\Signal\Data\NotCheckedData;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Rule\AdvisoryRule;
use Lockrot\Signal\Rule\NotCheckedRule;
use Lockrot\Signal\Signal;

/**
 * The facts report-2 writes about a finding beside its signals: how the metadata, the advisory
 * lookup and each check went, what the installed release needs from PHP, and the release scan. The
 * analyzer takes them from the package's facts; a finding built without them reads them from its
 * own fields ({@see Finding::details()}).
 *
 * @internal
 */
final class FindingDetails
{
    private string $metadataStatus;
    private ?string $metadataReason;
    private ?string $metadataMessage;
    /** @var list<Check> */
    private array $skipped;
    /** @var array{requires: ?string, target_runs: ?bool, project_allows: ?bool} */
    private array $installedPhp;
    private ?AllowlistEntry $entry;
    private string $check;
    private ?string $uncheckedReason;
    /** @var list<IgnoredAdvisory> */
    private array $ignored;
    private ?PackageFixes $fixes;
    private ?Check $releasesUnchecked = null;

    /**
     * @param list<Check>                                                   $skipped
     * @param array{requires: ?string, target_runs: ?bool, project_allows: ?bool} $installedPhp
     * @param list<IgnoredAdvisory>                                         $ignored
     */
    public function __construct(string $metadataStatus, ?string $metadataReason, ?string $metadataMessage, array $skipped, array $installedPhp, ?AllowlistEntry $entry, string $check, ?string $uncheckedReason, array $ignored, ?PackageFixes $fixes)
    {
        $this->metadataStatus = $metadataStatus;
        $this->metadataReason = $metadataReason;
        $this->metadataMessage = $metadataMessage;
        $this->skipped = $skipped;
        $this->installedPhp = $installedPhp;
        $this->entry = $entry;
        $this->check = $check;
        $this->uncheckedReason = $uncheckedReason;
        $this->ignored = $ignored;
        $this->fixes = $fixes;
    }

    /**
     * @param list<IgnoredAdvisory> $ignored the advisories Composer's audit ignore lists keep out of S9
     * @param list<Signal>          $signals         the finding's signals: S10 tells a gap from a skip
     * @param ?string               $metadataFailure what the repository that lists the package answered instead of its metadata
     */
    public static function of(PackageFacts $facts, ?AllowlistEntry $entry, PhpFloor $floor, array $ignored, array $signals, ?string $metadataFailure): self
    {
        $package = $facts->package();
        $status = $facts->metadataStatus();
        $coverage = $facts->advisoryCoverage();
        $reason = $coverage === null ? 'composer_too_old' : $coverage->reason();
        $requires = $package->requirePhp();
        // A lookup that some feed answered is partial, whether or not that feed counted an advisory.
        $answered = $coverage !== null && $coverage->records() !== null;
        $check = $reason === null ? 'complete' : ($answered || $facts->advisories() !== [] ? 'partial' : 'not_run');

        $details = new self(
            $status,
            $status === PackageFacts::METADATA_UNAVAILABLE && $metadataFailure !== null ? MetadataFailure::reason($metadataFailure) : null,
            $status === PackageFacts::METADATA_UNAVAILABLE ? $metadataFailure : null,
            self::skippedChecks($facts, $entry, $signals, $reason),
            ['requires' => $requires, 'target_runs' => $requires === null ? null : $floor->admitsTarget($requires), 'project_allows' => $requires === null ? null : $floor->allowsProject($requires)],
            $entry,
            $check,
            $reason,
            $ignored,
            $facts->fixes()
        );
        $details->releasesUnchecked = NotCheckedRule::releasesUnchecked($facts);

        return $details;
    }

    /** The S10 entry for release data a counted advisory needed and did not get. */
    public function releasesUnchecked(): ?Check
    {
        return $this->releasesUnchecked;
    }

    /**
     * The checks that did not run and raise no S10: the repository activity of a package lockrot does
     * not ask about, or whose S10 a raised S1 or a whole-package entry drops; the release metadata
     * that was not read; S8 on a branch snapshot; the advisories of a package the lookup scope or an
     * unparseable version leaves out.
     *
     * @param list<Signal> $signals
     *
     * @return list<Check>
     */
    private static function skippedChecks(PackageFacts $facts, ?AllowlistEntry $entry, array $signals, ?string $advisoryReason): array
    {
        $inS10 = [];
        foreach ($signals as $signal) {
            $data = $signal->data();
            if ($data instanceof NotCheckedData) {
                foreach ($data->checks() as $gap) {
                    $inS10[] = $gap->check();
                }
            }
        }
        $skipped = [];
        if ($facts->activity() === null && !\in_array('repository_activity', $inS10, true)) {
            $activity = $facts->activityNotChecked();
            if ($activity === null) {
                $activity = $entry !== null && $entry->acceptsAll() ? 'allowlisted' : ($facts->package()->isFromComposerRepository() ? 'no_repository' : 'not_from_composer_repository');
            }
            $skipped[] = new Check('repository_activity', $activity, [Signal::S3, Signal::S4]);
        }
        if ($facts->metadataStatus() !== PackageFacts::METADATA_READ) {
            $skipped[] = new Check('release_metadata', $facts->metadataStatus(), [Signal::S2, Signal::S8]);
        } elseif (ReleaseBranch::of($facts->package()->version()) === null) {
            $skipped[] = new Check('release_branch', 'branch_snapshot', [Signal::S8]);
        }
        if (\in_array($advisoryReason, ['not_from_composer_repository', 'unparseable_version'], true)) {
            $skipped[] = new Check('advisories', (string) $advisoryReason, [Signal::S9]);
        }

        return $skipped;
    }

    public function metadataStatus(): string
    {
        return $this->metadataStatus;
    }

    /** @return array{status: string, reason: ?string, message: ?string} */
    public function metadata(): array
    {
        return ['status' => $this->metadataStatus, 'reason' => $this->metadataReason, 'message' => $this->metadataMessage];
    }

    /** @return list<Check> */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** @return array{requires: ?string, target_runs: ?bool, project_allows: ?bool} */
    public function installedPhp(): array
    {
        return $this->installedPhp;
    }

    public function entry(): ?AllowlistEntry
    {
        return $this->entry;
    }

    /** Whether every advisory feed answered for the package. */
    public function advisoriesComplete(): bool
    {
        return $this->check === 'complete';
    }

    /**
     * report-2's `security`: `vulnerable` with the counts, the fix and the release scan when an
     * advisory counts, else `clear` on a complete lookup and `unchecked` on any other.
     *
     * @param list<AdvisoryRow> $rows
     *
     * @return array<string, mixed>
     */
    public function toSecurityArray(array $rows, ?string $branch): array
    {
        $standing = $this->standing($rows);
        $out = [
            'status' => $standing->status(),
            'check' => $this->check,
            'complete' => $this->check === 'complete',
            'unchecked_reason' => $this->uncheckedReason,
        ];
        $ignored = array_map([self::class, 'ignored'], $this->ignored);
        if (!$standing->isVulnerable()) {
            return $out + ['ignored' => $ignored, 'ignored_count' => $standing->ignoredCount()];
        }
        $gets = $this->fixes === null ? null : $this->fixes->gets();
        $installed = $this->installedBranchFixes($rows, $branch);

        return $out + [
            'worst' => self::worst($standing->counts()),
            'counts' => $standing->counts(),
            'ignored' => $ignored,
            'ignored_count' => $standing->ignoredCount(),
            'fix_kind' => $standing->fixKind(),
            'installed_branch_fixes' => $installed === null ? null : self::branchFixes($installed),
            'move_in' => null,
            'gets' => $gets === null ? null : [
                'version' => $gets->version(),
                'php_check' => $gets->phpCheck()->toArray(),
                'clears' => array_map(static fn (string $id): array => ['kind' => 'advisory', 'id' => $id, 'basis' => 'outside_range'], $gets->clears()),
                'clears_all' => $gets->clearsAll(),
            ],
            'partial' => null,
        ];
    }

    /** @param list<AdvisoryRow> $rows */
    public function standing(array $rows): SecurityStanding
    {
        if ($rows === []) {
            return SecurityStanding::notVulnerable($this->check, \count($this->ignored), self::counts([]));
        }
        // With no move, `fix_kind` is the hardest kind of its advisories: FIX_KINDS runs easiest first.
        $hardest = 0;
        foreach ($rows as $row) {
            $hardest = max($hardest, (int) array_search($row->fix()->kind(), ScoreModel::FIX_KINDS, true));
        }

        return SecurityStanding::vulnerable($this->check, \count($this->ignored), self::counts($rows), ScoreModel::FIX_KINDS[$hardest]);
    }

    /**
     * `flags[vulnerable].summary`, from the S9 rows and the installed branch row.
     *
     * @param list<AdvisoryRow> $rows the deciding one marked
     */
    public function vulnerableSummary(array $rows, ?string $branch, string $decidingSeverity): string
    {
        $counts = self::counts($rows);

        return FlagSentence::vulnerable($rows, $counts, self::worst($counts), $decidingSeverity, $branch, $this->installedBranchFixes($rows, $branch));
    }

    /**
     * The installed branch's `fixes`, null only for a branch snapshot. With no release data the
     * scan has no branch row, so the S9 rows give it: unread release data gives no fix (SPEC §5.3).
     *
     * @param list<AdvisoryRow> $rows
     */
    private function installedBranchFixes(array $rows, ?string $branch): ?BranchFixes
    {
        if ($branch === null) {
            return null;
        }
        $installed = $this->fixes === null ? null : $this->fixes->installedBranch();
        if ($installed !== null) {
            return $installed;
        }
        $clears = [];
        $unknown = 0;
        foreach ($rows as $row) {
            if ($row->fix()->onInstalledBranch() === true) {
                $clears[] = $row->id();
            }
            $unknown += $row->fix()->kind() === Fix::UNKNOWN ? 1 : 0;
        }

        return BranchFixes::installedFromRows($branch, $clears, $unknown, \count($rows));
    }

    /**
     * @param list<AdvisoryRow> $rows
     *
     * @return array<string, int> every severity in display order
     */
    private static function counts(array $rows): array
    {
        $counts = array_fill_keys(Severity::DISPLAY_ORDER, 0);
        foreach ($rows as $row) {
            $counts[$row->severity()] = ($counts[$row->severity()] ?? 0) + 1;
        }

        return $counts;
    }

    /** @param array<string, int> $counts every severity, from rows that hold at least one */
    private static function worst(array $counts): string
    {
        return Severity::worstOf(array_keys(array_filter($counts))) ?? Severity::LOW;
    }

    /**
     * A branch row's `fixes`. The score after a move to its candidate waits for the move.
     *
     * @return array{fixed: int, unknown: int, of: int, fix_kind: ?string, lowest: ?string, newest: ?string, held_by: list<Holder>, if_applied: null}
     */
    public static function branchFixes(BranchFixes $row): array
    {
        $candidate = $row->candidate();

        return [
            'fixed' => $row->fixed(),
            'unknown' => $row->unknown(),
            'of' => $row->of(),
            'fix_kind' => $row->fixKind(),
            'lowest' => $row->lowest(),
            'newest' => $row->newest(),
            'held_by' => $candidate === null ? [] : $candidate->heldBy(),
            'if_applied' => null,
        ];
    }

    public function fixes(): ?PackageFixes
    {
        return $this->fixes;
    }

    /** @return array<string, mixed> */
    private static function ignored(IgnoredAdvisory $ignored): array
    {
        $advisory = $ignored->advisory();

        return [
            'id' => $advisory->id(),
            'cve' => $advisory->cve(),
            'title' => AdvisoryRule::title($advisory->cve(), $advisory->title()),
            'severity' => Severity::fromComposer($advisory->severity())->bucket(),
            'by' => $ignored->match()->by(),
            'matched' => $ignored->match()->kind(),
            'reason' => $ignored->match()->reason(),
        ];
    }
}
