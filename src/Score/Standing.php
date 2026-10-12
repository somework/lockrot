<?php

declare(strict_types=1);

namespace Lockrot\Score;

use Lockrot\Baseline\BaselineEntry;
use Lockrot\Security\Severity;
use Lockrot\Verdict\Score;
use Lockrot\Verdict\ScoreModel;

/**
 * Where one finding stands against the baseline, `finding.baseline` (SPEC §6.4, "Standing"): `new`
 * for a graded package without an entry, `worsened` when the entry leaves a counted flag uncovered or
 * does not know a counted advisory, else `known`. A degree change inside one word is never `worsened`.
 *
 * @internal
 *
 * @phpstan-type Shape array{status: string, recorded: ?array{lead: ?string, flags: ?array<string, array{known_since: string}>, advisory_count: int, first_seen: string, target_php: string, fix_model: int}, advisories_now: array{known: int, new: int, re_rated: int, fix_lost: int}, worsened_by: list<WorsenedFact>}
 */
final class Standing implements \JsonSerializable
{
    public const KNOWN = 'known';
    public const NEW_PACKAGE = 'new';
    public const WORSENED = 'worsened';

    /** A fix is lost when a fix recorded as one of the other kinds reads one of these. */
    private const LOST = ['blocked', 'none'];

    private ?BaselineEntry $entry;
    private string $recordedTargetPhp;
    private int $recordedFixModel;
    /** @var array<string, FactState> by flag */
    private array $flags = [];
    /** @var array<string, FactState> by advisory id */
    private array $advisories = [];
    /** @var list<WorsenedFact> */
    private array $worsenedBy = [];
    /** @var array{known: int, new: int, re_rated: int, fix_lost: int} */
    private array $advisoriesNow = ['known' => 0, 'new' => 0, 're_rated' => 0, 'fix_lost' => 0];
    /** @var list<string> */
    private array $uncovered = [];
    /** @var list<CountedAdvisory> */
    private array $unknown = [];

    private function __construct(?BaselineEntry $entry, string $recordedTargetPhp, int $recordedFixModel)
    {
        $this->entry = $entry;
        $this->recordedTargetPhp = $recordedTargetPhp;
        $this->recordedFixModel = $recordedFixModel;
    }

    /**
     * A graded package that the baseline file holds no entry for: every counted fact is new.
     *
     * @param list<string>          $counted    the counted maintenance flags, in flag order
     * @param list<CountedAdvisory> $advisories the counted advisories
     */
    public static function newPackage(array $counted, array $advisories): self
    {
        $standing = new self(null, '', 0);
        foreach ($counted as $flag) {
            $standing->flags[$flag] = new FactState(FactState::NEW_FACT, null, null);
            $standing->worsenedBy[] = WorsenedFact::flag($flag);
        }
        $standing->uncovered = $counted;
        foreach (self::ordered($advisories) as $advisory) {
            $standing->advisories[$advisory->id()] = new FactState(FactState::NEW_FACT, null, null);
            $standing->worsenedBy[] = WorsenedFact::advisory($advisory, FactState::NEW_FACT, null, null);
            $standing->unknown[] = $advisory;
            ++$standing->advisoriesNow['new'];
        }

        return $standing;
    }

    /**
     * Each counted fact against the entry. A fix is compared as lost only when $compareFixes: the
     * run's target PHP and fix model equal the file's.
     *
     * @param list<string>          $counted    the counted maintenance flags, in flag order
     * @param list<CountedAdvisory> $advisories the counted advisories
     */
    public static function against(BaselineEntry $entry, string $recordedTargetPhp, int $recordedFixModel, array $counted, array $advisories, bool $compareFixes): self
    {
        $standing = new self($entry, $recordedTargetPhp, $recordedFixModel);
        $coverage = Coverage::under($entry->recordedLead());
        $recordedFlags = $entry->flags();
        foreach ($counted as $flag) {
            if (isset($recordedFlags[$flag])) {
                $standing->flags[$flag] = new FactState(FactState::KNOWN, $recordedFlags[$flag], null);
            } elseif ($coverage->covers($flag)) {
                $lead = (string) $coverage->recordedLead();
                $standing->flags[$flag] = new FactState(FactState::COVERED, $recordedFlags[$lead], $lead);
            } else {
                $standing->flags[$flag] = new FactState(FactState::NEW_FACT, null, null);
                $standing->worsenedBy[] = WorsenedFact::flag($flag);
                $standing->uncovered[] = $flag;
            }
        }
        foreach (self::ordered($advisories) as $advisory) {
            $standing->compare($advisory, $compareFixes);
        }

        return $standing;
    }

    /** One state per advisory, by precedence: `new`, then `re_rated`, then `fix_lost`. */
    private function compare(CountedAdvisory $advisory, bool $compareFixes): void
    {
        $recorded = $this->entry === null ? null : $this->entry->advisory($advisory->id());
        if ($recorded === null) {
            $state = FactState::NEW_FACT;
        } elseif (Severity::fromComposer($advisory->severity())->gateRank() > Severity::fromComposer($recorded->severity())->gateRank()) {
            $state = FactState::RE_RATED;
        } elseif ($compareFixes && !\in_array($recorded->fixKind(), self::LOST, true) && \in_array($advisory->fixKind(), self::LOST, true)) {
            $state = FactState::FIX_LOST;
        } else {
            $state = FactState::KNOWN;
        }
        ++$this->advisoriesNow[$state];
        $this->advisories[$advisory->id()] = new FactState($state, $recorded === null ? null : $recorded->knownSince(), null);
        if ($state !== FactState::KNOWN) {
            $this->worsenedBy[] = WorsenedFact::advisory($advisory, $state, $recorded === null ? null : $recorded->severity(), $recorded === null ? null : $recorded->fixKind());
            $this->unknown[] = $advisory;
        }
    }

    /**
     * The advisories in the score's order: the most points, then the severity order, then the id.
     *
     * @param list<CountedAdvisory> $advisories
     *
     * @return list<CountedAdvisory> with the severity bucket
     */
    private static function ordered(array $advisories): array
    {
        $bucketed = array_map(static fn (CountedAdvisory $advisory): CountedAdvisory => $advisory->bucketed(), $advisories);
        usort($bucketed, static fn (CountedAdvisory $a, CountedAdvisory $b): int => ScoreModel::advisoryPoints($b->severity(), $b->fixKind()) <=> ScoreModel::advisoryPoints($a->severity(), $a->fixKind())
            ?: array_search($a->severity(), ScoreModel::SEVERITIES, true) <=> array_search($b->severity(), ScoreModel::SEVERITIES, true)
            ?: strcmp($a->id(), $b->id()));

        return $bucketed;
    }

    public function status(): string
    {
        if ($this->entry === null) {
            return self::NEW_PACKAGE;
        }

        return $this->worsenedBy === [] ? self::KNOWN : self::WORSENED;
    }

    public function hasEntry(): bool
    {
        return $this->entry !== null;
    }

    public function recordedLead(): ?string
    {
        return $this->entry === null ? null : $this->entry->recordedLead();
    }

    public function flagState(string $flag): ?FactState
    {
        return $this->flags[$flag] ?? null;
    }

    public function advisoryState(string $id): ?FactState
    {
        return $this->advisories[$id] ?? null;
    }

    /** @return list<string> the counted flags the entry does not cover, a prefix of them in flag order */
    public function uncoveredFlags(): array
    {
        return $this->uncovered;
    }

    /** @return list<CountedAdvisory> the counted advisories the entry does not know, with the bucket */
    public function unknownAdvisories(): array
    {
        return $this->unknown;
    }

    /** @return list<WorsenedFact> */
    public function worsenedBy(): array
    {
        return $this->worsenedBy;
    }

    /** The gate score under the entry, null for a package new to the file: its gate score is its score. */
    public function gateScore(Score $score): ?GateScore
    {
        return $this->entry === null ? null : GateScore::of($score, $this->uncovered, $this->unknown);
    }

    /** @return Shape */
    public function jsonSerialize(): array
    {
        $entry = $this->entry;

        return [
            'status' => $this->status(),
            'recorded' => $entry === null ? null : [
                'lead' => $entry->recordedLead(),
                'flags' => $entry->flags() === [] ? null : array_map(static fn (string $since): array => ['known_since' => $since], $entry->flags()),
                'advisory_count' => \count($entry->advisories()),
                'first_seen' => $entry->firstSeen(),
                'target_php' => $this->recordedTargetPhp,
                'fix_model' => $this->recordedFixModel,
            ],
            'advisories_now' => $this->advisoriesNow,
            'worsened_by' => $this->worsenedBy,
        ];
    }
}
