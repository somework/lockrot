<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Composer\Semver\VersionParser;
use Lockrot\Score\Counterfactual;
use Lockrot\Score\IfApplied;
use Lockrot\Security\Candidate;
use Lockrot\Security\Holder;
use Lockrot\Security\PhpCheck;
use Lockrot\Signal\BranchRow;
use Lockrot\Signal\ConstraintOpenness;
use Lockrot\Signal\Data\NoReleaseData;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveQuery;

/**
 * Judges a {@see MoveDraft}: what it clears for every version it admits, what it leaves, adds and
 * cannot check, its PHP, what holds it, Composer's check of it, its commands as argv lists and the
 * score after it (docs/verdicts.md#move). `assumes` says what the rerun takes as given.
 *
 * @internal
 */
final class MoveBuilder
{
    private const RESOLVED = [Move::REQUIRE, Move::RAISE_PHP, Move::TAG];
    private const ACTIONS = [Move::REQUIRE, Move::RAISE_PHP, Move::TAG, Move::REPLACE, Move::FIND_ALTERNATIVE, Move::TEST];

    /**
     * @param list<string> $merged
     * @param ?string      $fixKind the security fix kind, written when the move comes from `vulnerable`
     */
    public static function build(MoveDraft $draft, array $merged, ?string $fixKind, MoveContext $context): Move
    {
        $finding = $context->finding();
        $flags = $finding->flags();
        $kind = $draft->kind();
        $row = $draft->row();
        $lower = $draft->lower();
        $clears = [];
        $unverified = [];
        $adds = [];
        if ($draft->isRowMove() && $row !== null) {
            [$clears, $unverified] = self::clearsFor($row, $lower, self::countedMaintenance($finding), $context->advisoryIds(), $context);
        }
        $phpCheck = null;
        if ($row !== null && (\in_array($kind, [Move::UPDATE, Move::REQUIRE, Move::RAISE_PHP, Move::BLOCKED], true) || $draft->source() === FlagSet::PINNED)) {
            $phpCheck = PhpCheck::of($lower === null ? $row->php() : $lower->php(), $context->floor());
        }
        $latest = $draft->latest();
        if ($kind === Move::TAG) {
            $clears = [MoveFact::on(MoveFact::FLAG, FlagSet::PINNED, MoveFact::TAGS_ONLY)];
            [$adds, $unverified] = self::tagRisks($row, $latest, $flags, $context);
        }
        $cleared = self::keys(array_merge($clears, $unverified));
        $leaves = [];
        foreach (self::countedMaintenance($finding) as $flag) {
            if (!\in_array(MoveFact::FLAG.' '.$flag, $cleared, true)) {
                $leaves[] = MoveFact::left(MoveFact::FLAG, $flag);
            }
        }
        foreach ($context->advisoryIds() as $id) {
            if (!\in_array(MoveFact::ADVISORY.' '.$id, $cleared, true)) {
                $leaves[] = MoveFact::left(MoveFact::ADVISORY, $id);
            }
        }
        $toBranch = $row !== null && \in_array($kind, [Move::UPDATE, Move::REQUIRE, Move::RAISE_PHP, Move::BLOCKED, Move::TAG], true) ? $row->branch() : null;
        $installed = $context->installedBranch();
        $crosses = $toBranch === null || $installed === null ? null : (self::major($toBranch) !== self::major($installed) || (strpos($installed, '0.') === 0 && $toBranch !== $installed));
        $subject = $context->subject();
        $constraint = \in_array($kind, [Move::REQUIRE, Move::RAISE_PHP], true) ? $draft->constraint() : null;
        $version = \in_array($kind, Move::ROW_KINDS, true) ? ($lower === null ? null : $lower->release()->pretty()) : ($kind === Move::TAG && $latest !== null ? $latest->version() : null);
        $checks = $context->checks();
        $query = $checks === null ? null : self::query($kind, $subject, $constraint, $version, $phpCheck);
        $outcome = $query === null || $checks === null ? null : $checks->check($query);
        $check = $outcome === null ? null : $outcome->check();
        $heldBy = $lower !== null && $kind !== Move::TAG ? $lower->heldBy() : [];
        if ($outcome !== null && $outcome->check()->isRefused()) {
            $heldBy = Holder::distinct(array_merge($heldBy, $outcome->holds()));
        }
        $because = self::because($kind, $crosses, $heldBy, $subject);
        $ifApplied = null;
        if (\in_array($kind, [Move::UPDATE, Move::REQUIRE, Move::RAISE_PHP, Move::TAG], true)) {
            $resolves = $check !== null && $check->isResolved();
            $assumes = array_merge([IfApplied::CLEARS_HOLD], !$resolves && ($because !== [] || $heldBy !== []) ? [IfApplied::COMPOSER_RESOLVES] : [], $context->advisoriesComplete() ? [] : [IfApplied::NO_NEW_ADVISORIES]);
            $ifApplied = self::rerun($draft, $clears, $unverified, $adds, $context, $assumes);
        }
        $s2 = $finding->signalData(NoReleaseData::class);

        return (new Move(
            $kind,
            $draft->source(),
            $merged,
            $draft->source() === FlagSet::VULNERABLE || \in_array(FlagSet::VULNERABLE, $merged, true) ? $fixKind : null,
            $draft->reason(),
            $draft->replacement(),
            $kind === Move::FIND_ALTERNATIVE && $draft->source() === FlagSet::SILENT && $s2 !== null ? $s2->years() : null,
            $toBranch,
            $version,
            $row === null ? null : ($kind === Move::UPDATE ? $context->updateTarget($row) : (\in_array($kind, [Move::REQUIRE, Move::RAISE_PHP, Move::BLOCKED], true) ? $row->highest() : null)),
            $constraint,
            $latest,
            $phpCheck,
            $crosses,
            $heldBy,
            $clears,
            $unverified,
            $adds,
            $leaves,
            $ifApplied,
            $because,
            self::runnable(self::commands($kind, $subject, $constraint, $phpCheck, $latest, $version, $check, $checks)),
            $subject
        ))->withCheck($check);
    }

    /**
     * The Composer request of a move that runs one: `composer require` for a package that
     * composer.json requires, else `composer update` with the constraint inline.
     */
    private static function query(string $kind, MoveSubject $subject, ?string $constraint, ?string $version, ?PhpCheck $phpCheck): ?MoveQuery
    {
        if ($version === null) {
            return null;
        }
        $caret = '^'.ltrim($version, 'vV');
        $php = $phpCheck === null ? null : $phpCheck->raiseTo();
        switch ($kind) {
            case Move::UPDATE:
                return MoveQuery::update($subject->package(), $caret, $version);
            case Move::REQUIRE:
            case Move::RAISE_PHP:
                if ($constraint === null) {
                    return null;
                }

                return $subject->isDirect() ? MoveQuery::require($subject->package(), $constraint, $version, $php) : MoveQuery::update($subject->package(), $constraint, $version, $php);
            case Move::TAG:
                return $subject->isDirect() ? MoveQuery::require($subject->package(), $caret, $version) : MoveQuery::update($subject->package(), $caret, $version);
            default:
                return null;
        }
    }

    /** @return list<string> the counted maintenance flags in flag order */
    private static function countedMaintenance(Finding $finding): array
    {
        return array_map(static fn ($term): string => $term->flag(), $finding->score()->maintenanceTerms());
    }

    /**
     * What a move to $row from $lower clears of $flags and $advisories, for every version it admits
     * up to the row's newest, and what it cannot check.
     *
     * @param list<string> $flags
     * @param list<string> $advisories ids
     *
     * @return array{0: list<MoveFact>, 1: list<MoveFact>}
     */
    public static function clearsFor(BranchRow $row, ?Candidate $lower, array $flags, array $advisories, MoveContext $context): array
    {
        $clears = [];
        $unverified = [];
        if (\in_array(FlagSet::LEFT_BEHIND, $flags, true) && !$row->installed() && $context->isReleasing($context->releasedAt($row))) {
            $clears[] = MoveFact::on(MoveFact::FLAG, FlagSet::LEFT_BEHIND, MoveFact::BRANCH_RELEASING);
        }
        if (\in_array(FlagSet::OLD_PROMISE, $flags, true)) {
            $at = $lower === null ? null : $lower->release()->at();
            $ga = $context->ga();
            if ($at === null || $ga === null) {
                $unverified[] = MoveFact::unchecked(MoveFact::FLAG, FlagSet::OLD_PROMISE, MoveFact::UNDATED);
            } elseif ($at->format('Y-m-d') > $ga->format('Y-m-d')) {
                $clears[] = MoveFact::on(MoveFact::FLAG, FlagSet::OLD_PROMISE, MoveFact::RELEASED_AFTER_GA);
            }
        }
        $ranges = $context->ranges();
        $newest = $lower === null ? null : $context->release($row->highest());
        foreach ($advisories as $id) {
            $range = $ranges[$id] ?? null;
            if ($range === null) {
                continue;
            }
            if ($newest === null) {
                $unverified[] = MoveFact::unchecked(MoveFact::ADVISORY, $id, MoveFact::NO_RELEASE_DATA);
            } elseif (!MoveContext::reaches($range, $lower->release()->normalized(), $newest->normalized())) {
                $clears[] = MoveFact::on(MoveFact::ADVISORY, $id, MoveFact::OUTSIDE_RANGE);
            }
        }

        return [$clears, $unverified];
    }

    /**
     * What a tag move raises that counts, in flag order: left-behind on a line that stopped
     * releasing while a higher one does (S8's rule on the tag's row), and old-promise on a tag
     * released before the target major's GA with an open php. A fact lockrot cannot date is
     * unverified.
     *
     * @return array{0: list<MoveFact>, 1: list<MoveFact>}
     */
    private static function tagRisks(?BranchRow $row, ?Latest $latest, FlagSet $flags, MoveContext $context): array
    {
        $adds = [];
        $unverified = [];
        $quiet = static fn (string $flag): bool => !\in_array($flag, $flags->fired(), true) && !\in_array($flag, $flags->acceptSet(), true);
        $target = $context->subject()->targetPhp();
        $php = $row === null ? null : $row->php();
        if ($row !== null && $quiet(FlagSet::LEFT_BEHIND)) {
            $state = self::leftBehindOn($row, $context);
            if ($state === 'fires') {
                $adds[] = MoveFact::on(MoveFact::FLAG, FlagSet::LEFT_BEHIND, MoveFact::BRANCH_NOT_RELEASING);
            } elseif ($state === 'unknown') {
                $unverified[] = MoveFact::unchecked(MoveFact::FLAG, FlagSet::LEFT_BEHIND, MoveFact::UNDATED);
            }
        }
        if ($row !== null && $row->admitsTargetPhp() !== false && $quiet(FlagSet::OLD_PROMISE) && ($php === null || ($target !== null && (new ConstraintOpenness())->isOpenEndedFor($php, $target)))) {
            $released = $latest === null ? null : $latest->released();
            $ga = $context->ga();
            if ($released === null) {
                $unverified[] = MoveFact::unchecked(MoveFact::FLAG, FlagSet::OLD_PROMISE, MoveFact::UNDATED);
            } elseif ($ga !== null && (new \DateTimeImmutable($released))->format('Y-m-d') < $ga->format('Y-m-d')) {
                $adds[] = MoveFact::on(MoveFact::FLAG, FlagSet::OLD_PROMISE, MoveFact::RELEASED_BEFORE_GA);
            }
        }

        return [$adds, $unverified];
    }

    /**
     * S8's rule on one row, as if a release there were installed: `fires` when its newest release is
     * older than release-warn-years and a higher branch released within them, `unknown`
     * when a date it needs is missing, else `quiet`.
     */
    private static function leftBehindOn(BranchRow $row, MoveContext $context): string
    {
        $parser = new VersionParser();
        $own = $row->highestReleased();
        $above = array_filter($context->rows(), static fn (BranchRow $other): bool => version_compare($parser->normalize($other->highest()), $parser->normalize($row->highest()), '>'));
        $undatedAbove = array_filter($above, static fn (BranchRow $other): bool => $other->highestReleased() === null) !== [];
        $alive = array_filter($above, static fn (BranchRow $other): bool => $context->isReleasing($other->highestReleased()));
        if ($own === null) {
            return $alive !== [] || $undatedAbove ? 'unknown' : 'quiet';
        }
        if ($context->isReleasing($own)) {
            return 'quiet';
        }
        if ($alive !== []) {
            return 'fires';
        }

        return $undatedAbove ? 'unknown' : 'quiet';
    }

    /**
     * The engine rerun on the facts minus what the move clears and the accepted facts it settles,
     * plus what it adds and every unverified flag. A flag that fires stays as it is.
     *
     * @param list<MoveFact> $clears
     * @param list<MoveFact> $unverified
     * @param list<MoveFact> $adds
     * @param list<string>   $assumes
     */
    private static function rerun(MoveDraft $draft, array $clears, array $unverified, array $adds, MoveContext $context, array $assumes): IfApplied
    {
        $finding = $context->finding();
        $flags = $finding->flags();
        $removed = [];
        $advisories = [];
        foreach ($clears as $fact) {
            if ($fact->isFlag()) {
                $removed[] = $fact->id();
            } else {
                $advisories[] = $fact->id();
            }
        }
        $row = $draft->row();
        if ($row !== null) {
            foreach (self::clearsFor($row, $draft->lower(), $flags->accepted(), [], $context)[0] as $settled) {
                $removed[] = $settled->id();
            }
        }
        $raised = [];
        foreach (array_merge($adds, $unverified) as $fact) {
            if ($fact->isFlag()) {
                $raised[] = $fact->id();
            }
        }
        $score = $finding->score();

        return Counterfactual::afterMove($flags, $removed, $advisories, $raised, $score->reach(), $score->isDev(), $finding->isMaintenanceJudged(), $unverified !== [], $assumes);
    }

    /**
     * @param list<Holder> $heldBy
     *
     * @return list<string>
     */
    private static function because(string $kind, ?bool $crosses, array $heldBy, MoveSubject $subject): array
    {
        $held = array_filter($heldBy, static fn (Holder $holder): bool => $holder->source() === Holder::PACKAGE) !== [];
        $acts = \in_array($kind, self::ACTIONS, true) && !$subject->isDirect() && !$held;
        $because = [];
        if ($held) {
            $because[] = Move::HOLDERS;
        }
        if ($crosses === true && \in_array($kind, self::RESOLVED, true)) {
            $because[] = Move::CROSSES_MAJOR;
        }
        if ($acts) {
            $because[] = $subject->dependents() === [] ? Move::UNREACHED : Move::TRANSITIVE;
        }

        return $because;
    }

    /**
     * @param list<list<string>> $commands
     *
     * @return list<list<string>> the steps whose arguments pass {@see CommandArgument}
     */
    private static function runnable(array $commands): array
    {
        $runnable = [];
        foreach ($commands as $argv) {
            if (CommandArgument::allPass($argv)) {
                $runnable[] = $argv;
            }
        }

        return $runnable;
    }

    /**
     * The steps of the form that the check resolved, with its option. A refused move has none, and
     * a move that the check did not run on has the inline form.
     *
     * @return list<list<string>>
     */
    private static function commands(string $kind, MoveSubject $subject, ?string $constraint, ?PhpCheck $phpCheck, ?Latest $latest, ?string $version, ?MoveCheck $check, ?MoveChecks $checks): array
    {
        if ($check !== null && $check->isRefused()) {
            return [];
        }
        $option = $check === null ? [] : array_values(array_intersect_key(MoveCheck::OPTIONS, [(string) $check->form() => true]));
        $package = $subject->package();
        switch ($kind) {
            case Move::UPDATE:
                $inline = $checks !== null && $version !== null && $checks->writesInline($subject->isDirect());

                return [array_merge(['composer', 'update', $inline ? $package.':^'.ltrim((string) $version, 'vV') : $package], $option)];
            case Move::REQUIRE:
            case Move::RAISE_PHP:
                $commands = [];
                if ($phpCheck !== null && $phpCheck->raiseTo() !== null) {
                    $commands[] = ['composer', 'require', 'php:'.$phpCheck->raiseTo(), '--no-update'];
                }
                if ($constraint !== null) {
                    $commands = array_merge($commands, self::packageStep($subject, $constraint, $option, $check, $checks));
                }

                return $commands;
            case Move::TAG:
                return $latest === null ? [] : self::packageStep($subject, '^'.ltrim($latest->version(), 'vV'), $option, $check, $checks);
            default:
                return [];
        }
    }

    /**
     * `composer require` for a package that composer.json requires. A package that it does not
     * require gets no root requirement: when the check resolves its `update` request, the step is
     * that `composer update` with the constraint inline, on a Composer that takes one there.
     *
     * @param list<string> $option
     *
     * @return list<list<string>>
     */
    private static function packageStep(MoveSubject $subject, string $constraint, array $option, ?MoveCheck $check, ?MoveChecks $checks): array
    {
        $package = $subject->package();
        if ($subject->isDirect()) {
            return [array_merge(['composer', 'require', $package.':'.$constraint], $option)];
        }
        if ($check !== null && $check->isResolved() && $checks !== null && $checks->writesInline(false)) {
            return [array_merge(['composer', 'update', $package.':'.$constraint], $option)];
        }

        return [];
    }

    /**
     * @param list<MoveFact> $facts
     *
     * @return list<string> `kind id` keys
     */
    private static function keys(array $facts): array
    {
        return array_map(static fn (MoveFact $fact): string => $fact->kind().' '.$fact->id(), $facts);
    }

    private static function major(string $branch): string
    {
        return explode('.', $branch)[0];
    }
}
