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
        $phpCheck = null;
        if ($row !== null && (\in_array($kind, [Move::UPDATE, Move::REQUIRE, Move::RAISE_PHP, Move::BLOCKED], true) || $draft->source() === FlagSet::PINNED)) {
            // A blocked move names the PHP of the release that keeps it off the target, the others the release at the top of their constraint.
            $phpCheck = PhpCheck::of($kind === Move::BLOCKED ? ($lower === null ? $row->php() : $lower->php()) : $row->highestPhp(), $context->floor());
        }
        $latest = $draft->latest();
        $subject = $context->subject();
        $constraint = \in_array($kind, [Move::REQUIRE, Move::RAISE_PHP], true) ? $draft->constraint() : null;
        $version = \in_array($kind, Move::ROW_KINDS, true) ? ($lower === null ? null : $lower->release()->pretty()) : ($kind === Move::TAG && $latest !== null ? $latest->version() : null);
        $newest = $row === null ? null : ($kind === Move::UPDATE ? $context->updateTarget($row) : (\in_array($kind, [Move::REQUIRE, Move::RAISE_PHP, Move::BLOCKED], true) ? $row->highest() : null));
        $checks = $context->checks();
        $query = $checks === null ? null : self::query($kind, $subject, $constraint, $version, $phpCheck, $newest);
        $outcome = $query === null || $checks === null ? null : $checks->check($query);
        $check = $outcome === null ? null : $outcome->check();
        if ($check === null && $checks !== null && \in_array($kind, [Move::UPDATE, Move::REQUIRE, Move::RAISE_PHP, Move::TAG], true)) {
            $check = MoveCheck::notChecked(MoveCheck::NO_RELEASE_READ);
        }
        if ($check === null && $checks !== null && $kind === Move::BLOCKED && $lower !== null && $checks->refused($subject->package())->refuses($lower->release()->normalized())) {
            $check = MoveCheck::refused();
        }
        // A solve that installs a release below the row's newest clears what that release clears.
        $installs = $row === null || $check === null || !$draft->isRowMove() ? null : $check->installsBelow($row->highest());
        $below = $installs === null ? null : $context->release($installs);
        $judged = $below === null || $row === null ? $row : (BranchRow::ofRelease($below, $row->installed(), $context->floor()) ?? $row);
        if ($draft->isRowMove() && $judged !== null) {
            [$clears, $unverified] = self::clearsFor($judged, $lower, self::countedMaintenance($finding), $context->advisoryIds(), $context, $row);
        }
        if ($kind === Move::TAG) {
            $clears = [MoveFact::on(MoveFact::FLAG, FlagSet::PINNED, MoveFact::TAGS_ONLY)];
            [$adds, $unverified] = self::tagRisks($row, $latest, $flags, $context);
            if ($row !== null && \in_array(FlagSet::CAPPED, self::countedMaintenance($finding), true)) {
                [$cappedClears, $cappedUnverified] = self::cappedOn($row, $context);
                $clears = array_merge($clears, $cappedClears);
                $unverified = array_merge($unverified, $cappedUnverified);
            }
        }
        if ($row !== null && \in_array($kind, array_merge(Move::ROW_KINDS, [Move::TAG]), true) && !\in_array(FlagSet::CAPPED, $flags->fired(), true)
            && !\in_array(FlagSet::CAPPED, $flags->acceptSet(), true) && $judged !== null && $context->phpCap()->stopsBelow($judged->php()) === true) {
            $adds[] = MoveFact::on(MoveFact::FLAG, FlagSet::CAPPED, MoveFact::STOPS_BELOW_NEWEST_PHP);
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
        $joint = $outcome !== null && $outcome->isJoint();
        $heldBy = $lower !== null && $kind !== Move::TAG && !$joint ? $lower->heldBy() : [];
        if ($outcome !== null && $outcome->check()->isRefused()) {
            $heldBy = Holder::distinct(array_merge($heldBy, $outcome->holds()));
        }
        $movesWith = $joint ? $outcome->movesWith() : [];
        $because = self::because($kind, $crosses, $heldBy, $subject);
        $ifApplied = null;
        // A solve below the newest release can clear nothing: the score after such a move is the current total (SPEC I14).
        if ($clears !== [] && \in_array($kind, [Move::UPDATE, Move::REQUIRE, Move::RAISE_PHP, Move::TAG], true)) {
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
            $newest,
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
            self::runnable(self::commands($kind, $subject, $constraint, $phpCheck, $latest, $version, $check, $checks, array_column($movesWith, 'package'))),
            $subject
        ))->withCheck($check)->withMovesWith($movesWith);
    }

    /**
     * The Composer request of a move that runs one: `composer require` for a package that
     * composer.json requires, else `composer update` with the constraint inline.
     */
    private static function query(string $kind, MoveSubject $subject, ?string $constraint, ?string $version, ?PhpCheck $phpCheck, ?string $newest): ?MoveQuery
    {
        if ($version === null) {
            return null;
        }
        $caret = '^'.ltrim($version, 'vV');
        $php = $phpCheck === null ? null : $phpCheck->raiseTo();
        $package = $subject->package();
        switch ($kind) {
            case Move::UPDATE:
                return MoveQuery::update($package, $caret, $version, $php, $newest);
            case Move::REQUIRE:
            case Move::RAISE_PHP:
                return $constraint === null ? null : self::requireOrUpdate($subject, $constraint, $version, $php, $newest);
            case Move::TAG:
                return self::requireOrUpdate($subject, $caret, $version, $php, null);
            default:
                return null;
        }
    }

    /** `composer require` for a package that composer.json lists, in the section that lists it, else `composer update`. */
    private static function requireOrUpdate(MoveSubject $subject, string $constraint, string $version, ?string $php, ?string $newest): MoveQuery
    {
        if (!$subject->isListed()) {
            return MoveQuery::update($subject->package(), $constraint, $version, $php, $newest);
        }
        return MoveQuery::require($subject->package(), $constraint, $version, $php, $newest)->withRequireDev($subject->isListedInRequireDevOnly());
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
     * @param ?BranchRow   $branch     the branch row of $row, which dates the branch as S8 does: a row
     *                                 of a release below the newest is dated by the branch's newest release
     *
     * @return array{0: list<MoveFact>, 1: list<MoveFact>}
     */
    public static function clearsFor(BranchRow $row, ?Candidate $lower, array $flags, array $advisories, MoveContext $context, ?BranchRow $branch = null): array
    {
        $clears = [];
        $unverified = [];
        if (\in_array(FlagSet::LEFT_BEHIND, $flags, true) && !$row->installed() && $context->isReleasing($context->releasedAt($branch ?? $row))) {
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
        if (\in_array(FlagSet::CAPPED, $flags, true)) {
            [$cappedClears, $cappedUnverified] = self::cappedOn($row, $context);
            $clears = array_merge($clears, $cappedClears);
            $unverified = array_merge($unverified, $cappedUnverified);
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
     * Whether the row's newest release admits R: it clears `capped`, and a release that states no
     * php leaves it unverified.
     *
     * @return array{0: list<MoveFact>, 1: list<MoveFact>}
     */
    private static function cappedOn(BranchRow $row, MoveContext $context): array
    {
        $below = $context->phpCap()->stopsBelow($row->php());
        if ($below === null) {
            return [[], [MoveFact::unchecked(MoveFact::FLAG, FlagSet::CAPPED, MoveFact::NO_RELEASE_DATA)]];
        }

        return [$below ? [] : [MoveFact::on(MoveFact::FLAG, FlagSet::CAPPED, MoveFact::ADMITS_NEWEST_PHP)], []];
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
     * The steps of the form that the check resolved, with its option, then the `composer why-not`
     * step of a move that the check does not resolve. A refused move has no other step, and a move
     * that the check did not run on has the inline form. A move that a joint request resolves
     * updates its holders in the same step.
     *
     * @param list<string> $holders the holders that the joint request moves
     *
     * @return list<list<string>>
     */
    private static function commands(string $kind, MoveSubject $subject, ?string $constraint, ?PhpCheck $phpCheck, ?Latest $latest, ?string $version, ?MoveCheck $check, ?MoveChecks $checks, array $holders): array
    {
        $whyNot = \in_array($kind, [Move::UPDATE, Move::REQUIRE, Move::RAISE_PHP, Move::TAG], true) && $version !== null && ($check === null || !$check->isResolved())
            ? [['composer', 'why-not', $subject->package(), $version, '--locked']]
            : [];
        $steps = $check !== null && $check->isRefused() ? [] : self::steps($kind, $subject, $constraint, $phpCheck, $latest, $version, $check, $checks, $holders);

        return array_merge($steps, $whyNot);
    }

    /**
     * @param list<string> $holders
     *
     * @return list<list<string>>
     */
    private static function steps(string $kind, MoveSubject $subject, ?string $constraint, ?PhpCheck $phpCheck, ?Latest $latest, ?string $version, ?MoveCheck $check, ?MoveChecks $checks, array $holders): array
    {
        $form = $check === null ? null : $check->form();
        $option = $form !== null && isset(MoveCheck::OPTIONS[$form]) ? [MoveCheck::OPTIONS[$form]] : [];
        $package = $subject->package();
        $raise = $phpCheck !== null && $phpCheck->raiseTo() !== null ? [['composer', 'require', 'php:'.$phpCheck->raiseTo(), '--no-update']] : [];
        switch ($kind) {
            case Move::UPDATE:
                $inline = $checks !== null && $version !== null && $checks->writesInline($subject->isListed());

                return array_merge($raise, [array_merge(['composer', 'update', $inline ? $package.':^'.ltrim((string) $version, 'vV') : $package], $holders, $option)]);
            case Move::REQUIRE:
            case Move::RAISE_PHP:
                return $constraint === null ? $raise : array_merge($raise, self::packageStep($subject, $constraint, $option, $check, $checks, $holders));
            case Move::TAG:
                return $latest === null ? [] : array_merge($raise, self::packageStep($subject, '^'.ltrim($latest->version(), 'vV'), $option, $check, $checks, $holders));
            default:
                return [];
        }
    }

    /**
     * `composer require` for a package that composer.json lists, with `--dev` when only
     * `require-dev` lists it. A package that it does not list gets no root requirement: when the
     * check resolves its `update` request, the step is that `composer update` with the constraint
     * inline, on a Composer that takes one there. With holders, a listed package's step writes the
     * requirement with `--no-update` and updates the package with its holders.
     *
     * @param list<string> $option
     * @param list<string> $holders
     *
     * @return list<list<string>>
     */
    private static function packageStep(MoveSubject $subject, string $constraint, array $option, ?MoveCheck $check, ?MoveChecks $checks, array $holders): array
    {
        $package = $subject->package();
        if ($subject->isListed()) {
            $dev = $subject->isListedInRequireDevOnly() ? ['--dev'] : [];
            if ($holders !== []) {
                return [array_merge(['composer', 'require', $package.':'.$constraint], $dev, ['--no-update']), array_merge(['composer', 'update', $package], $holders)];
            }

            return [array_merge(['composer', 'require', $package.':'.$constraint], $dev, $option)];
        }
        if ($check !== null && $check->isResolved() && $checks !== null && $checks->writesInline(false)) {
            return [array_merge(['composer', 'update', $package.':'.$constraint], $holders, $option)];
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
