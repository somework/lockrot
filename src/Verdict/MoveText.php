<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Security\Holder;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveCheckers;

/**
 * `next_step.text`, text grammar 1: rendered from the move object, the package and the run alone.
 * A renderer that meets an unknown kind prints it.
 *
 * @internal
 */
final class MoveText
{
    /** A sentence names at most this many direct dependents, then "and N more". */
    public const DEPENDENTS_SHOWN = 3;

    private const NO_MOVE = [
        'package_quiet' => '—',
        'higher_undated' => 'no move: lockrot could not date the releases of the higher branches',
        'no_higher_release' => 'no move: no higher branch released within release-warn-years',
        'releases_unknown' => 'no move: lockrot could not read its releases, so it names no fix',
        'affected_range_unknown' => 'no move: no advisory names the versions it affects, so lockrot names no fix',
        'metadata_not_read' => 'no move: lockrot did not read its releases, so it names none',
        'local_package' => 'no move: it is installed from a local path (your own package)',
    ];


    private const ADDS = [
        MoveFact::RELEASED_BEFORE_GA => "it was released before your target PHP's major",
        MoveFact::BRANCH_NOT_RELEASING => 'that line no longer releases while a higher line does',
        MoveFact::STOPS_BELOW_NEWEST_PHP => 'it stops below the newest PHP',
    ];

    public static function render(Move $move): string
    {
        $subject = $move->subject();
        $kind = $move->kind();
        $text = self::head($move, $subject);
        if (\in_array($kind, array_merge(Move::ROW_KINDS, [Move::TAG]), true)) {
            $text .= self::leaves($move).self::unverified($move);
        }
        $holders = array_map(static fn (Holder $holder): string => self::holderWords($holder, $subject), self::inTextOrder($move->heldBy(), $subject->package()));
        if ($holders !== []) {
            $text .= '; held by '.implode(' and ', $holders);
        }
        foreach ($move->composerMovesBecause() as $reason) {
            $text .= '; '.self::because($reason, $move, $subject);
        }
        $through = self::throughWords($move->through());
        if ($through !== '' && \in_array(Move::TRANSITIVE, $move->composerMovesBecause(), true)) {
            $text .= '; '.$through;
        }

        $step = $move->commands() === [] ? '' : self::platformStep($subject->targetPhp(), $subject->platformOverride(), $subject->refusedTargetInside());

        return $step.$text.self::checked($move->check());
    }

    /**
     * SPEC §5.6: while the lock records a platform PHP outside the target minor, Composer solves
     * the commands at that PHP, not at the target. The sentence and a space, else an empty string.
     * While Composer refuses the lock itself, the step alone does not make it install.
     *
     * @param ?string $platformOverride    `run.php_window.platform_override`
     * @param ?bool   $refusedTargetInside `run.php_window.target.inside` while `run.move_check.baseline` is `refused`, else null
     */
    public static function platformStep(?string $targetPhp, ?string $platformOverride, ?bool $refusedTargetInside = null): string
    {
        if ($targetPhp === null || $platformOverride === null || MoveCheckers::platformPhp($targetPhp, $platformOverride) === $platformOverride) {
            return '';
        }
        $refused = $refusedTargetInside === null ? '' : ' Composer still refuses the lock itself: '.($refusedTargetInside ? 'the Run tab names the cause.' : 'the PHP line names the packages.');

        return 'First set config.platform.php to your PHP '.$targetPhp.' release: the lock records '.$platformOverride.'.'.$refused.' ';
    }

    /** D10: what Composer's solver said about the move, or why lockrot did not ask it. */
    private static function checked(?MoveCheck $check): string
    {
        if ($check === null) {
            return '';
        }
        switch ($check->result()) {
            case MoveCheck::RESOLVES:
                return "; Composer's solver checked it against the lock";
            case MoveCheck::REFUSED:
                return "; Composer's solver refuses it against the lock";
            default:
                return '; not checked with Composer\'s solver ('.MoveCheck::reasonWords((string) $check->reason()).'), so it holds only if Composer resolves it';
        }
    }

    /** @param list<string> $names */
    private static function dependents(array $names): string
    {
        if (\count($names) > self::DEPENDENTS_SHOWN) {
            return implode(', ', \array_slice($names, 0, self::DEPENDENTS_SHOWN)).' and '.(\count($names) - self::DEPENDENTS_SHOWN).' more';
        }

        return implode(', ', $names);
    }

    private static function head(Move $move, MoveSubject $subject): string
    {
        switch ($move->kind()) {
            case Move::REPLACE:
                return self::replace($move);
            case Move::FIND_ALTERNATIVE:
                return $move->source() === FlagSet::ABANDONED ? 'find a replacement (none named)' : 'find a maintained alternative: nothing released for '.self::years($move->quietYears()).' years';
            case Move::TAG:
                return self::tag($move, $subject);
            case Move::TEST:
                $latest = $move->latest();

                return 'test it on PHP '.$subject->targetPhp().($latest !== null && $latest->isInstalled() ? ' — '.$latest->version().' is its newest release' : '');
            case Move::UPDATE:
                return self::raise($move).self::update($move).' (gets '.$move->newest().')'.self::clears($move).self::adds($move);
            case Move::REQUIRE:
            case Move::RAISE_PHP:
                return self::requirement($move, $subject);
            case Move::BLOCKED:
                return self::blocked($move, $subject);
            case Move::NO_FIX:
                return 'no release fixes it';
            case Move::NO_SINGLE_FIX:
                return 'no single release fixes every advisory; see each advisory';
            case Move::NO_TAG:
                return 'no tagged release to move to; review the snapshot, or replace it';
            case Move::NO_MOVE:
                $reason = $move->reason() ?? '';
                if ($reason === 'no_release_admits') {
                    return 'no move: no release above '.$subject->version().' admits PHP '.$subject->newestPhp();
                }

                return (self::NO_MOVE[$reason] ?? $reason).($reason === 'metadata_not_read' && $move->source() === FlagSet::PINNED ? '; review the snapshot' : '');
            default:
                return $move->kind();
        }
    }

    /** The POSIX rendering of the move's `composer update` step, or words when the command argument rule refused it. */
    private static function update(Move $move): string
    {
        foreach ($move->commands() as $argv) {
            $rendered = $argv[1] === 'update' ? ShellCommand::posix($argv) : null;
            if ($rendered !== null) {
                return $rendered;
            }
        }

        return 'update it with Composer';
    }

    private static function replace(Move $move): string
    {
        $replacement = $move->replacement();
        if ($replacement === null) {
            return 'replace it';
        }
        $byRegistry = $replacement->namedBy() === 'repository';
        $package = $replacement->package();
        if ($package === null) {
            return 'replace it: '.($byRegistry ? 'its registry' : 'composer.lock').' suggests "'.$replacement->suggestion().'"';
        }
        $finding = $replacement->finding();

        return 'replace with '.$package.' ('.($byRegistry ? 'named by its registry' : 'named in composer.lock')
            .($finding === null ? '' : '; itself '.$finding->verdict().': '.implode(' · ', $finding->flagIds())).')';
    }

    private static function tag(Move $move, MoveSubject $subject): string
    {
        $latest = $move->latest();
        $version = $latest === null ? '' : $latest->version();
        $ownLine = $move->toBranch() !== null && $move->crossesMajor() === false && self::isBranchAlias($subject->version());
        $word = $ownLine ? 'the newest on its own line' : 'the newest';
        if ($subject->isDirect()) {
            $text = self::raise($move).'require a tag — '.$word.' is '.$version;
        } else {
            $via = self::dependents($subject->dependents());
            $text = self::raise($move).'move it to a tag — '.$word.' is '.$version.($via === '' ? '' : ', through '.$via);
        }
        $released = $latest === null ? null : $latest->released();
        $text .= $released === null ? '' : ' ('.substr($released, 0, 10).')';
        if ($latest !== null && $latest->relation() === 'older') {
            $text .= ', older than your snapshot ('.substr((string) $latest->snapshotTime(), 0, 10).'); review, or replace';
        }
        return $text.self::phpClause($move, $subject, false).self::adds($move);
    }

    private static function requirement(Move $move, MoveSubject $subject): string
    {
        $raise = self::raise($move);
        if ($subject->isDirect()) {
            $text = $raise.'require '.$subject->package().' '.$move->constraint();
        } else {
            $via = self::dependents($subject->dependents());
            $text = $raise.'move '.$subject->package().' to '.$move->constraint().($via === '' ? '' : ' through '.$via);
        }

        return $text.self::clears($move).self::adds($move).self::phpClause($move, $subject, true);
    }

    private static function raise(Move $move): string
    {
        $check = $move->phpCheck();

        return $check !== null && $check->raiseTo() !== null ? 'raise require.php to '.$check->raiseTo().', then ' : '';
    }

    /** One clause per basis of `adds[]`: what the move raises that would count. */
    private static function adds(Move $move): string
    {
        $text = '';
        foreach (self::addsByBasis($move) as $basis => $ids) {
            $text .= '; '.(self::ADDS[$basis] ?? $basis).', so '.implode(' and ', $ids).' would count';
        }

        return $text;
    }

    private static function blocked(Move $move, MoveSubject $subject): string
    {
        $latest = $move->latest();
        $where = $latest !== null ? 'the newest tag, '.$latest->version().',' : ($move->toBranch() ?? 'the fix');
        $check = $move->phpCheck();
        $requires = $check === null ? null : $check->requires();
        $solve = $move->check();
        if ($solve !== null && $solve->isRefused() && ($check === null || $check->targetRuns() !== false)) {
            return 'no move installs '.$where.': Composer names no hold';
        }
        $text = $requires !== null
            ? 'no move runs on the target: '.$where.' needs php '.$requires.' and the target php '.$subject->targetPhp().' does not run it'
            : 'no move runs on the target: the target php '.$subject->targetPhp().' does not run '.$where;

        return $text.($move->source() === FlagSet::PINNED ? '; replace it' : '');
    }

    private static function phpClause(Move $move, MoveSubject $subject, bool $runs): string
    {
        $check = $move->phpCheck();
        $requires = $check === null ? null : $check->requires();
        if ($check === null || $requires === null) {
            return '';
        }
        $text = '; '.$move->toBranch().' needs php '.$requires;
        if ($check->projectAllows() === true) {
            $text .= ', which require.php allows';
        } elseif ($check->raiseSize() !== null) {
            $text .= ', require.php allows '.self::php((string) $subject->projectPhpLowest()).' (a '.$check->raiseSize().' raise)';
        }

        return $text.($runs ? '; the target php '.$subject->targetPhp().' runs it' : '');
    }

    private static function clears(Move $move): string
    {
        $parts = [];
        $advisories = 0;
        foreach ($move->clears() as $fact) {
            if (!$fact->isFlag()) {
                ++$advisories;
            } elseif ($fact->id() !== FlagSet::PINNED) {
                $parts[] = $fact->id();
            }
        }
        if ($advisories > 0) {
            $parts[] = $advisories === 1 ? 'the advisory' : $advisories.' advisories';
        }

        return $parts === [] ? '' : ' — clears '.self::join($parts);
    }

    private static function leaves(Move $move): string
    {
        $flags = [];
        $advisories = 0;
        foreach ($move->leaves() as $fact) {
            if ($fact->isFlag()) {
                $flags[] = $fact->id();
            } else {
                ++$advisories;
            }
        }
        $items = array_merge($flags, $advisories > 0 ? [$advisories.' advisor'.($advisories === 1 ? 'y' : 'ies')] : []);
        if ($items === []) {
            return '';
        }

        return '; '.implode(', ', $items).' '.(\count($items) === 1 && $advisories < 2 ? 'stays' : 'stay');
    }

    private static function unverified(Move $move): string
    {
        $ids = array_map(static fn (MoveFact $fact): string => $fact->id(), $move->unverified());

        return $ids === [] ? '' : '; not checked: '.implode(', ', $ids);
    }

    private static function holderWords(Holder $holder, MoveSubject $subject): string
    {
        $link = self::linkWords($holder);
        if ($holder->source() === Holder::ROOT) {
            return 'composer.json ('.$link.')';
        }
        // The finding's own release: its grade and flags are the installed version's, not this one's.
        if ($holder->package() === $subject->package()) {
            return $holder->package().' '.$holder->version().', its own release ('.$link.')';
        }
        $bits = [$link];
        $finding = $holder->finding();
        if ($finding !== null && $finding->isGraded()) {
            $bits[] = $finding->lead() !== null ? 'itself '.$finding->lead() : 'itself '.$finding->verdict().': '.implode(' · ', $finding->flagIds());
        }

        return $holder->package().' '.$holder->version().' ('.implode('; ', $bits).')';
    }

    /**
     * The root holds, then the holds of the package's own releases, then the package holds whose
     * target is the package, then the other holds. Within a group: by the holder's grade, then by
     * name, then in `held_by[]` order.
     *
     * @param list<Holder> $holders
     *
     * @return list<Holder>
     */
    public static function inTextOrder(array $holders, string $package): array
    {
        $root = [];
        $own = [];
        $on = [];
        $other = [];
        foreach ($holders as $holder) {
            if ($holder->source() === Holder::ROOT) {
                $root[] = $holder;
            } elseif ($holder->package() === $package) {
                $own[] = $holder;
            } elseif ($holder->target() === null || $holder->target() === $package) {
                $on[] = $holder;
            } else {
                $other[] = $holder;
            }
        }

        return array_merge($root, $own, self::ranked($on), self::ranked($other));
    }

    /**
     * @param list<Holder> $holders
     *
     * @return list<Holder> by the holder's grade, then by name, then in the given order
     */
    private static function ranked(array $holders): array
    {
        $grades = array_flip(ScoreModel::GRADES);
        $keys = [];
        foreach ($holders as $index => $holder) {
            $finding = $holder->finding();
            $keys[$index] = [$finding === null ? \count($grades) : $grades[$finding->verdict()] ?? \count($grades), $holder->package(), $index];
        }
        uksort($holders, static fn (int $a, int $b): int => $keys[$a] <=> $keys[$b]);

        return array_values($holders);
    }

    /**
     * `requires ^1.0`, `conflicts with <5.3`, `requires symfony/console ^4.4`: the link and its target.
     *
     * @param ?callable(string): string $name marks the target as the format marks a package name
     */
    public static function linkWords(Holder $holder, ?callable $name = null): string
    {
        $target = $holder->target();
        if ($holder->link() === Holder::ROOT_REQUIRE && $target === null) {
            return 'no release of '.$holder->constraint().' can install';
        }
        if ($holder->link() === Holder::REPLACE && $target === null) {
            return 'replaces it';
        }
        $verb = ['conflict' => 'conflicts with ', 'replace' => 'replaces '][$holder->link()] ?? 'requires ';

        return $verb.($target === null ? '' : ($name === null ? $target : $name($target)).' ').$holder->constraint().($holder->link() === Holder::REQUIRE_DEV ? ' (require-dev)' : '');
    }

    private static function because(string $reason, Move $move, MoveSubject $subject): string
    {
        $resolved = \in_array($move->kind(), [Move::REQUIRE, Move::RAISE_PHP, Move::TAG], true);
        switch ($reason) {
            case Move::HOLDERS:
                return 'the packages that hold it may need to move too';
            case Move::CROSSES_MAJOR:
                $check = $move->check();
                $solved = $check !== null && $check->result() !== MoveCheck::NOT_CHECKED;

                return (strpos((string) $move->toBranch(), '0.') === 0 ? 'a new 0.x line (Composer treats 0.x minors as breaking)' : 'a new major').' brings its own requirements'.($solved ? '' : ', which lockrot does not solve');
            case Move::TRANSITIVE:
                $dependents = $subject->dependents();

                return $resolved ? 'what requires it must allow it' : 'it comes in through '.self::dependents($dependents).': replace or update '.(\count($dependents) === 1 ? 'that package' : 'them');
            default:
                return $resolved ? 'lockrot found no path from your requirements to it, so what requires it must allow it' : 'lockrot found no path from your requirements to it: `composer why` names what requires it';
        }
    }

    /** @param list<ThroughEntry> $through */
    private static function throughWords(array $through): string
    {
        $gone = array_filter($through, static fn (ThroughEntry $entry): bool => $entry->newestRequires() === false);
        $kept = array_filter($through, static fn (ThroughEntry $entry): bool => $entry->newestRequires() === true);
        $entries = $gone !== [] ? $gone : $kept;
        $names = array_map(static fn (ThroughEntry $entry): string => $entry->package().' ('.$entry->newestVersion().')', \array_slice($entries, 0, self::DEPENDENTS_SHOWN));
        if ($names === []) {
            return '';
        }
        $one = \count($entries) === 1;
        $more = \count($entries) - self::DEPENDENTS_SHOWN;
        $list = $more > 0 ? implode(', ', $names).' and '.$more.' more' : self::join($names);
        $verb = $gone !== [] ? ($one ? 'no longer requires' : 'no longer require') : ($one ? 'still requires' : 'still require');

        return 'the newest release'.($one ? '' : 's').' of '.$list.' '.$verb.' it';
    }

    /** @return array<string, list<string>> the ids of `adds[]` by basis, in order */
    private static function addsByBasis(Move $move): array
    {
        $by = [];
        foreach ($move->adds() as $fact) {
            $by[$fact->basis() ?? ''][] = $fact->id();
        }

        return $by;
    }

    /** @param non-empty-list<string> $parts */
    private static function join(array $parts): string
    {
        $last = array_pop($parts);

        return $parts === [] ? $last : implode(', ', $parts).' and '.$last;
    }

    private static function years(?float $years): string
    {
        return $years === null ? '' : number_format($years, 1, '.', '');
    }

    /** `run.project_php_lowest` as a sentence prints it: a trailing `.0` patch is dropped. */
    private static function php(string $lowest): string
    {
        return preg_replace('/^(\d+\.\d+)\.0$/', '$1', $lowest) ?? $lowest;
    }

    /** A branch-alias snapshot (`5.4.x-dev`) sits on its own line. */
    public static function isBranchAlias(string $version): bool
    {
        return ReleaseBranch::aliasLine($version) !== null;
    }
}
