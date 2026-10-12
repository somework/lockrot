<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Composer\Package\Loader\ValidatingArrayLoader;
use Lockrot\Analyzer\Libyears;
use Lockrot\Analyzer\LibyearsMeasurement;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Legacy\AdvisoryFacts013;
use Lockrot\Legacy\NoFix013;
use Lockrot\Legacy\Priority013;
use Lockrot\Legacy\PriorityBasis013;
use Lockrot\Legacy\Verdict013;
use Lockrot\Lock\PackageOrigin;
use Lockrot\Score\MaintenanceTerm;
use Lockrot\Score\ScoreBasis;
use Lockrot\Score\ScoreContext;
use Lockrot\Score\SecurityTerm;
use Lockrot\Signal\Data\AbandonedData;
use Lockrot\Signal\Data\AdvisoryData;
use Lockrot\Signal\Data\AdvisoryRow;
use Lockrot\Signal\Data\Check;
use Lockrot\Signal\Data\LeftBehindData;
use Lockrot\Signal\Data\NoPushData;
use Lockrot\Signal\Data\NoReleaseData;
use Lockrot\Signal\Data\NotCheckedData;
use Lockrot\Signal\Data\OldPromiseData;
use Lockrot\Signal\Data\PinnedData;
use Lockrot\Signal\Data\SignalData;
use Lockrot\Signal\Rule\NotCheckedRule;
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
    /** report-1's S9 facts, which the report-1 readers read: S9's data is report-2's. */
    private AdvisoryFacts013 $advisoryFacts013;
    /** Null for a finding built without its flags. */
    private ?Score $score = null;
    private ?string $grade = null;
    private ?FlagSet $flags = null;
    private bool $maintenanceJudged = true;
    private ?FindingDetails $details = null;

    /**
     * @param list<Signal> $signals
     * @param list<string> $chain
     * @param list<string> $directDependents
     */
    public function __construct(string $package, string $version, string $verdict, array $signals, array $chain, ?string $allowlistReason, ?\DateTimeImmutable $dataDate, ?string $note = null, bool $dev = false, array $directDependents = [], ?LibyearsMeasurement $libyears = null, ?PackageOrigin $origin = null, ?string $replacementNamedBy = null, ?AdvisoryFacts013 $advisoryFacts013 = null)
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
        $this->advisoryFacts013 = $advisoryFacts013 ?? AdvisoryFacts013::none();
    }

    public function advisoryFacts013(): AdvisoryFacts013
    {
        return $this->advisoryFacts013;
    }

    /**
     * @param bool $maintenanceJudged lockrot read the release metadata
     *
     * @throws \InvalidArgumentException when the finding's details say otherwise
     */
    public function withFlags(FlagSet $flags, bool $maintenanceJudged): self
    {
        if ($this->details !== null) {
            self::assertJudgement($this->details, $maintenanceJudged);
        }
        $clone = clone $this;
        $clone->score = Score::of($flags, self::reachOf($this->chain), $this->dev);
        $clone->grade = ScoreBasis::verdict($clone->score, $flags, $maintenanceJudged);
        $clone->flags = $flags;
        $clone->maintenanceJudged = $maintenanceJudged;

        return $clone;
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

    /** The verdict that report-1 writes. */
    public function verdict(): string
    {
        return $this->verdict;
    }

    /**
     * The verdict of the score: a grade, else `finished`, `unknown` or `ok`.
     *
     * @throws \LogicException for a finding built without its flags
     */
    public function grade(): string
    {
        if ($this->grade === null) {
            throw $this->unscored();
        }

        return $this->grade;
    }

    /**
     * The first counted maintenance flag, null when none counts.
     *
     * @throws \LogicException for a finding built without its flags
     */
    public function lead(): ?string
    {
        return $this->score()->lead();
    }

    /**
     * The counted flags in flag order: the maintenance terms, then `vulnerable` when an advisory counts.
     *
     * @return list<string>
     *
     * @throws \LogicException for a finding built without its flags
     */
    public function flagIds(): array
    {
        $score = $this->score();
        $ids = array_map(static fn (MaintenanceTerm $term): string => $term->flag(), $score->maintenanceTerms());
        if ($score->deciding() !== null) {
            $ids[] = FlagSet::VULNERABLE;
        }

        return $ids;
    }

    /** Whether lockrot read the release metadata, so S2 and S8 ran. */
    public function isMaintenanceJudged(): bool
    {
        return $this->maintenanceJudged;
    }

    /** The move `next_step` publishes, null at score 0 and for a finding built without its details. */
    public function nextStep(): ?Move
    {
        return $this->details === null ? null : $this->details->moves()->nextStep();
    }

    /** @throws \LogicException for a finding built without its details */
    public function withMoves(Moves $moves): self
    {
        $clone = clone $this;
        $clone->details = $this->details()->withMoves($moves);

        return $clone;
    }

    /**
     * A copy whose holders and replacement name their own findings, as S7 joins once every
     * finding is graded ({@see withSignals()}).
     *
     * @param array<string, GradedPackage> $findings by package name
     *
     * @throws \LogicException for a finding built without its details
     */
    public function withHolders(array $findings): self
    {
        $clone = clone $this;
        $clone->details = $this->details()->withFindings($findings);
        $clone->signals = array_map(static function (Signal $signal) use ($findings): Signal {
            $data = $signal->data();

            return $data instanceof AdvisoryData ? new Signal($signal->level(), $signal->summary(), $data->withFindings($findings)) : $signal;
        }, $this->signals);

        return $clone;
    }

    /** @throws \LogicException for a finding built without its flags */
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

    /** @throws \LogicException for a finding built without its flags */
    public function flags(): FlagSet
    {
        if ($this->flags === null) {
            throw $this->unscored();
        }

        return $this->flags;
    }

    /**
     * report-2's `priority`: the grade, else `none`.
     *
     * @throws \LogicException for a finding built without its flags
     */
    public function gradeOrNone(): string
    {
        return $this->isGraded() ? $this->grade() : 'none';
    }

    private function unscored(): \LogicException
    {
        return new \LogicException(\sprintf('%s was built without its flags, so it has no score.', $this->package));
    }

    /**
     * The rules' signals. Their S9 rows mark no deciding advisory, and their S10 lacks the release
     * data that a counted advisory needed: {@see self::writtenSignals()} adds both.
     *
     * @return list<Signal>
     */
    public function signals(): array
    {
        return $this->signals;
    }

    /**
     * The data of the rules' signal of that class ({@see self::signals()}), null when it did not fire.
     *
     * @template T of SignalData
     *
     * @param class-string<T> $class
     *
     * @return ?T
     */
    public function signalData(string $class): ?SignalData
    {
        return self::dataIn($this->signals, $class);
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

    /** The years that report-2 writes, rounded to two decimals. */
    public function libyearsRounded(): ?float
    {
        $years = $this->libyears->years();

        return $years === null ? null : round($years, 2);
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

        return $this->advisoryFacts013->noFixExpected($this->verdict);
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

    /**
     * report-2's `replacement`: the successor, when `abandoned` counts.
     *
     * @throws \LogicException for a finding built without its flags
     */
    public function countedSuccessor(): ?string
    {
        return \in_array(FlagSet::ABANDONED, $this->flagIds(), true) ? self::successorOf($this->package, $this->replacement()) : null;
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

    /** S1's replacement as the repository writes it, free text. Null when S1 names none. */
    public function replacement(): ?string
    {
        $s1 = $this->signalData(AbandonedData::class);
        $replacement = $s1 === null ? null : $s1->replacement();

        return $replacement !== null && $replacement !== '' ? $replacement : null;
    }

    /**
     * Only a direct requirement gets the clause: a transitive package's parent owns its requirement.
     * See docs/verdicts.md#left-behind.
     */
    private function followClause(): ?string
    {
        $s8 = $this->signalData(LeftBehindData::class);
        $reachable = $s8 === null ? null : $s8->reachable();
        if ($this->verdict !== Verdict::LEFT_BEHIND || !$this->isDirect() || $reachable === null) {
            return null;
        }
        $constraint = $reachable->suggestedConstraint();

        return $constraint !== null && $constraint !== '' ? 'require '.$constraint.' to follow' : null;
    }

    /** Every signal but S7, the deciding ones ({@see self::DECIDING}) first. The note when there is none. */
    public function ownEvidence(): string
    {
        $parts = [];
        foreach ($this->ownSignalsDecidingFirst() as $signal) {
            $parts[] = $signal->summary();
            if ($signal->id() === Signal::S8 && ($clause = $this->followClause()) !== null) {
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

    /**
     * A copy with the facts report-2 writes beside the signals.
     *
     * @throws \InvalidArgumentException when the metadata status contradicts the flags' judgement
     */
    public function withDetails(FindingDetails $details): self
    {
        if ($this->score !== null) {
            self::assertJudgement($details, $this->maintenanceJudged);
        }
        $clone = clone $this;
        $clone->details = $details;

        return $clone;
    }

    /** @throws \LogicException for a finding built without the analyzer's facts */
    public function details(): FindingDetails
    {
        if ($this->details === null) {
            throw new \LogicException('The finding of '.$this->package.' was built without its details.');
        }

        return $this->details;
    }

    /** Maintenance is judged exactly when the repository metadata was read. */
    private static function assertJudgement(FindingDetails $details, bool $maintenanceJudged): void
    {
        if (($details->metadataStatus() === 'read') !== $maintenanceJudged) {
            throw new \InvalidArgumentException(\sprintf('Metadata status "%s" contradicts maintenance_judged %s.', $details->metadataStatus(), $maintenanceJudged ? 'true' : 'false'));
        }
    }

    /**
     * report-2's finding object without `rank`, `baseline` and `gate`, which the report writes.
     *
     * @return array<string, mixed>
     *
     * @throws \LogicException for a finding built without its flags
     */
    public function toArray(): array
    {
        $details = $this->details();
        $flags = $this->flags();
        $signals = $this->writtenSignals();
        $checksMissing = self::checksOf($signals);
        $context = $this->context($details, $checksMissing);
        $branchKey = ReleaseBranch::of($this->version);
        $branch = $branchKey === null ? null : ReleaseBranch::label($branchKey);
        $basis = $this->scoreBasisOf($context);
        $rows = self::rowsOf($signals);
        $summaries = $this->flagSummaries($flags, $basis, $signals, $rows, $branch);
        $counted = $this->flagIds();
        $evidence = [];
        foreach ($summaries as $flag => $summary) {
            if (\in_array($flag, $counted, true)) {
                $evidence[] = \sprintf('%s: %s', $flag, $summary);
            }
        }
        $entry = $details->entry();
        $successor = $this->countedSuccessor();

        return [
            'package' => $this->package,
            'version' => $this->version,
            'branch' => $branch,
            'installed_php' => $details->installedPhp(),
            'verdict' => $this->grade(),
            'priority' => $this->gradeOrNone(),
            'lead' => $this->lead(),
            'flags' => $this->flagsOut($flags, $basis, $signals, $summaries, \count($rows), $context->livenessComplete()),
            'score' => $basis,
            'next_step' => $details->moves()->nextStep(),
            'security' => $details->toSecurityArray($rows, $branch),
            'checks_missing' => $checksMissing,
            'checks_skipped' => $details->skipped(),
            'maintenance_judged' => $this->maintenanceJudged,
            'metadata' => $details->metadata(),
            'activity' => $details->activity(),
            'allowlist' => $entry === null ? null : $entry->toArray(),
            'from_composer_repository' => $this->isFromComposerRepository(),
            'origin' => $this->origin->toArray(),
            'replacement' => $successor,
            'replacement_url' => $successor === null ? null : PackageOrigin::replacementPage($this->replacementNamedBy, $successor),
            'note' => $this->note,
            'libyears_unmeasured' => $this->libyears->unmeasuredReason(),
            'direct' => $this->isDirect(),
            'dev' => $this->dev,
            'reach' => self::reachOf($this->chain),
            'chain' => $this->chain,
            'direct_dependents' => $this->directDependents,
            'signals' => $signals,
            'evidence' => implode('; ', $evidence),
            'allowlist_reason' => $entry !== null && $entry->acceptsAll() ? $entry->reason() : null,
            'data_date' => $this->dataDate === null ? null : $this->dataDate->format(\DATE_ATOM),
            'libyears' => $this->libyearsRounded(),
        ];
    }

    /**
     * `finding.score`, worded with the checks that did not run.
     *
     * @throws \LogicException for a finding built without its flags or its details
     */
    public function scoreBasis(): ScoreBasis
    {
        return $this->scoreBasisOf($this->context($this->details(), self::checksOf($this->writtenSignals())));
    }

    private function scoreBasisOf(ScoreContext $context): ScoreBasis
    {
        return ScoreBasis::of($this->flags(), self::reachOf($this->chain), $this->dev, $context);
    }

    /**
     * Where the finding stands on security, from its S9 rows.
     *
     * @throws \LogicException for a finding built without its details
     */
    public function securityStanding(): SecurityStanding
    {
        return $this->details()->standing(self::rowsOf($this->writtenSignals()));
    }

    /**
     * @param list<Check> $checksMissing S10's checks as report-2 writes them
     */
    private function context(FindingDetails $details, array $checksMissing): ScoreContext
    {
        $livenessComplete = true;
        $s3Unread = false;
        foreach (array_merge($details->skipped(), $checksMissing) as $check) {
            $livenessComplete = $livenessComplete && array_intersect($check->blocks(), [Signal::S2, Signal::S4]) === [];
            $s3Unread = $s3Unread || \in_array(Signal::S3, $check->blocks(), true);
        }

        return new ScoreContext($this->maintenanceJudged, $details->advisoriesComplete(), $livenessComplete, $s3Unread, ReleaseBranch::of($this->version) === null && !$this->maintenanceJudged);
    }

    /**
     * The signals as report-2 writes them. S9 marks the advisory that the score's security term
     * counts, so the row and the term cannot name two advisories. S10 gains the release data a
     * counted advisory needed and did not get.
     *
     * @return list<Signal>
     *
     * @throws \LogicException for a finding built without its details
     */
    public function writtenSignals(): array
    {
        $deciding = $this->score === null ? null : $this->score->deciding();
        $out = [];
        foreach ($this->signals as $signal) {
            $data = $signal->data();
            $out[] = $data instanceof AdvisoryData ? new Signal($signal->level(), $signal->summary(), $data->withDeciding($deciding === null ? null : $deciding->advisory())) : $signal;
        }
        $releases = $this->details()->releasesUnchecked();
        if ($releases === null) {
            return $out;
        }
        foreach ($out as $i => $signal) {
            $data = $signal->data();
            if ($data instanceof NotCheckedData) {
                $out[$i] = new Signal($signal->level(), $signal->summary().'; '.NotCheckedRule::RELEASES_WORDS, new NotCheckedData(array_merge($data->checks(), [$releases])));

                return $out;
            }
        }
        $out[] = new Signal(Signal::LEVEL_INFO, NotCheckedRule::RELEASES_WORDS, new NotCheckedData([$releases]));

        return $out;
    }

    /**
     * Each fired flag's sentence, in flag order.
     *
     * @param list<Signal>      $signals
     * @param list<AdvisoryRow> $rows
     *
     * @return array<string, string>
     */
    private function flagSummaries(FlagSet $flags, ScoreBasis $basis, array $signals, array $rows, ?string $branch): array
    {
        $data = array_map(static fn (Signal $signal): SignalData => $signal->data(), $signals);
        $security = $basis->securityTerm();
        $summaries = [];
        foreach ($flags->fired() as $flag) {
            $summaries[$flag] = $flag === FlagSet::VULNERABLE
                ? $this->details()->vulnerableSummary($rows, $branch, $security === null ? '' : $security->severity())
                : FlagSentence::maintenance($flag, $data);
        }

        return $summaries;
    }

    /**
     * @param list<Signal>          $signals
     * @param array<string, string> $summaries each fired flag's sentence
     *
     * @return list<array<string, mixed>>
     */
    private function flagsOut(FlagSet $flags, ScoreBasis $basis, array $signals, array $summaries, int $advisories, bool $livenessComplete): array
    {
        $roles = [];
        foreach ($basis->maintenanceTerms() as $term) {
            $roles[$term->flag()] = $term->role();
        }
        $fired = array_map(static fn (Signal $signal): string => $signal->id(), $signals);
        $out = [];
        foreach ($flags->fired() as $flag) {
            if ($flag === FlagSet::VULNERABLE) {
                $out[] = ['id' => $flag, 'role' => SecurityTerm::ROLE, 'baseline' => null, 'signal_ids' => [Signal::S9], 'degree' => null, 'headline' => ['unit' => 'advisories', 'value' => $advisories, 'source' => null], 'summary' => $summaries[$flag]];
                continue;
            }
            $out[] = [
                'id' => $flag,
                'role' => $roles[$flag] ?? 'accepted',
                'baseline' => null,
                'signal_ids' => array_values(array_intersect(FlagSentence::SIGNALS[$flag], $fired)),
                'degree' => $flag === FlagSet::ABANDONED ? ['reasons' => $flags->reasons(), 'liveness_complete' => $livenessComplete] : (\in_array($flag, [FlagSet::SILENT, FlagSet::STALE], true) ? ['liveness_complete' => $livenessComplete] : null),
                'headline' => self::headline($flag, $flags, $signals),
                'summary' => $summaries[$flag],
            ];
        }

        return $out;
    }

    /**
     * @param list<Signal> $signals
     *
     * @return array{unit: string, value: int|float|string|null, source: ?string}
     */
    private static function headline(string $flag, FlagSet $flags, array $signals): array
    {
        switch ($flag) {
            case FlagSet::ABANDONED:
                return ['unit' => 'reason', 'value' => \in_array(FlagSet::ARCHIVED, $flags->reasons(), true) ? 'archived' : 'marked', 'source' => null];
            case FlagSet::PINNED:
                $s6 = self::dataIn($signals, PinnedData::class);

                return ['unit' => 'reason', 'value' => $s6 === null ? null : $s6->reason(), 'source' => null];
            case FlagSet::LEFT_BEHIND:
                $s8 = self::dataIn($signals, LeftBehindData::class);

                return ['unit' => 'years', 'value' => $s8 === null ? null : $s8->years(), 'source' => 'branch'];
            case FlagSet::OLD_PROMISE:
                $s5 = self::dataIn($signals, OldPromiseData::class);

                return ['unit' => 'php', 'value' => $s5 === null ? null : $s5->writtenForPhp(), 'source' => null];
            default:
                $s2 = self::dataIn($signals, NoReleaseData::class);
                $s4 = self::dataIn($signals, NoPushData::class);
                $release = $s2 === null ? null : $s2->years();
                $push = $s4 === null ? null : $s4->years();
                if ($release === null && $push === null) {
                    return ['unit' => 'years', 'value' => null, 'source' => null];
                }

                return $push !== null && ($release === null || $push > $release) ? ['unit' => 'years', 'value' => $push, 'source' => 'push'] : ['unit' => 'years', 'value' => $release, 'source' => 'release'];
        }
    }

    /**
     * @template T of SignalData
     *
     * @param list<Signal>    $signals
     * @param class-string<T> $class
     *
     * @return ?T
     */
    private static function dataIn(array $signals, string $class): ?SignalData
    {
        foreach ($signals as $signal) {
            $data = $signal->data();
            if ($data instanceof $class) {
                return $data;
            }
        }

        return null;
    }

    /**
     * @param list<Signal> $signals
     *
     * @return list<AdvisoryRow>
     */
    private static function rowsOf(array $signals): array
    {
        $s9 = self::dataIn($signals, AdvisoryData::class);

        return $s9 === null ? [] : $s9->rows();
    }

    /**
     * @param list<Signal> $signals
     *
     * @return list<Check>
     */
    private static function checksOf(array $signals): array
    {
        $s10 = self::dataIn($signals, NotCheckedData::class);

        return $s10 === null ? [] : $s10->checks();
    }
}
