<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Data\Repository\StableRelease;
use Lockrot\Security\Candidate;
use Lockrot\Security\FixFinder;
use Lockrot\Security\PackageFixes;
use Lockrot\Signal\BranchRow;
use Lockrot\Signal\Data\AdvisoryData;
use Lockrot\Signal\Data\AdvisoryRow;
use Lockrot\Signal\Data\LeftBehindData;
use Lockrot\Signal\PhpCap;
use Lockrot\Signal\PhpFloor;

/**
 * What the moves of one finding read: the finding, its branch rows and releases, the release scan
 * and the run's floors and clock. The advisories that the finding does not count, on any version,
 * raise the lower bound of a maintenance move as they raise a security move's ({@see FixFinder}).
 *
 * @internal
 */
final class MoveContext
{
    private Finding $finding;
    private MoveSubject $subject;
    /** @var list<BranchRow> */
    private array $rows;
    private ?PackageMetadata $metadata;
    /** @var list<StableRelease>|null the stable releases above the installed one, ascending */
    private ?array $releases;
    private ?PackageFixes $fixes;
    private FixFinder $finder;
    private PhpFloor $floor;
    private \DateTimeImmutable $now;
    private int $warnYears;
    private ?\DateTimeImmutable $ga;
    /** @var array<string, ConstraintInterface> */
    private array $ranges;
    /** @var list<ConstraintInterface> */
    private array $own;
    private bool $advisoriesComplete;
    private ?MoveChecks $checks = null;
    private ?PhpCap $cap = null;

    /**
     * @param list<BranchRow>                    $rows      highest branch first
     * @param array<string, ConstraintInterface> $ranges    the affected range of each counted advisory that has one
     * @param list<ConstraintInterface>          $own       the ranges of the advisories on other versions
     * @param ?\DateTimeImmutable                $ga        the GA of the target PHP's major
     */
    public function __construct(Finding $finding, MoveSubject $subject, ?PackageMetadata $metadata, array $rows, ?PackageFixes $fixes, FixFinder $finder, PhpFloor $floor, \DateTimeImmutable $now, int $warnYears, ?\DateTimeImmutable $ga, array $ranges, array $own, bool $advisoriesComplete)
    {
        $this->metadata = $metadata;
        $releases = $metadata === null ? null : $metadata->releasesAbove();
        $this->finding = $finding;
        $this->subject = $subject;
        $this->rows = $rows;
        $this->releases = $releases;
        $this->fixes = $fixes;
        $this->finder = $finder;
        $this->floor = $floor;
        $this->now = $now;
        $this->warnYears = $warnYears;
        $this->ga = $ga;
        $this->ranges = $ranges;
        $this->own = $own;
        $this->advisoriesComplete = $advisoriesComplete;
    }

    /** A copy whose moves Composer's solver checks. Without it a move carries no check. */
    public function withChecks(MoveChecks $checks): self
    {
        $clone = clone $this;
        $clone->checks = $checks;

        return $clone;
    }

    public function checks(): ?MoveChecks
    {
        return $this->checks;
    }

    /** A copy that judges `capped` on the releases that its moves install. Without it no release is capped. */
    public function withPhpCap(PhpCap $cap): self
    {
        $clone = clone $this;
        $clone->cap = $cap;

        return $clone;
    }

    public function phpCap(): PhpCap
    {
        return $this->cap ?? PhpCap::none();
    }

    public function finding(): Finding
    {
        return $this->finding;
    }

    public function subject(): MoveSubject
    {
        return $this->subject;
    }

    /** @return list<BranchRow> highest branch first */
    public function rows(): array
    {
        return $this->rows;
    }

    public function installedRow(): ?BranchRow
    {
        foreach ($this->rows as $row) {
            if ($row->installed()) {
                return $row;
            }
        }

        return null;
    }

    public function row(string $branch): ?BranchRow
    {
        foreach ($this->rows as $row) {
            if ($row->branch() === $branch) {
                return $row;
            }
        }

        return null;
    }

    /** The newest stable release, null when lockrot read none. */
    public function lastStableVersion(): ?string
    {
        return $this->metadata === null ? null : $this->metadata->lastStableVersion();
    }

    public function lastStableReleased(): ?string
    {
        $at = $this->metadata === null ? null : $this->metadata->lastStableReleaseAt();

        return $at === null ? null : $at->format(\DATE_ATOM);
    }

    public function fixes(): ?PackageFixes
    {
        return $this->fixes;
    }

    public function floor(): PhpFloor
    {
        return $this->floor;
    }

    public function ga(): ?\DateTimeImmutable
    {
        return $this->ga;
    }

    public function advisoriesComplete(): bool
    {
        return $this->advisoriesComplete;
    }

    /** @return list<string> the counted advisories in S9's order: points, severity, then id */
    public function advisoryIds(): array
    {
        $s9 = $this->finding->signalData(AdvisoryData::class);

        return $s9 === null ? [] : array_map(static fn (AdvisoryRow $row): string => $row->id(), $s9->rows());
    }

    /** @return array<string, ConstraintInterface> */
    public function ranges(): array
    {
        return $this->ranges;
    }

    /** The installed branch as a label, or a branch-alias snapshot's own line (`5.x` for `5.4.x-dev`). */
    public function installedBranch(): ?string
    {
        $version = $this->subject->version();
        $key = ReleaseBranch::of(MoveText::isBranchAlias($version) ? substr($version, 0, -\strlen('.x-dev')) : $version);

        return $key === null ? null : ReleaseBranch::label($key);
    }

    /** Whether a row lies above the installed release: its newest release is higher. */
    public function isAbove(BranchRow $row): bool
    {
        $release = $this->release($row->highest());

        return $release !== null;
    }

    /** The `newest` of an update move: {@see PackageFixes::newestOnInstalledBranch()} on the installed row, else the row's newest. */
    public function updateTarget(BranchRow $row): string
    {
        $newest = $this->fixes === null || !$row->installed() ? null : $this->fixes->newestOnInstalledBranch();

        return $newest ?? $row->highest();
    }

    /**
     * The `newest` of a require, raise-php or blocked move. On the installed row it is the row's
     * `fixes.newest`, so the security move and the S9 fix agree (ruling R3-2). A move to $lower reads
     * it at $lower: no link in its own `held_by` lowers it (ruling R5-1). Else the row's newest.
     */
    public function rowTarget(BranchRow $row, ?Candidate $lower = null): string
    {
        $fixes = $this->fixes === null || !$row->installed() ? null : $this->fixes->installedBranch();
        if ($fixes === null) {
            return $row->highest();
        }
        if ($lower === null) {
            return $fixes->newest() ?? $row->highest();
        }

        return $this->finder->newestOf($this->subject->package(), $this->releases ?? [], $lower) ?? $row->highest();
    }

    /** The date of the row's newest dated release, as S8 dates a branch, or S8's date for the same branch when the row has none. */
    public function releasedAt(BranchRow $row): ?\DateTimeImmutable
    {
        if ($row->newestDatedReleased() !== null) {
            return $row->newestDatedReleased();
        }
        $s8 = $this->finding->signalData(LeftBehindData::class);

        return $s8 !== null && $s8->newestBranch() === $row->branch() ? new \DateTimeImmutable($s8->newestRelease()) : null;
    }

    public function isReleasing(?\DateTimeImmutable $at): bool
    {
        return $at !== null && $at > $this->now->modify('-'.$this->warnYears.' years');
    }

    /** A release above the installed one, by its version as the repository writes it. */
    public function release(string $pretty): ?StableRelease
    {
        foreach ($this->releases ?? [] as $release) {
            if ($release->pretty() === $pretty) {
                return $release;
            }
        }

        return null;
    }

    /**
     * The lowest release on $row that $constraint admits, with its class and holders, and the
     * constraint from it. The advisories on the package's other versions raise it. An advisory whose
     * range holds the branch's newest release reaches every constraint on the branch, so it does
     * not raise the release (SPEC §5.3).
     *
     * @return array{0: ?Candidate, 1: string}
     */
    public function lowestAdmitted(BranchRow $row, string $constraint): array
    {
        try {
            $admits = (new VersionParser())->parseConstraints($constraint);
        } catch (\UnexpectedValueException $e) {
            return [null, $constraint];
        }
        $onRow = [];
        foreach ($this->releases ?? [] as $release) {
            $branch = ReleaseBranch::of($release->normalized());
            $label = $branch === null ? null : ReleaseBranch::label($branch);
            if ($label === $row->branch() && $admits->matches(new Constraint('==', $release->normalized()))) {
                $onRow[] = $release;
            }
        }
        if ($onRow === []) {
            return [null, $constraint];
        }
        $newest = $onRow[\count($onRow) - 1];
        $own = array_filter($this->own, static fn (ConstraintInterface $range): bool => !self::insideAny($newest, [$range]));
        $from = \count($onRow);
        while ($from > 0 && !self::insideAny($onRow[$from - 1], $own)) {
            --$from;
        }
        if ($from === 0) {
            return [$this->candidate($onRow[0]), $constraint];
        }

        return [$this->candidate($onRow[$from]), '^'.ltrim($onRow[$from]->pretty(), 'vV')];
    }

    public function candidate(StableRelease $release): ?Candidate
    {
        return $this->finder->candidate($this->subject->package(), $release);
    }

    /** Whether some release from $lower up to $newest lies inside $range. */
    public static function reaches(ConstraintInterface $range, string $lower, string $newest): bool
    {
        $span = new MultiConstraint([new Constraint('>=', $lower), new Constraint('<=', $newest)]);

        return Intervals::haveIntersections($range, $span);
    }

    /** @param array<ConstraintInterface> $ranges */
    private static function insideAny(StableRelease $release, array $ranges): bool
    {
        $point = new Constraint('==', $release->normalized());
        foreach ($ranges as $range) {
            if (Intervals::haveIntersections($range, $point)) {
                return true;
            }
        }

        return false;
    }
}
