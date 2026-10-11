<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Security\Candidate;
use Lockrot\Security\Fix;
use Lockrot\Signal\BranchRow;
use Lockrot\Signal\Data\AbandonedData;
use Lockrot\Signal\Data\LeftBehindData;
use Lockrot\Signal\Data\OldPromiseData;
use Lockrot\Signal\Data\PinnedData;

/**
 * The move of the lead maintenance flag (docs/verdicts.md#move). A marking
 * that Composer's abandoned ignore list removes raises no S1, so it never names a replacement.
 *
 * @internal
 */
final class MaintenanceMove
{
    public static function draft(MoveContext $context): ?MoveDraft
    {
        $finding = $context->finding();
        $lead = $finding->lead();
        switch ($lead) {
            case null:
                return null;
            case FlagSet::ABANDONED:
                return self::abandoned($finding);
            case FlagSet::SILENT:
                return MoveDraft::of(Move::FIND_ALTERNATIVE, $lead);
            case FlagSet::PINNED:
                return self::pinned($context);
            case FlagSet::LEFT_BEHIND:
                return self::leftBehind($context);
            case FlagSet::OLD_PROMISE:
                return self::oldPromise($context);
            default:
                return MoveDraft::of(Move::NO_MOVE, $lead)->withReason('package_quiet');
        }
    }

    /** The kind of a move to $lower: `update` only on the installed branch, which `composer update` stays on. */
    public static function kindOf(Candidate $lower, BranchRow $row): string
    {
        switch ($lower->kind()) {
            case Fix::UPDATE:
                return $row->installed() ? Move::UPDATE : Move::REQUIRE;
            case Fix::UPGRADE:
                return Move::REQUIRE;
            default:
                return $lower->kind();
        }
    }

    private static function abandoned(Finding $finding): MoveDraft
    {
        $s1 = $finding->signalData(AbandonedData::class);
        $replacement = $s1 === null ? null : $s1->replacement();
        if ($s1 === null || $replacement === null || $replacement === '') {
            return MoveDraft::of(Move::FIND_ALTERNATIVE, FlagSet::ABANDONED);
        }

        return MoveDraft::of(Move::REPLACE, FlagSet::ABANDONED)->withReplacement(new Replacement($finding->package(), $replacement, $s1->markedBy(), $s1->replacementUrl()));
    }

    private static function pinned(MoveContext $context): MoveDraft
    {
        $finding = $context->finding();
        if (!$finding->isMaintenanceJudged()) {
            return MoveDraft::of(Move::NO_MOVE, FlagSet::PINNED)->withReason($finding->origin()->isLocal() ? 'local_package' : 'metadata_not_read');
        }
        $s6 = $finding->signalData(PinnedData::class);
        $snapshotTime = $s6 === null ? null : $s6->snapshotTime();
        $alias = $context->installedBranch();
        $own = $alias === null ? null : $context->row($alias);
        if ($own !== null) {
            $released = $own->highestReleased();
            $at = $released === null ? null : $released->format(\DATE_ATOM);
            $relation = $at === null || $snapshotTime === null ? null : self::relation($released, new \DateTimeImmutable($snapshotTime));

            return self::tag($own, new Latest($own->highest(), $at, false, $relation, $snapshotTime));
        }
        $version = $s6 === null ? null : $s6->lastStableVersion();
        if ($s6 === null || $version === null) {
            return MoveDraft::of(Move::NO_TAG, FlagSet::PINNED);
        }
        $row = null;
        foreach ($context->rows() as $candidate) {
            $row = ltrim($candidate->highest(), 'vV') === ltrim($version, 'vV') ? $candidate : $row;
        }

        return self::tag($row, new Latest($version, $s6->lastStableRelease(), false, $s6->tagRelation(), $snapshotTime));
    }

    private static function tag(?BranchRow $row, Latest $latest): MoveDraft
    {
        $kind = $row !== null && $row->admitsTargetPhp() === false ? Move::BLOCKED : Move::TAG;

        return MoveDraft::of($kind, FlagSet::PINNED)->withLatest($latest, $row);
    }

    private static function relation(\DateTimeImmutable $released, \DateTimeImmutable $snapshot): string
    {
        if ($released->getTimestamp() === $snapshot->getTimestamp()) {
            return 'same';
        }

        return $released < $snapshot ? 'older' : 'newer';
    }

    private static function leftBehind(MoveContext $context): MoveDraft
    {
        $s8 = $context->finding()->signalData(LeftBehindData::class);
        $reachable = $s8 === null ? null : $s8->reachable();
        $row = $reachable === null ? null : $context->row($reachable->branch());
        $suggested = $reachable === null ? null : $reachable->suggestedConstraint();
        if ($row !== null && $suggested !== null) {
            return self::require(FlagSet::LEFT_BEHIND, $context, $row, $suggested);
        }
        $above = array_values(array_filter($context->rows(), static fn (BranchRow $row): bool => !$row->installed() && $context->isAbove($row)));
        $releasing = array_reverse(array_values(array_filter($above, static fn (BranchRow $row): bool => $context->isReleasing($context->releasedAt($row)))));
        foreach ($releasing as $row) {
            if ($row->admitsTargetPhp() !== false) {
                return self::require(FlagSet::LEFT_BEHIND, $context, $row, '^'.implode('.', \array_slice(explode('.', ltrim($row->highest(), 'vV')), 0, 2)));
            }
        }
        if ($releasing !== []) {
            return MoveDraft::of(Move::BLOCKED, FlagSet::LEFT_BEHIND)->to($releasing[0], null, null);
        }
        foreach ($above as $row) {
            if ($context->releasedAt($row) === null) {
                return MoveDraft::of(Move::NO_MOVE, FlagSet::LEFT_BEHIND)->withReason('higher_undated');
            }
        }

        return MoveDraft::of(Move::NO_MOVE, FlagSet::LEFT_BEHIND)->withReason('no_higher_release');
    }

    private static function oldPromise(MoveContext $context): MoveDraft
    {
        $ga = $context->ga();
        $installed = $context->installedRow();
        $newest = $installed === null ? null : $context->release($installed->highest());
        $lower = $newest === null ? null : $context->candidate($newest);
        if ($installed !== null && $lower !== null && $lower->kind() === Fix::UPDATE && self::after($newest === null ? null : $newest->at(), $ga)) {
            return MoveDraft::of(Move::UPDATE, FlagSet::OLD_PROMISE)->to($installed, $lower, null);
        }
        foreach (array_reverse($context->rows()) as $row) {
            if (!$row->installed() && $context->isAbove($row) && $row->admitsTargetPhp() !== false && self::after($row->highestReleased(), $ga)) {
                return self::require(FlagSet::OLD_PROMISE, $context, $row, '^'.ltrim($row->highest(), 'vV'));
            }
        }
        // With no stable release read, the installed one is the newest that lockrot knows.
        $installedVersion = $context->subject()->version();
        $s5 = $context->finding()->signalData(OldPromiseData::class);
        $version = $context->lastStableVersion() ?? $installedVersion;
        $released = $context->lastStableVersion() === null ? ($s5 === null ? null : $s5->released()) : $context->lastStableReleased();

        return MoveDraft::of(Move::TEST, FlagSet::OLD_PROMISE)->withLatest(new Latest($version, $released, ltrim($version, 'vV') === ltrim($installedVersion, 'vV'), null, null), null);
    }

    private static function after(?\DateTimeImmutable $released, ?\DateTimeImmutable $ga): bool
    {
        return $released !== null && $ga !== null && $released->format('Y-m-d') > $ga->format('Y-m-d');
    }

    /** A move to a branch by a constraint: its kind is the class of the lowest release the constraint admits. */
    private static function require(string $source, MoveContext $context, BranchRow $row, string $constraint): MoveDraft
    {
        [$lower, $constraint] = $context->lowestAdmitted($row, $constraint);
        $kind = $lower === null ? self::rowKind($row) : self::kindOf($lower, $row);
        $kind = $kind === Move::UPDATE ? Move::REQUIRE : $kind;

        return MoveDraft::of($kind, $source)->to($row, $lower, $kind === Move::BLOCKED ? null : $constraint);
    }

    /** With no release read on the row, its class from its PHP: the row's newest release decides. */
    private static function rowKind(BranchRow $row): string
    {
        if ($row->admitsTargetPhp() === false) {
            return Move::BLOCKED;
        }

        return $row->admitsProjectPhp() === false ? Move::RAISE_PHP : Move::REQUIRE;
    }
}
