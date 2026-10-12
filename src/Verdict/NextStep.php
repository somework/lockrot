<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Composer\Semver\VersionParser;
use Lockrot\Score\Counterfactual;
use Lockrot\Score\IfApplied;
use Lockrot\Security\Fix;
use Lockrot\Signal\BranchRow;

/**
 * The one producer of a finding's moves (docs/verdicts.md#move): a security move of class
 * `update` leads, else the move of the larger part, a tie to the lower score after it, then
 * security's. Two moves to one branch merge into the tightest constraint. The other part's move
 * follows in `also[]` unless the lead already clears or leaves unverified every fact of that part.
 *
 * @internal
 */
final class NextStep
{
    /** @param list<ThroughEntry> $through the lead move's `through[]` entries */
    public static function plan(MoveContext $context, array $through): Moves
    {
        $finding = $context->finding();
        $score = $finding->score();
        $security = $score->deciding() === null ? null : self::securityDraft($context);
        $maintenance = MaintenanceMove::draft($context);
        $fixKind = $security === null ? null : $security->fixKind();
        $rows = self::rowsIfApplied($context);
        $partial = self::partial($context);
        [$main, $other] = self::lead($security, $maintenance, $context, $fixKind);
        if ($main === null) {
            return new Moves(null, null, $fixKind, $partial, $rows);
        }
        $merged = [];
        $mainRow = $main->row();
        $otherRow = $other === null ? null : $other->row();
        if ($other !== null && $main->isRowMove() && $other->isRowMove() && $mainRow !== null && $otherRow !== null && $mainRow->branch() === $otherRow->branch()) {
            if (version_compare((string) self::lowerOf($other), (string) self::lowerOf($main), '>')) {
                $lower = $other->lower();
                $kind = $lower === null ? $main->kind() : MaintenanceMove::kindOf($lower, $mainRow);
                $constraint = \in_array($kind, [Move::REQUIRE, Move::RAISE_PHP], true) ? $other->constraint() : null;
                $main = MoveDraft::of($kind, $main->source())->to($mainRow, $lower, $constraint)->withFixKind($main->fixKind());
            }
            $merged = [$other->source()];
            $other = null;
        }
        $lead = MoveBuilder::build($main, $merged, $fixKind, $context);
        $also = [];
        if ($other !== null && $other->kind() !== Move::NO_MOVE) {
            $second = MoveBuilder::build($other, [], $fixKind, $context);
            if (!self::covers($lead, self::partFacts($other, $context))) {
                $also = [$second];
            }
        }
        $advisories = $context->advisoryIds();
        $moveIn = null;
        if ($lead->isSecurity() || ($advisories !== [] && array_diff($advisories, $lead->clearedAdvisories()) === [])) {
            $moveIn = Moves::IN_NEXT_STEP;
            $lower = $main->lower();
            if ($lead->fixKind() === null && $advisories !== [] && $lower !== null) {
                $lead = $lead->withFixKind($lower->kind());
            }
        } elseif ($also !== [] && $also[0]->isSecurity()) {
            $moveIn = Moves::IN_ALSO;
        }
        $lead = $lead->asLead($finding->isDirect() ? [] : $through, $also);
        foreach (array_merge([$lead], $also) as $move) {
            $partial = $partial !== null && $move->kind() === Move::UPDATE && $move->version() === $partial->version() ? null : $partial;
        }
        $published = $moveIn === Moves::IN_NEXT_STEP ? $lead : ($moveIn === Moves::IN_ALSO ? $also[0] : null);

        return new Moves($lead, $moveIn, $published === null ? $fixKind : $published->fixKind(), $partial, $rows);
    }

    /**
     * The security move: the release scan's pick, the easiest row candidate, then the lowest
     * release. With none, why: no release outside the ranges, no single one, or no release
     * data.
     */
    private static function securityDraft(MoveContext $context): MoveDraft
    {
        $fixes = $context->fixes();
        $candidate = $fixes === null ? null : $fixes->move();
        if ($candidate !== null) {
            $row = $context->row($candidate->branch());
            if ($row !== null) {
                return self::toCandidate($row, $candidate);
            }
        }
        $kinds = array_unique(array_map(static fn (Fix $fix): string => $fix->kind(), $fixes === null ? [] : array_values($fixes->fixes())));
        $finding = $context->finding();
        if ($kinds === [Fix::UNKNOWN] || $kinds === []) {
            $reason = $finding->origin()->isLocal() ? 'local_package' : ($finding->isFromComposerRepository() ? 'releases_unknown' : 'metadata_not_read');

            return MoveDraft::of(Move::NO_MOVE, FlagSet::VULNERABLE)->withReason($reason)->withFixKind(Fix::UNKNOWN);
        }
        $hardest = null;
        foreach (ScoreModel::FIX_KINDS as $kind) {
            $hardest = \in_array($kind, $kinds, true) ? $kind : $hardest;
        }

        return MoveDraft::of($kinds === [Fix::NONE] ? Move::NO_FIX : Move::NO_SINGLE_FIX, FlagSet::VULNERABLE)->withFixKind($hardest);
    }

    /** A move to a row's candidate: `composer update` on the installed row, `require ^{lowest}` above it. */
    private static function toCandidate(BranchRow $row, \Lockrot\Security\Candidate $candidate): MoveDraft
    {
        $kind = MaintenanceMove::kindOf($candidate, $row);
        $constraint = \in_array($kind, [Move::REQUIRE, Move::RAISE_PHP], true) ? '^'.ltrim($candidate->release()->pretty(), 'vV') : null;

        return MoveDraft::of($kind, FlagSet::VULNERABLE)->to($row, $candidate, $constraint)->withFixKind($candidate->kind());
    }

    /**
     * Each row's score after a move to its candidate, judged as the move to it is.
     *
     * @return array<string, IfApplied>
     */
    private static function rowsIfApplied(MoveContext $context): array
    {
        $out = [];
        $fixes = $context->fixes();
        foreach ($fixes === null ? [] : $fixes->branches() as $branch) {
            $candidate = $branch->candidate();
            $row = $context->row($branch->branch());
            if ($candidate === null || $row === null) {
                continue;
            }
            $ifApplied = MoveBuilder::build(self::toCandidate($row, $candidate), [], $candidate->kind(), $context)->ifApplied();
            if ($ifApplied !== null) {
                $out[$branch->branch()] = $ifApplied->asRow();
            }
        }

        return $out;
    }

    /** `security.partial`, judged as an `update` move to that release. */
    private static function partial(MoveContext $context): ?SecurityPartial
    {
        $fixes = $context->fixes();
        $partial = $fixes === null ? null : $fixes->partial();
        $installed = $context->installedRow();
        if ($partial === null || $installed === null) {
            return null;
        }
        $finding = $context->finding();
        $flags = array_map(static fn ($term): string => $term->flag(), $finding->score()->maintenanceTerms());
        [$clears, $unverified] = MoveBuilder::clearsFor($installed, $partial->release(), $flags, [], $context);
        $settled = array_map(static fn (MoveFact $fact): string => $fact->id(), $clears);
        foreach ($partial->clears() as $id) {
            $clears[] = MoveFact::on(MoveFact::ADVISORY, $id, MoveFact::OUTSIDE_RANGE);
        }
        $score = $finding->score();
        $assumes = array_merge([IfApplied::CLEARS_HOLD], $context->advisoriesComplete() ? [] : [IfApplied::NO_NEW_ADVISORIES]);
        $rerun = Counterfactual::afterMove($finding->flags(), $settled, $partial->clears(), [], $score->reach(), $score->isDev(), $finding->isMaintenanceJudged(), $unverified !== [], $assumes);

        return new SecurityPartial($partial->version(), $clears, $unverified, $rerun);
    }

    /**
     * @return array{0: ?MoveDraft, 1: ?MoveDraft} the lead and the other part's move
     */
    private static function lead(?MoveDraft $security, ?MoveDraft $maintenance, MoveContext $context, ?string $fixKind): array
    {
        if ($security === null || $maintenance === null) {
            return [$security ?? $maintenance, null];
        }
        if ($security->kind() === Move::UPDATE) {
            return [$security, $maintenance];
        }
        $score = $context->finding()->score();
        $byPart = $score->securityHalves() <=> $score->maintenanceHalves();
        if ($byPart === 0) {
            $after = static function (MoveDraft $draft) use ($context, $fixKind, $score): int {
                $ifApplied = MoveBuilder::build($draft, [], $fixKind, $context)->ifApplied();

                return $ifApplied === null ? $score->total() : $ifApplied->total();
            };
            $byPart = $after($maintenance) <=> $after($security);
        }

        return $byPart >= 0 ? [$security, $maintenance] : [$maintenance, $security];
    }

    /** The normalized lower bound of a move: its constraint's, else the release it names. */
    private static function lowerOf(MoveDraft $draft): ?string
    {
        $constraint = $draft->constraint();
        if ($constraint !== null) {
            try {
                return (new VersionParser())->parseConstraints($constraint)->getLowerBound()->getVersion();
            } catch (\UnexpectedValueException $e) {
                return null;
            }
        }
        $lower = $draft->lower();

        return $lower === null ? null : $lower->release()->normalized();
    }

    /**
     * The counted facts of the part that a move comes from.
     *
     * @return list<string> `kind id` keys
     */
    private static function partFacts(MoveDraft $draft, MoveContext $context): array
    {
        if ($draft->source() === FlagSet::VULNERABLE) {
            return array_map(static fn (string $id): string => MoveFact::ADVISORY.' '.$id, $context->advisoryIds());
        }

        return array_map(static fn ($term): string => MoveFact::FLAG.' '.$term->flag(), $context->finding()->score()->maintenanceTerms());
    }

    /** @param list<string> $facts `kind id` keys */
    private static function covers(Move $move, array $facts): bool
    {
        $covered = array_map(static fn (MoveFact $fact): string => $fact->kind().' '.$fact->id(), array_merge($move->clears(), $move->unverified()));

        return array_diff($facts, $covered) === [];
    }
}
