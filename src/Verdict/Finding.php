<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Composer\Package\Loader\ValidatingArrayLoader;
use Lockrot\Analyzer\Libyears;
use Lockrot\Analyzer\LibyearsMeasurement;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Legacy\NoFix013;
use Lockrot\Legacy\Priority013;
use Lockrot\Legacy\PriorityBasis013;
use Lockrot\Legacy\Verdict013;
use Lockrot\Lock\PackageOrigin;
use Lockrot\Score\ScoreBasis;
use Lockrot\Signal\Signal;

/** @internal */
final class Finding
{
    /**
     * The verdicts under which nobody fixes an advisory upstream. `pinned` and `old-promise` are absent:
     * a branch snapshot or an open php constraint says nothing about a coming fix.
     */
    public const NO_FIX_VERDICTS = [Verdict::ABANDONED, Verdict::SILENT, Verdict::LEFT_BEHIND];

    /** The note for a lock entry with no notification-url: no repository was asked. */
    public const NOTE_NOT_IN_REPOSITORY = 'not from a Composer repository, not checked';

    /** Must match the signals that {@see VerdictEngine} reads for each verdict. */
    private const DECIDING = [
        Verdict::ABANDONED => [Signal::S1, Signal::S3],
        Verdict::SILENT => [Signal::S2, Signal::S4],
        Verdict::PINNED => [Signal::S6],
        Verdict::LEFT_BEHIND => [Signal::S8],
        Verdict::OLD_PROMISE => [Signal::S5],
        Verdict::STALE => [Signal::S2, Signal::S4],
    ];

    private string $package;
    private string $version;
    private string $verdict;
    /** @var list<Signal> */
    private array $signals;
    /** @var list<string> */
    private array $chain;
    private ?string $allowlistReason;
    private ?\DateTimeImmutable $dataDate;
    private ?string $note;
    /** True for `packages-dev` in the lock. */
    private bool $dev;
    /**
     * Sorted by name, with the package itself when it is direct. Empty exactly when the chain is.
     *
     * @var list<string>
     */
    private array $directDependents;
    /** Kept unrounded: the report's totals must sum what was measured, not what was printed. */
    private LibyearsMeasurement $libyears;
    /** `from_composer_repository` is read from its kind. */
    private PackageOrigin $origin;
    /** The registry that named the replacement, which decides where it is linked. */
    private ?string $replacementNamedBy;
    /** Null for a finding built without its flags. */
    private ?Score $score = null;
    private ?string $grade = null;

    /**
     * @param list<Signal> $signals
     * @param list<string> $chain
     * @param list<string> $directDependents
     * @param bool         $maintenanceJudged lockrot read the release metadata
     */
    public function __construct(string $package, string $version, string $verdict, array $signals, array $chain, ?string $allowlistReason, ?\DateTimeImmutable $dataDate, ?string $note = null, bool $dev = false, array $directDependents = [], ?LibyearsMeasurement $libyears = null, ?PackageOrigin $origin = null, ?string $replacementNamedBy = null, ?FlagSet $flags = null, bool $maintenanceJudged = true)
    {
        $origin ??= PackageOrigin::unattributed();
        $fromComposerRepository = $origin->isComposerRepository();
        // The note says that no repository was asked. A caller that forgets the flag contradicts it.
        if ($note === self::NOTE_NOT_IN_REPOSITORY && $fromComposerRepository) {
            throw new \InvalidArgumentException(\sprintf('%s is noted as not from a Composer repository, so it cannot be from one.', $package));
        }
        $this->package = $package;
        $this->version = $version;
        $this->verdict = $verdict;
        $this->signals = $signals;
        $this->chain = $chain;
        $this->allowlistReason = $allowlistReason;
        $this->dataDate = $dataDate;
        $this->note = $note;
        $this->dev = $dev;
        $this->directDependents = $directDependents;
        // No measurement means no dates were compared, or no repository was asked.
        $libyears = $libyears ?? LibyearsMeasurement::unmeasured($fromComposerRepository ? Libyears::NO_STABLE_RELEASE_DATE : Libyears::NOT_FROM_COMPOSER_REPOSITORY);
        // Libyears::measure() reads the flag first, so its first reason and the flag always agree.
        $unmeasuredAsNotAsked = $libyears->unmeasuredReason() === Libyears::NOT_FROM_COMPOSER_REPOSITORY;
        if ($unmeasuredAsNotAsked === $fromComposerRepository) {
            throw new \InvalidArgumentException(\sprintf('%s: a package goes unmeasured as not from a Composer repository exactly when it is not from one.', $package));
        }
        $this->libyears = $libyears;
        $this->origin = $origin;
        $this->replacementNamedBy = $replacementNamedBy;
        if ($flags !== null) {
            $this->score = Score::of($flags, self::reachOf($chain), $dev);
            $this->grade = ScoreBasis::verdict($this->score, $flags, $maintenanceJudged);
        }
    }

    /** @param list<string> $chain */
    private static function reachOf(array $chain): string
    {
        if (\count($chain) === 1) {
            return Score::DIRECT;
        }

        return $chain === [] ? Score::UNREACHED : Score::TRANSITIVE;
    }

    /**
     * A copy with $signals as its whole signal list, so that S7 can join after every verdict is
     * known. The verdict does not change: it comes from the original signals.
     *
     * @param list<Signal> $signals
     */
    public function withSignals(array $signals): self
    {
        $clone = clone $this;
        $clone->signals = $signals;

        return $clone;
    }

    public function package(): string
    {
        return $this->package;
    }

    public function version(): string
    {
        return $this->version;
    }

    /** The cause word that report-1 writes. */
    public function verdict(): string
    {
        return $this->verdict;
    }

    /** The verdict of the score: a grade, else `finished`, `unknown` or `ok`. */
    public function grade(): string
    {
        if ($this->grade === null) {
            throw $this->unscored();
        }

        return $this->grade;
    }

    /** The first counted maintenance flag, null when none counts. */
    public function lead(): ?string
    {
        return $this->score()->lead();
    }

    public function isGraded(): bool
    {
        return $this->score()->grade() !== null;
    }

    /** @throws \LogicException for a finding built without its flags */
    public function score(): Score
    {
        if ($this->score === null) {
            throw $this->unscored();
        }

        return $this->score;
    }

    private function unscored(): \LogicException
    {
        return new \LogicException(\sprintf('%s was built without its flags, so it has no score.', $this->package));
    }

    /** @return list<Signal> */
    public function signals(): array
    {
        return $this->signals;
    }

    /** @return list<string> */
    public function chain(): array
    {
        return $this->chain;
    }

    public function allowlistReason(): ?string
    {
        return $this->allowlistReason;
    }

    public function dataDate(): ?\DateTimeImmutable
    {
        return $this->dataDate;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function isDirect(): bool
    {
        return \count($this->chain) === 1;
    }

    public function isDev(): bool
    {
        return $this->dev;
    }

    public function isFromComposerRepository(): bool
    {
        return $this->origin->isComposerRepository();
    }

    public function origin(): PackageOrigin
    {
        return $this->origin;
    }

    /** @return list<string> */
    public function directDependents(): array
    {
        return $this->directDependents;
    }

    /** Unrounded years: docs/verdicts.md#libyears. */
    public function libyears(): ?float
    {
        return $this->libyears->years();
    }

    /** One of {@see Libyears::REASONS}, null exactly when {@see libyears()} is a number. */
    public function libyearsUnmeasured(): ?string
    {
        return $this->libyears->unmeasuredReason();
    }

    /**
     * The direct requirements that the chain does not name. Removing the chain's root from
     * composer.json leaves the package installed through any of them.
     *
     * @return list<string>
     */
    public function otherDirectDependents(): array
    {
        $root = $this->chain[0] ?? null;

        return array_values(array_filter($this->directDependents, static fn (string $name): bool => $name !== $root));
    }

    /** Derived, never stored: the priority is a view of the verdict, the chain, the dev flag and S9. */
    public function priority(): string
    {
        return $this->priorityBasis()->priority();
    }

    public function priorityBasis(): PriorityBasis013
    {
        return Priority013::basis($this->verdict, $this->isDirect(), $this->dev, $this->hasUnfixableAdvisory(), $this->chain !== []);
    }

    /** Rules: docs/verdicts.md#security-advisories. */
    public function hasUnfixableAdvisory(): bool
    {
        return ($list = $this->noFixExpected()) !== null && $list !== [];
    }

    /**
     * Null, empty and non-empty: docs/schema.md#advisories-with-no-fix-expected.
     *
     * @return ?list<array{id: string, reason: string}>
     */
    public function noFixExpected(): ?array
    {
        if (!\in_array($this->verdict, self::NO_FIX_VERDICTS, true) || !Verdict013::flagged($this->verdict)) {
            return null;
        }
        // Only S9 knows whether a null fixed_by was looked for. Without the key, nothing says that it was.
        $releasesRead = ($this->advisoryData()['releases_read'] ?? false) === true;
        $list = [];
        foreach ($this->advisoryRows() as $row) {
            $fixed = $this->verdict === Verdict::LEFT_BEHIND ? ($row['fixed_on_branch'] ?? false) === true : ($row['fixed_by'] ?? null) !== null;
            if (!$fixed) {
                $list[] = ['id' => $row['id'], 'reason' => $this->noFixReason($row, $releasesRead)];
            }
        }

        return $list;
    }

    /** @param array<mixed, mixed> $row */
    private function noFixReason(array $row, bool $releasesRead): string
    {
        if ($this->verdict === Verdict::LEFT_BEHIND && ($row['fixed_by'] ?? null) !== null) {
            return NoFix013::NOT_ON_INSTALLED_BRANCH;
        }
        if (!$releasesRead) {
            return NoFix013::RELEASES_UNKNOWN;
        }
        if (($row['affected_versions'] ?? null) === null) {
            return NoFix013::AFFECTED_RANGE_UNKNOWN;
        }

        return NoFix013::NO_RELEASE_FIXES;
    }

    /** The clause texts: docs/verdicts.md#security-advisories. */
    private function noFixClause(): ?string
    {
        $list = $this->noFixExpected();
        if ($list === null || $list === []) {
            return null;
        }
        if (\in_array(NoFix013::NOT_ON_INSTALLED_BRANCH, array_column($list, 'reason'), true)) {
            $branch = ReleaseBranch::of($this->version);

            return $branch === null ? 'no fix expected' : 'no fix expected on '.ReleaseBranch::label($branch);
        }
        // The successor, not the raw marker: only a package name is somewhere to migrate to.
        if (($replacement = $this->successor()) !== null) {
            return 'no fix expected; migrate to '.$replacement;
        }

        return 'no fix expected';
    }

    /**
     * The repository's replacement is free text: only a Composer package name other than this package's
     * own counts as a successor. The `migrate to` clause, the JSON `replacement` and the
     * `with_replacement` count all read it and must agree. See docs/verdicts.md#abandoned-and-where-to.
     */
    public function successor(): ?string
    {
        if ($this->verdict !== Verdict::ABANDONED) {
            return null;
        }
        return self::successorOf($this->package, $this->replacement());
    }

    /** The replacement when it names another Composer package, else null. */
    public static function successorOf(string $package, ?string $replacement): ?string
    {
        if ($replacement === null || strpos($replacement, '/') === false || ValidatingArrayLoader::hasPackageNamingError($replacement) !== null) {
            return null;
        }
        // Composer reads a package name without case, and so do the repositories that write them.
        if (strcasecmp($replacement, $package) === 0) {
            return null;
        }
        return $replacement;
    }

    private function replacementUrl(): ?string
    {
        $successor = $this->successor();

        return $successor === null ? null : PackageOrigin::replacementPage($this->replacementNamedBy, $successor);
    }

    private function replacement(): ?string
    {
        foreach ($this->signals as $signal) {
            if ($signal->id() === Signal::S1) {
                $replacement = $signal->data()['replacement'] ?? null;

                return \is_string($replacement) && $replacement !== '' ? $replacement : null;
            }
        }

        return null;
    }

    /**
     * Only a direct requirement gets the clause: a transitive package's parent owns its requirement.
     * See docs/verdicts.md#left-behind.
     */
    private function followClause(Signal $s8): ?string
    {
        if ($this->verdict !== Verdict::LEFT_BEHIND || !$this->isDirect()) {
            return null;
        }
        $constraint = $s8->data()['suggested_constraint'] ?? null;

        return \is_string($constraint) && $constraint !== '' ? 'require '.$constraint.' to follow' : null;
    }

    /**
     * A row that is not an array, or has no string id, counts as no advisory.
     *
     * @return list<array{id: string}&array<mixed, mixed>>
     */
    private function advisoryRows(): array
    {
        $rows = [];
        $list = $this->advisoryData()['advisories'] ?? [];
        foreach (\is_array($list) ? $list : [] as $row) {
            if (\is_array($row) && \is_string($row['id'] ?? null)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function advisoryData(): array
    {
        foreach ($this->signals as $signal) {
            if ($signal->id() === Signal::S9) {
                return $signal->data();
            }
        }

        return [];
    }

    /** Every signal but S7, the deciding ones ({@see self::DECIDING}) first. The note when there is none. */
    public function ownEvidence(): string
    {
        $parts = [];
        foreach ($this->ownSignalsDecidingFirst() as $signal) {
            $parts[] = $signal->summary();
            if ($signal->id() === Signal::S8 && ($clause = $this->followClause($signal)) !== null) {
                $parts[] = $clause;
            }
            if ($signal->id() === Signal::S9 && ($clause = $this->noFixClause()) !== null) {
                $parts[] = $clause;
            }
        }
        if ($parts === []) {
            return $this->note ?? '';
        }

        return implode('; ', $parts);
    }

    /** @return list<Signal> */
    private function ownSignalsDecidingFirst(): array
    {
        $deciding = self::DECIDING[$this->verdict] ?? [];
        $first = [];
        $rest = [];
        foreach ($this->signals as $signal) {
            if ($signal->id() === Signal::S7) {
                continue;
            }
            if (\in_array($signal->id(), $deciding, true)) {
                $first[] = $signal;
            } else {
                $rest[] = $signal;
            }
        }

        return array_merge($first, $rest);
    }

    /** S7 does not replace the note: a path package can pull in flagged packages and stay unchecked. */
    public function evidence(): string
    {
        $parts = [];
        $own = $this->ownEvidence();
        if ($own !== '') {
            $parts[] = $own;
        }
        foreach ($this->signals as $signal) {
            if ($signal->id() === Signal::S7) {
                $parts[] = $signal->summary();
            }
        }

        return implode('; ', $parts);
    }

    public function evidenceLine(): string
    {
        if ($this->allowlistReason === null) {
            return $this->evidence();
        }
        $evidence = $this->evidence();

        return ($evidence === '' ? '' : $evidence.'; ').'allowlisted: '.$this->allowlistReason;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $signals = [];
        foreach ($this->signals as $signal) {
            $signals[] = ['id' => $signal->id(), 'level' => $signal->level(), 'summary' => $signal->summary(), 'data' => $signal->data()];
        }
        $libyears = $this->libyears->years();

        return [
            'package' => $this->package, 'version' => $this->version, 'verdict' => $this->verdict,
            'priority' => $this->priority(), 'direct' => $this->isDirect(), 'dev' => $this->dev,
            'from_composer_repository' => $this->isFromComposerRepository(),
            'origin' => $this->origin->toArray(),
            'replacement' => $this->successor(),
            'replacement_url' => $this->replacementUrl(),
            'signals' => $signals, 'chain' => $this->chain, 'direct_dependents' => $this->directDependents,
            'evidence' => $this->evidence(),
            'allowlist_reason' => $this->allowlistReason, 'note' => $this->note,
            'data_date' => $this->dataDate === null ? null : $this->dataDate->format(\DATE_ATOM),
            'libyears' => $libyears === null ? null : round($libyears, 2),
            'libyears_unmeasured' => $this->libyears->unmeasuredReason(),
            'priority_basis' => $this->priorityBasis()->toArray(),
            'no_fix_expected' => $this->noFixExpected(),
        ];
    }
}
