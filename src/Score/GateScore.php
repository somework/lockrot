<?php

declare(strict_types=1);

namespace Lockrot\Score;

use Lockrot\Verdict\Score;
use Lockrot\Verdict\ScoreModel;

/**
 * The gate score under a baseline entry, `finding.gate.basis`: `min(score, 2 × new)`, where `new`
 * is the engine rerun on the uncovered flags and the advisories the baseline does not know (SPEC
 * §6.4, "The gate score"). A new fact counts at least at its own level, and old facts lift the gate
 * by one band at most.
 *
 * @internal
 *
 * @phpstan-type Shape array{score: int, verdict: ?string, cap: int, limited_by: string, new: array{total: int, exact: int|float, rounded_down: bool, terms: list<Term>, modifiers: list<Modifier>}}
 */
final class GateScore implements \JsonSerializable
{
    public const LIMITED_BY_SCORE = 'score';
    public const LIMITED_BY_CAP = 'cap';
    public const LIMITED_BY_EQUAL = 'equal';

    private Score $score;
    private Score $new;
    /** @var list<string> */
    private array $uncovered;
    /** @var list<CountedAdvisory> */
    private array $unknown;

    /**
     * @param list<string>          $uncovered
     * @param list<CountedAdvisory> $unknown
     */
    private function __construct(Score $score, Score $new, array $uncovered, array $unknown)
    {
        $this->score = $score;
        $this->new = $new;
        $this->uncovered = $uncovered;
        $this->unknown = $unknown;
    }

    /**
     * @param list<string>          $uncovered the counted maintenance flags that the entry does not cover
     * @param list<CountedAdvisory> $unknown   the counted advisories that the entry does not know
     */
    public static function of(Score $score, array $uncovered, array $unknown): self
    {
        return new self($score, Score::compute($uncovered, $unknown, $score->reach(), $score->isDev()), $uncovered, $unknown);
    }

    public function scoreTotal(): int
    {
        return $this->score->total();
    }

    public function newScore(): Score
    {
        return $this->new;
    }

    public function cap(): int
    {
        return 2 * $this->new->total();
    }

    public function total(): int
    {
        return min($this->score->total(), $this->cap());
    }

    /** Null at a gate score of 0. */
    public function verdict(): ?string
    {
        return ScoreModel::band($this->total());
    }

    /** `cap` whenever `new` is 0, so every known finding reads alike. */
    public function limitedBy(): string
    {
        if ($this->new->total() === 0 || $this->score->total() > $this->cap()) {
            return self::LIMITED_BY_CAP;
        }

        return $this->score->total() === $this->cap() ? self::LIMITED_BY_EQUAL : self::LIMITED_BY_SCORE;
    }

    /** @return list<string> */
    public function uncoveredFlags(): array
    {
        return $this->uncovered;
    }

    /** @return list<CountedAdvisory> */
    public function unknownAdvisories(): array
    {
        return $this->unknown;
    }

    /** @return list<Term> the terms of `new`, in the score's order */
    public function newTerms(): array
    {
        $deciding = $this->new->deciding();

        return $deciding === null ? $this->new->maintenanceTerms() : array_merge($this->new->maintenanceTerms(), [$deciding]);
    }

    /**
     * The lead and security terms whose contribution reaches the share of a grade's floor that marks a
     * record `error`: a half without a baseline entry, a quarter under one (SPEC §6.2, "Record marks").
     * A failing grade value always has one: every contribution is a power of two or half of one.
     *
     * @param list<Term> $terms the score's terms, or `new`'s under an entry
     *
     * @return list<Term>
     */
    public static function termsReaching(array $terms, string $grade, bool $underEntry): array
    {
        $floorHalves = 2 * ScoreModel::floorOf($grade);

        return array_values(array_filter($terms, static fn (Term $term): bool => $term->role() !== Score::CORROBORATING
            && ($underEntry ? 4 : 2) * $term->contributionHalves() >= $floorHalves));
    }

    /** @return Shape */
    public function jsonSerialize(): array
    {
        $terms = $this->newTerms();

        return [
            'score' => $this->total(),
            'verdict' => $this->verdict(),
            'cap' => $this->cap(),
            'limited_by' => $this->limitedBy(),
            'new' => [
                'total' => $this->new->total(),
                'exact' => HalfPoints::json($this->new->exactHalves()),
                'rounded_down' => $this->new->exactHalves() !== 2 * $this->new->total(),
                'terms' => $terms,
                'modifiers' => $terms === [] ? [] : ScoreBasis::modifiersOf($this->new),
            ],
        ];
    }
}
