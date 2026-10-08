<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Data\Advisory\IgnoredAdvisory;
use Lockrot\Data\Repository\MetadataFailure;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Security\BranchFixes;
use Lockrot\Security\Fix;
use Lockrot\Security\PackageFixes;
use Lockrot\Security\Severity;
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
 *
 * @phpstan-type Check array{check: string, reason: string, blocks: list<string>}
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
    /** @var ?Check */
    private ?array $releasesUnchecked = null;

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
        $check = $reason === null ? 'complete' : ($facts->advisories() !== [] ? 'partial' : 'not_run');

        $details = new self(
            $status,
            $status === PackageFacts::METADATA_UNAVAILABLE && $metadataFailure !== null ? MetadataFailure::reason($metadataFailure) : null,
            $status === PackageFacts::METADATA_UNAVAILABLE ? $metadataFailure : null,
            self::skippedChecks($facts, $entry, $signals, $reason),
            ['requires' => $requires, 'target_runs' => $requires === null ? null : $floor->admitsTarget($requires), 'project_allows' => $requires === null ? null : $floor->admitsProject($requires)],
            $entry,
            $check,
            $reason,
            $ignored,
            $facts->fixes()
        );
        $details->releasesUnchecked = NotCheckedRule::releasesUnchecked($facts);

        return $details;
    }

    /** @return ?Check the S10 entry for release data a counted advisory needed and did not get */
    public function releasesUnchecked(): ?array
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
            if ($signal->id() === Signal::S10) {
                $gaps = $signal->data()['unchecked'] ?? [];
                foreach (\is_array($gaps) ? $gaps : [] as $gap) {
                    $inS10[] = \is_array($gap) ? ($gap['check'] ?? null) : null;
                }
            }
        }
        $skipped = [];
        if ($facts->activity() === null && !\in_array('repository_activity', $inS10, true)) {
            $activity = $facts->activityNotChecked();
            if ($activity === null) {
                $activity = $entry !== null && $entry->acceptsAll() ? 'allowlisted' : ($facts->package()->isFromComposerRepository() ? 'no_repository' : 'not_from_composer_repository');
            }
            $skipped[] = ['check' => 'repository_activity', 'reason' => $activity, 'blocks' => [Signal::S3, Signal::S4]];
        }
        if ($facts->metadataStatus() !== PackageFacts::METADATA_READ) {
            $skipped[] = ['check' => 'release_metadata', 'reason' => $facts->metadataStatus(), 'blocks' => [Signal::S2, Signal::S8]];
        } elseif (ReleaseBranch::of($facts->package()->version()) === null) {
            $skipped[] = ['check' => 'release_branch', 'reason' => 'branch_snapshot', 'blocks' => [Signal::S8]];
        }
        if (\in_array($advisoryReason, ['not_from_composer_repository', 'unparseable_version'], true)) {
            $skipped[] = ['check' => 'advisories', 'reason' => (string) $advisoryReason, 'blocks' => [Signal::S9]];
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
     * @param list<array<string, mixed>> $rows the S9 rows, each with its `severity` bucket and `fix`
     *
     * @return array<string, mixed>
     */
    public function security(array $rows, ?string $branch): array
    {
        $out = [
            'status' => $rows !== [] ? 'vulnerable' : ($this->check === 'complete' ? 'clear' : 'unchecked'),
            'check' => $this->check,
            'complete' => $this->check === 'complete',
            'unchecked_reason' => $this->uncheckedReason,
        ];
        $ignored = array_map([self::class, 'ignored'], $this->ignored);
        if ($rows === []) {
            return $out + ['ignored' => $ignored, 'ignored_count' => \count($ignored)];
        }
        $counts = self::counts($rows);
        // With no move, `fix_kind` is the hardest kind of its advisories: FIX_KINDS runs easiest first.
        $hardest = 0;
        foreach ($rows as $row) {
            $fix = \is_array($row['fix'] ?? null) ? $row['fix'] : [];
            $hardest = max($hardest, (int) array_search($fix['kind'] ?? Fix::UNKNOWN, ScoreModel::FIX_KINDS, true));
        }
        $worst = self::worst($counts);
        $installed = $this->fixes === null ? null : $this->fixes->installedBranch();
        $gets = $this->fixes === null ? null : $this->fixes->gets();

        return $out + [
            'worst' => $worst,
            'counts' => $counts,
            'ignored' => $ignored,
            'ignored_count' => \count($ignored),
            'fix_kind' => ScoreModel::FIX_KINDS[$hardest],
            'installed_branch_fixes' => $branch === null || $installed === null ? null : self::branchFixes($installed),
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

    /**
     * `flags[vulnerable].summary`, from the S9 rows and the installed branch row.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function vulnerableSummary(array $rows, ?string $branch, string $decidingSeverity): string
    {
        $counts = self::counts($rows);
        $installed = $branch === null || $this->fixes === null ? null : $this->fixes->installedBranch();

        return FlagSentence::vulnerable(
            $rows,
            $counts,
            self::worst($counts),
            $decidingSeverity,
            $branch,
            $installed === null ? null : ['fixed' => $installed->fixed(), 'unknown' => $installed->unknown(), 'of' => $installed->of(), 'fix_kind' => $installed->fixKind()]
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, int> every severity in display order
     */
    private static function counts(array $rows): array
    {
        $counts = array_fill_keys(Severity::DISPLAY_ORDER, 0);
        foreach ($rows as $row) {
            $severity = \is_string($row['severity'] ?? null) ? $row['severity'] : Severity::UNRATED;
            $counts[$severity] = ($counts[$severity] ?? 0) + 1;
        }

        return $counts;
    }

    /** @param array<string, int> $counts */
    private static function worst(array $counts): string
    {
        foreach (Severity::DISPLAY_ORDER as $severity) {
            if (($counts[$severity] ?? 0) > 0) {
                return $severity;
            }
        }

        return Severity::LOW;
    }

    /**
     * A branch row's `fixes`. The score after a move to its candidate waits for the move.
     *
     * @return array<string, mixed>
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
            'held_by' => $candidate === null ? [] : array_map([AdvisoryRule::class, 'heldBy'], $candidate->heldBy()),
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
        $title = $advisory->title();
        $cve = $advisory->cve();
        if ($title !== null) {
            $title = trim((string) preg_replace('/\s+/u', ' ', $title));
            $title = $cve !== null && strpos($title, $cve.': ') === 0 ? substr($title, \strlen($cve) + 2) : $title;
        }

        return [
            'id' => $advisory->id(),
            'cve' => $cve,
            'title' => $title,
            'severity' => Severity::fromComposer($advisory->severity())->bucket(),
            'by' => $ignored->match()->by(),
            'matched' => $ignored->match()->kind(),
            'reason' => $ignored->match()->reason(),
        ];
    }
}
