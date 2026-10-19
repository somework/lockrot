<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Allowlist\AcceptedCheck;
use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Data\Advisory\AdvisoryNameCoverage;
use Lockrot\Data\Advisory\IgnoredAdvisory;
use Lockrot\Data\Forge\ActivityLookup;
use Lockrot\Data\Repository\MetadataFailure;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Score\IfApplied;
use Lockrot\Security\BranchFixes;
use Lockrot\Security\Fix;
use Lockrot\Security\Holder;
use Lockrot\Security\PackageFixes;
use Lockrot\Security\Severity;
use Lockrot\Signal\AbandonedIgnored;
use Lockrot\Signal\Data\AdvisoryRow;
use Lockrot\Signal\Data\Check;
use Lockrot\Signal\Data\NotCheckedData;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Rule\AbandonedRule;
use Lockrot\Signal\Rule\AdvisoryRule;
use Lockrot\Signal\Rule\NotCheckedRule;
use Lockrot\Signal\Signal;
use Lockrot\Solver\MoveCheck;

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
    private Age $age;
    private ?AdvisoryNameCoverage $lookup;
    private ?AbandonedIgnored $abandonedIgnored;
    private ?Moves $moves = null;
    private ActivityLookup $activity;
    /** @var list<AcceptedCheck> */
    private array $uncheckedAccepted = [];

    /**
     * @param list<Check>                                                   $skipped
     * @param array{requires: ?string, target_runs: ?bool, project_allows: ?bool} $installedPhp
     * @param list<IgnoredAdvisory>                                         $ignored
     */
    public function __construct(string $metadataStatus, ?string $metadataReason, ?string $metadataMessage, array $skipped, array $installedPhp, ?AllowlistEntry $entry, string $check, ?string $uncheckedReason, array $ignored, ?PackageFixes $fixes, ActivityLookup $activity, Age $age, ?AdvisoryNameCoverage $lookup, ?AbandonedIgnored $abandonedIgnored)
    {
        $this->abandonedIgnored = $abandonedIgnored;
        $this->activity = $activity;
        $this->age = $age;
        $this->lookup = $lookup;
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
     * @param list<AcceptedCheck>     $accepted        the S10 entries that `accept-unchecked` accepted: each one is a skip
     */
    public static function of(PackageFacts $facts, ?AllowlistEntry $entry, PhpFloor $floor, array $ignored, array $signals, ?string $metadataFailure, Age $age, array $accepted = []): self
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
            self::skippedChecks($facts, $signals, $reason, $accepted),
            ['requires' => $requires, 'target_runs' => $requires === null ? null : $floor->admitsTarget($requires), 'project_allows' => $requires === null ? null : $floor->allowsProject($requires)],
            $entry,
            $check,
            $reason,
            $ignored,
            $facts->fixes(),
            $facts->activityLookup(),
            $age,
            $coverage,
            AbandonedRule::ignored($facts)
        );
        $details->releasesUnchecked = NotCheckedRule::releasesUnchecked($facts);
        $details->uncheckedAccepted = $accepted;

        return $details;
    }

    public function age(): Age
    {
        return $this->age;
    }

    /** The marking that Composer's abandoned ignore list kept out of S1, null when it kept none. */
    public function abandonedIgnored(): ?AbandonedIgnored
    {
        return $this->abandonedIgnored;
    }

    /** What the advisory lookup did for the package, null when the run had no lookup. */
    public function advisoryLookup(): ?AdvisoryNameCoverage
    {
        return $this->lookup;
    }

    public function withMoves(Moves $moves): self
    {
        $clone = clone $this;
        $clone->moves = $moves;

        return $clone;
    }

    public function moves(): Moves
    {
        return $this->moves ?? Moves::none();
    }

    /**
     * The holders and the replacement of every move and candidate, each with its own finding.
     *
     * @param array<string, GradedPackage> $findings by package name
     */
    public function withFindings(array $findings): self
    {
        $clone = clone $this;
        $clone->fixes = $this->fixes === null ? null : $this->fixes->withFindings($findings);
        $clone->moves = $this->moves === null ? null : $this->moves->withFindings($findings);

        return $clone;
    }

    public function activity(): ActivityLookup
    {
        return $this->activity;
    }

    /** The S10 entry for release data a counted advisory needed and did not get. */
    public function releasesUnchecked(): ?Check
    {
        return $this->releasesUnchecked;
    }

    /**
     * The checks that did not run and raise no S10: the repository activity that lockrot did not
     * read, unless S10 holds it; the archived flag that a read did not carry; the release metadata
     * that was not read, unless S10 holds it; S8 on a branch snapshot; the advisories of a package
     * the lookup scope or an unparseable version leaves out.
     *
     * @param list<Signal>        $signals
     * @param list<AcceptedCheck> $accepted
     *
     * @return list<Check>
     */
    private static function skippedChecks(PackageFacts $facts, array $signals, ?string $advisoryReason, array $accepted): array
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
        $acceptedSkips = [];
        foreach ($accepted as $check) {
            $acceptedSkips[$check->unchecked()->check()] = $check->skip();
        }
        $skipped = [];
        $lookup = $facts->activityLookup();
        if (isset($acceptedSkips['repository_activity'])) {
            $skipped[] = $acceptedSkips['repository_activity'];
        } elseif ($lookup->status() !== ActivityLookup::READ && !\in_array('repository_activity', $inS10, true)) {
            $skipped[] = new Check('repository_activity', (string) $lookup->reason(), [Signal::S3, Signal::S4]);
        }
        if ($lookup->archivedUnread() !== null) {
            $skipped[] = new Check('repository_archived', $lookup->archivedUnread(), [Signal::S3]);
        }
        if (isset($acceptedSkips['release_metadata'])) {
            $skipped[] = $acceptedSkips['release_metadata'];
        } elseif ($facts->metadataStatus() !== PackageFacts::METADATA_READ) {
            if (!\in_array('release_metadata', $inS10, true)) {
                $skipped[] = new Check('release_metadata', $facts->metadataStatus(), [Signal::S2, Signal::S8]);
            }
        } elseif (ReleaseBranch::of($facts->package()->version()) === null) {
            $skipped[] = new Check('release_branch', 'branch_snapshot', [Signal::S8]);
        }
        if (isset($acceptedSkips['advisories'])) {
            $skipped[] = $acceptedSkips['advisories'];
        } elseif (\in_array($advisoryReason, ['not_from_composer_repository', 'unparseable_version'], true)) {
            $skipped[] = new Check('advisories', (string) $advisoryReason, [Signal::S9]);
        }

        return $skipped;
    }

    /** @return list<AcceptedCheck> the S10 entries that `accept-unchecked` accepted, in S10's order */
    public function uncheckedAccepted(): array
    {
        return $this->uncheckedAccepted;
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

    /** Why the advisory lookup did not complete for the package, null on a complete lookup. */
    public function uncheckedReason(): ?string
    {
        return $this->uncheckedReason;
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
            'records' => $this->lookup === null ? null : $this->lookup->records(),
            'feeds' => $this->lookup === null ? [] : $this->lookup->feeds(),
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
            'installed_branch_fixes' => $installed === null ? null : $this->rowFixes($installed),
            'move_in' => $this->moves()->moveIn(),
            'gets' => $gets === null ? null : [
                'version' => $gets->version(),
                'php_check' => $gets->phpCheck(),
                'clears' => array_map(static fn (string $id): array => ['kind' => 'advisory', 'id' => $id, 'basis' => 'outside_range'], self::inS9Order($gets->clears(), $rows)),
                'clears_all' => $gets->clearsAll(),
            ],
            'partial' => $this->moves()->partial(),
        ];
    }

    /**
     * @param list<string>      $ids
     * @param list<AdvisoryRow> $rows
     *
     * @return list<string> $ids in S9 order: the feed's order changes between runs
     */
    private static function inS9Order(array $ids, array $rows): array
    {
        return array_values(array_intersect(array_map(static fn (AdvisoryRow $row): string => $row->id(), $rows), $ids));
    }

    /** @param list<AdvisoryRow> $rows */
    public function standing(array $rows): SecurityStanding
    {
        if ($rows === []) {
            return SecurityStanding::notVulnerable($this->check, \count($this->ignored), self::counts([]));
        }
        // With no move, `fix_kind` is the hardest kind of its advisories: FIX_KINDS runs easiest first.
        $fixKind = $this->moves()->fixKind();
        $order = array_flip(ScoreModel::FIX_KINDS);
        $hardest = max(array_map(static fn (AdvisoryRow $row): int => $order[$row->fix()->kind()], $rows));

        return SecurityStanding::vulnerable($this->check, \count($this->ignored), self::counts($rows), $fixKind ?? ScoreModel::FIX_KINDS[$hardest]);
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
     * A branch row's `fixes`, with the score after a move to its candidate and Composer's check of that move.
     *
     * @return array{fixed: int, unknown: int, of: int, fix_kind: ?string, lowest: ?string, newest: ?string, held_by: list<Holder>, if_applied: ?IfApplied, check: ?MoveCheck}
     */
    public function rowFixes(BranchFixes $row): array
    {
        return self::branchFixes($row, $this->moves()->rowIfApplied($row->branch()), $this->moves()->rowCheck($row->branch()));
    }

    /** @return array{fixed: int, unknown: int, of: int, fix_kind: ?string, lowest: ?string, newest: ?string, held_by: list<Holder>, if_applied: ?IfApplied, check: ?MoveCheck} */
    public static function branchFixes(BranchFixes $row, ?IfApplied $ifApplied, ?MoveCheck $check = null): array
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
            'if_applied' => $ifApplied,
            'check' => $check,
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
