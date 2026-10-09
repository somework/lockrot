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
use Lockrot\Score\ScoreBasis;
use Lockrot\Score\SecurityTerm;
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
        $this->advisoryFacts013 = $advisoryFacts013 ?? self::legacyFactsOf($signals);
    }

    /**
     * A finding built without report-1's S9 facts takes them from an S9 that still carries them, so
     * a report-1 S9 written by hand reads as it always did.
     *
     * @param list<Signal> $signals
     */
    private static function legacyFactsOf(array $signals): AdvisoryFacts013
    {
        foreach ($signals as $signal) {
            if ($signal->id() === Signal::S9) {
                $data = $signal->data();
                $rows = [];
                foreach (\is_array($data['advisories'] ?? null) ? $data['advisories'] : [] as $row) {
                    if (\is_array($row) && \is_string($row['id'] ?? null)) {
                        $rows[] = $row;
                    }
                }

                /** @var list<array{id: string, cve: ?string, title: ?string, link: ?string, severity: ?string, reported_at: ?string, affected_versions: ?string, fixed_by: ?string, fixed_on_branch: bool}> $rows */
                return new AdvisoryFacts013($rows, ($data['releases_read'] ?? false) === true);
            }
        }

        return AdvisoryFacts013::none();
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
        $ids = array_column($score->maintenanceTerms(), 'flag');
        if ($score->deciding() !== null) {
            $ids[] = FlagSet::VULNERABLE;
        }

        return $ids;
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
        return $this->advisoryFacts013->data();
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
        $signals = $this->signalsOut($details);
        $data = array_column($signals, 'data', 'id');
        $checksMissing = self::checks($data[Signal::S10]['unchecked'] ?? []);
        $context = $this->context($details, $checksMissing);
        $branchKey = ReleaseBranch::of($this->version);
        $branch = $branchKey === null ? null : ReleaseBranch::label($branchKey);
        $basis = ScoreBasis::of($flags, self::reachOf($this->chain), $this->dev, $context);
        $rows = self::rows($data[Signal::S9]['advisories'] ?? []);
        $flagsOut = $this->flagsOut($flags, $basis, $data, $context['liveness_complete'], $branch);
        $counted = $this->flagIds();
        $evidence = [];
        foreach ($flagsOut as $flag) {
            if (\in_array($flag['id'], $counted, true)) {
                $evidence[] = \sprintf('%s: %s', self::scalarOrNull($flag['id']), self::scalarOrNull($flag['summary']));
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
            'flags' => $flagsOut,
            'score' => $basis,
            'next_step' => null,
            'security' => $details->security($rows, $branch),
            'checks_missing' => $checksMissing,
            'checks_skipped' => $details->skipped(),
            'maintenance_judged' => $this->maintenanceJudged,
            'metadata' => $details->metadata(),
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
        $details = $this->details();
        $data = array_column($this->signalsOut($details), 'data', 'id');

        return ScoreBasis::of($this->flags(), self::reachOf($this->chain), $this->dev, $this->context($details, self::checks($data[Signal::S10]['unchecked'] ?? [])));
    }

    /**
     * Where the finding stands on security, from its S9 rows.
     *
     * @throws \LogicException for a finding built without its details
     */
    public function securityStanding(): SecurityStanding
    {
        $data = array_column($this->signalsOut($this->details()), 'data', 'id');

        return $this->details()->standing(self::rows($data[Signal::S9]['advisories'] ?? []));
    }

    /**
     * @param list<array{check: string, reason: string, blocks: list<string>}> $checksMissing S10's checks as report-2 writes them
     *
     * @return array{maintenance_judged: bool, advisories_complete: bool, liveness_complete: bool, s3_unread: bool, s8_unread: bool}
     */
    private function context(FindingDetails $details, array $checksMissing): array
    {
        $livenessComplete = true;
        $s3Unread = false;
        foreach (array_merge($details->skipped(), $checksMissing) as $check) {
            $livenessComplete = $livenessComplete && array_intersect($check['blocks'], [Signal::S2, Signal::S4]) === [];
            $s3Unread = $s3Unread || \in_array(Signal::S3, $check['blocks'], true);
        }

        return [
            'maintenance_judged' => $this->maintenanceJudged,
            'advisories_complete' => $details->advisoriesComplete(),
            'liveness_complete' => $livenessComplete,
            's3_unread' => $s3Unread,
            's8_unread' => ReleaseBranch::of($this->version) === null && !$this->maintenanceJudged,
        ];
    }

    /**
     * The signals as report-2 writes them: S10 gains the release data a counted advisory needed
     * and did not get.
     *
     * @return list<array{id: string, level: string, summary: string, data: array<string, mixed>}>
     */
    private function signalsOut(FindingDetails $details): array
    {
        $out = [];
        foreach ($this->signals as $signal) {
            $data = $signal->data();
            if ($signal->id() === Signal::S9) {
                $data['advisories'] = $this->decidingMarked($data['advisories'] ?? []);
            }
            $out[] = ['id' => $signal->id(), 'level' => $signal->level(), 'summary' => $signal->summary(), 'data' => $data];
        }
        $releases = $details->releasesUnchecked();
        if ($releases === null) {
            return $out;
        }
        foreach ($out as $i => $signal) {
            if ($signal['id'] === Signal::S10) {
                $unchecked = array_merge(self::checks($signal['data']['unchecked'] ?? []), [$releases]);
                $out[$i]['data'] = ['unchecked' => $unchecked, 'blocks' => array_values(array_unique(array_merge(...array_column($unchecked, 'blocks'))))];
                $out[$i]['summary'] .= '; '.NotCheckedRule::RELEASES_WORDS;

                return $out;
            }
        }
        $out[] = ['id' => Signal::S10, 'level' => Signal::LEVEL_INFO, 'summary' => NotCheckedRule::RELEASES_WORDS, 'data' => ['unchecked' => [$releases], 'blocks' => $releases['blocks']]];

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $data
     *
     * @return list<array<string, mixed>>
     */
    private function flagsOut(FlagSet $flags, ScoreBasis $basis, array $data, bool $livenessComplete, ?string $branch): array
    {
        $roles = [];
        foreach ($basis->maintenanceTerms() as $term) {
            $roles[$term->flag()] = $term->role();
        }
        $security = $basis->securityTerm();
        $out = [];
        foreach ($flags->fired() as $flag) {
            $ids = array_values(array_filter(FlagSentence::SIGNALS[$flag], static fn (string $id): bool => isset($data[$id])));
            if ($flag === FlagSet::VULNERABLE) {
                $rows = self::rows($data[Signal::S9]['advisories'] ?? []);
                $summary = $this->details()->vulnerableSummary($rows, $branch, $security === null ? '' : $security->severity());
                $out[] = ['id' => $flag, 'role' => SecurityTerm::ROLE, 'baseline' => null, 'signal_ids' => [Signal::S9], 'degree' => null, 'headline' => ['unit' => 'advisories', 'value' => \count($rows), 'source' => null], 'summary' => $summary];
                continue;
            }
            $out[] = [
                'id' => $flag,
                'role' => $roles[$flag] ?? 'accepted',
                'baseline' => null,
                'signal_ids' => $ids,
                'degree' => $flag === FlagSet::ABANDONED ? ['reasons' => $flags->reasons(), 'liveness_complete' => $livenessComplete] : (\in_array($flag, [FlagSet::SILENT, FlagSet::STALE], true) ? ['liveness_complete' => $livenessComplete] : null),
                'headline' => self::headline($flag, $flags, $data),
                'summary' => FlagSentence::maintenance($flag, $data),
            ];
        }

        return $out;
    }

    /**
     * The S9 rows with `deciding` after `points`: true on the advisory that the score's security
     * term counts, so the row and the term cannot name two advisories.
     *
     * @param mixed $rows
     *
     * @return list<array<string, mixed>>
     */
    private function decidingMarked($rows): array
    {
        $deciding = $this->score === null ? null : $this->score->deciding();
        $out = [];
        foreach (self::rows($rows) as $row) {
            $marked = [];
            foreach ($row as $key => $value) {
                $marked[$key] = $value;
                if ($key === 'points') {
                    $marked['deciding'] = $deciding !== null && $deciding['id'] === ($row['id'] ?? null);
                }
            }
            $out[] = $marked;
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $data
     *
     * @return array{unit: string, value: int|float|string|null, source: ?string}
     */
    private static function headline(string $flag, FlagSet $flags, array $data): array
    {
        switch ($flag) {
            case FlagSet::ABANDONED:
                return ['unit' => 'reason', 'value' => \in_array(FlagSet::ARCHIVED, $flags->reasons(), true) ? 'archived' : 'marked', 'source' => null];
            case FlagSet::PINNED:
                return ['unit' => 'reason', 'value' => self::scalarOrNull($data[Signal::S6]['reason'] ?? null), 'source' => null];
            case FlagSet::LEFT_BEHIND:
                return ['unit' => 'years', 'value' => self::scalarOrNull($data[Signal::S8]['years'] ?? null), 'source' => 'branch'];
            case FlagSet::OLD_PROMISE:
                return ['unit' => 'php', 'value' => self::scalarOrNull($data[Signal::S5]['written_for_php'] ?? null), 'source' => null];
            default:
                $release = self::scalarOrNull($data[Signal::S2]['years'] ?? null);
                $push = self::scalarOrNull($data[Signal::S4]['years'] ?? null);
                if ($release === null && $push === null) {
                    return ['unit' => 'years', 'value' => null, 'source' => null];
                }

                return $push !== null && ($release === null || $push > $release) ? ['unit' => 'years', 'value' => $push, 'source' => 'push'] : ['unit' => 'years', 'value' => $release, 'source' => 'release'];
        }
    }

    /**
     * @param mixed $value
     *
     * @return int|float|string|null
     */
    private static function scalarOrNull($value)
    {
        return \is_int($value) || \is_float($value) || \is_string($value) ? $value : null;
    }

    /**
     * @param mixed $value a list of objects from a signal's data or the score basis
     *
     * @return list<array<string, mixed>>
     */
    private static function rows($value): array
    {
        $rows = [];
        foreach (\is_array($value) ? $value : [] as $row) {
            if (\is_array($row)) {
                $rows[] = array_filter($row, 'is_string', \ARRAY_FILTER_USE_KEY);
            }
        }

        return $rows;
    }

    /**
     * @param mixed $value S10's `unchecked` list
     *
     * @return list<array{check: string, reason: string, blocks: list<string>}>
     */
    private static function checks($value): array
    {
        $checks = [];
        foreach (self::rows($value) as $row) {
            $blocks = array_values(array_filter(\is_array($row['blocks'] ?? null) ? $row['blocks'] : [], 'is_string'));
            $checks[] = ['check' => (string) self::scalarOrNull($row['check'] ?? null), 'reason' => (string) self::scalarOrNull($row['reason'] ?? null), 'blocks' => $blocks];
        }

        return $checks;
    }
}
