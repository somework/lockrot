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

    /** What the platform step adds while Composer refuses the lock itself. */
    public const REFUSED_POINTER = ' Composer still refuses the lock itself: the moves_not_checked note names the first cause.';

    /** Composer's `require --no-interaction` of a name that both sections list writes `require` and deletes the require-dev line. */
    private const REMOVES_DEV = ', which removes it from require-dev';

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
        return implode('', array_column(self::parts($move), 0));
    }

    /**
     * `next_step.text` as pieces, each with whether it is a command: the pieces concatenate to the
     * text. At most one piece is a command, the POSIX rendering of an argv of `commands`. A text
     * format prints it in its command form, never through a prose transform. No piece is empty or
     * only whitespace.
     *
     * @return list<array{0: string, 1: bool}>
     */
    public static function parts(Move $move): array
    {
        $subject = $move->subject();
        $kind = $move->kind();
        $parts = self::head($move, $subject);
        $text = '';
        if (\in_array($kind, array_merge(Move::ROW_KINDS, [Move::TAG]), true)) {
            $text .= self::leaves($move).self::unverified($move);
        }
        $holders = array_map(static fn (Holder $holder): string => self::holderWords($holder, $subject), self::inTextOrder($move->heldBy(), $subject->package()));
        if ($holders !== []) {
            $text .= '; held by '.ListWords::of($holders);
        }
        foreach ($move->composerMovesBecause() as $reason) {
            $words = self::because($reason, $move, $subject);
            $text .= $words === '' ? '' : '; '.$words;
        }
        $through = self::throughWords($move->through());
        if ($through !== '' && \in_array(Move::TRANSITIVE, $move->composerMovesBecause(), true)) {
            $text .= '; '.$through;
        }
        $parts[] = [$text.self::checked($move->check()).self::movesWith($move), false];
        $step = $move->commands() === [] ? '' : self::platformStep($subject->targetPhp(), $subject->platformOverride(), $subject->isBaselineRefused());
        array_unshift($parts, [$step, false]);

        return self::joined($parts);
    }

    /**
     * Neighbouring prose pieces as one, with the empty pieces dropped.
     *
     * @param list<array{0: string, 1: bool}> $parts
     *
     * @return list<array{0: string, 1: bool}>
     */
    private static function joined(array $parts): array
    {
        $joined = [];
        foreach ($parts as [$text, $isCommand]) {
            if ($text === '') {
                continue;
            }
            $last = array_pop($joined);
            if ($last !== null && !$isCommand && !$last[1]) {
                $joined[] = [$last[0].$text, false];
                continue;
            }
            if ($last !== null) {
                $joined[] = $last;
            }
            $joined[] = [$text, $isCommand];
        }

        return $joined;
    }

    /**
     * SPEC §5.6: while the lock records a platform PHP outside the target minor, Composer solves
     * the commands at that PHP, not at the target. The sentence and a space, else an empty string.
     * While Composer refuses the lock itself, the step alone does not make it install: the sentence
     * points at the moves_not_checked note.
     *
     * @param ?string $platformOverride `run.php_window.platform_override`
     * @param bool    $baselineRefused  `run.move_check.baseline` is `refused`
     */
    public static function platformStep(?string $targetPhp, ?string $platformOverride, bool $baselineRefused = false): string
    {
        if ($targetPhp === null || $platformOverride === null || MoveCheckers::platformPhp($targetPhp, $platformOverride) === $platformOverride) {
            return '';
        }
        return 'First set config.platform.php to your PHP '.$targetPhp.' release: the lock records '.$platformOverride.'.'.($baselineRefused ? self::REFUSED_POINTER : '').' ';
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

    /** ", with an update of h/x (to 2.1.0)", one entry per holder that the joint request moves. */
    private static function movesWith(Move $move): string
    {
        $parts = [];
        foreach ($move->movesWith() as $moved) {
            $parts[] = $moved['package'].' (to '.$moved['version'].')';
        }

        return $parts === [] ? '' : ', with an update of '.ListWords::of($parts);
    }

    /** @param list<string> $names */
    private static function dependents(array $names): string
    {
        $more = \count($names) - self::DEPENDENTS_SHOWN;

        return ListWords::of(array_merge(\array_slice($names, 0, self::DEPENDENTS_SHOWN), $more > 0 ? [$more.' more'] : []));
    }

    /** @return list<array{0: string, 1: bool}> */
    private static function head(Move $move, MoveSubject $subject): array
    {
        if ($move->kind() === Move::UPDATE) {
            $below = self::below($move, $move->newest());
            $check = $move->check();
            $gets = ($check === null ? null : $check->installsAbove($move->newest())) ?? $move->newest();
            $command = self::update($move);

            return [
                [self::raise($move), false],
                [$command ?? 'update it with Composer', $command !== null],
                [($below === '' ? ' (gets '.$gets.')' : $below).self::clears($move).self::adds($move), false],
            ];
        }

        return [[self::prose($move, $subject), false]];
    }

    private static function prose(Move $move, MoveSubject $subject): string
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

    /** The POSIX rendering of the move's first `composer update` step that the command argument rule takes. */
    private static function update(Move $move): ?string
    {
        foreach ($move->commands() as $argv) {
            $rendered = $argv[1] === 'update' ? ShellCommand::posix($argv) : null;
            if ($rendered !== null) {
                return $rendered;
            }
        }

        return null;
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
        if ($subject->isListed()) {
            $text = self::raise($move).'require a tag'.self::inRequireDev($subject).' — '.$word.' is '.$version;
        } else {
            $via = self::dependents($subject->dependents());
            $text = self::raise($move).'move it to a tag — '.$word.' is '.$version.($via === '' ? '' : ', through '.$via);
        }
        $released = $latest === null ? null : $latest->released();
        $text .= ($released === null ? '' : ' ('.substr($released, 0, 10).')').self::below($move, $version);
        if ($latest !== null && $latest->relation() === 'older') {
            $text .= ', older than your snapshot ('.substr((string) $latest->snapshotTime(), 0, 10).'); review, or replace';
        }
        return $text.self::phpClause($move, $subject, false).self::adds($move);
    }

    private static function requirement(Move $move, MoveSubject $subject): string
    {
        $raise = self::raise($move);
        if ($subject->isListed()) {
            $text = $raise.'require '.$subject->package().' '.$move->constraint().self::inRequireDev($subject);
        } else {
            $via = self::dependents($subject->dependents());
            $text = $raise.'move '.$subject->package().' to '.$move->constraint().($via === '' ? '' : ' through '.$via);
        }

        return $text.self::below($move, $move->newest()).self::clears($move).self::adds($move).self::phpClause($move, $subject, true);
    }

    /**
     * " in require-dev" for a package that only require-dev lists, when the run does not require it
     * directly. The require of a name that both sections list removes its require-dev line.
     */
    private static function inRequireDev(MoveSubject $subject): string
    {
        if ($subject->isListedInBoth()) {
            return self::REMOVES_DEV;
        }

        return $subject->isListedInRequireDevOnly() && !$subject->isDirect() ? ' in require-dev' : '';
    }

    /** " (gets 5.3.8, below the newest 5.3.9)" when the resolving form installs a release below `$newest`, else an empty string. */
    private static function below(Move $move, ?string $newest): string
    {
        $check = $move->check();
        $installs = $check === null ? null : $check->installsBelow($newest);

        return $installs === null ? '' : ' (gets '.$installs.', below the newest '.$newest.')';
    }

    private static function raise(Move $move): string
    {
        $check = $move->phpCheck();
        if ($check === null || $check->raiseTo() === null) {
            return '';
        }

        return self::raiseWords($check->raiseTo(), $move->subject()->isPhpInRequireDev());
    }

    /** The prose of a require.php raise before a step. */
    public static function raiseWords(string $to, bool $phpInRequireDev): string
    {
        return 'raise require.php to '.$to.($phpInRequireDev ? self::REMOVES_DEV : '').', then ';
    }

    /** One clause per basis of `adds[]`: what the move raises that would count. */
    private static function adds(Move $move): string
    {
        $text = '';
        foreach (self::addsByBasis($move) as $basis => $ids) {
            $text .= '; '.(self::ADDS[$basis] ?? $basis).', so '.ListWords::of($ids).' would count';
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

        return $parts === [] ? '' : ' — clears '.ListWords::of($parts);
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

        return '; '.ListWords::of($items).' '.(\count($items) === 1 && $advisories < 2 ? 'stays' : 'stay');
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

        return array_merge($root, self::ranked($own), self::ranked($on), self::ranked($other));
    }

    /**
     * @param list<Holder> $holders
     *
     * @return list<Holder> by the holder's grade, then by name, then in the given order
     */
    private static function ranked(array $holders): array
    {
        $grades = array_flip(ScoreModel::GRADES);
        $rows = [];
        foreach ($holders as $index => $holder) {
            $finding = $holder->finding();
            $rows[] = [[$finding === null ? \count($grades) : $grades[$finding->verdict()] ?? \count($grades), $holder->package(), $index], $holder];
        }
        usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_column($rows, 1);
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

        $constraint = $holder->constraint() === 'self.version' && $holder->version() !== null ? 'self.version ('.$holder->version().')' : $holder->constraint();

        return $verb.($target === null ? '' : ($name === null ? $target : $name($target)).' ').$constraint.($holder->link() === Holder::REQUIRE_DEV ? ' in require-dev' : '');
    }

    /** The joint request moves what requires the package, so a move that it resolves asks nothing of them. */
    private static function because(string $reason, Move $move, MoveSubject $subject): string
    {
        $resolved = \in_array($move->kind(), [Move::REQUIRE, Move::RAISE_PHP, Move::TAG], true);
        $joint = $move->movesWith() !== [];
        switch ($reason) {
            case Move::HOLDERS:
                return 'the packages that hold it may need to move too';
            case Move::CROSSES_MAJOR:
                $check = $move->check();
                $solved = $check !== null && $check->result() !== MoveCheck::NOT_CHECKED;

                return (strpos((string) $move->toBranch(), '0.') === 0 ? 'a new 0.x line (Composer treats 0.x minors as breaking)' : 'a new major').' brings its own requirements'.($solved ? '' : ', which lockrot does not solve');
            case Move::TRANSITIVE:
                if ($joint) {
                    return '';
                }

                return $resolved ? 'what requires it must allow it' : 'it comes in through '.self::dependents(self::withRequirer($subject)).': '.self::throughActions($subject->dependents(), $move->through());
            default:
                if ($joint) {
                    return 'lockrot found no path from your requirements to it';
                }

                return $resolved ? 'lockrot found no path from your requirements to it, so what requires it must allow it' : 'lockrot found no path from your requirements to it: `composer why` names what requires it';
        }
    }

    /**
     * `direct_dependents`, with "({requirer} requires it)" after `chain[0]` when the chain passes
     * another package.
     *
     * @return list<string>
     */
    private static function withRequirer(MoveSubject $subject): array
    {
        $requirer = $subject->requirer();
        $head = $subject->chainHead();

        return array_map(static fn (string $name): string => $requirer !== null && $name === $head ? $name.' ('.$requirer.' requires it)' : $name, $subject->dependents());
    }

    /**
     * One action per group of dependents, in `through[]` order: "change your own package" for
     * a local one, "replace" for one whose locked release is its newest, else "replace or update".
     *
     * @param list<string>       $dependents
     * @param list<ThroughEntry> $through    empty on an `also[]` move: every dependent then reads "replace or update"
     */
    private static function throughActions(array $dependents, array $through): string
    {
        $groups = [];
        foreach ($through === [] ? $dependents : $through as $entry) {
            $name = $entry instanceof ThroughEntry ? $entry->package() : $entry;
            $group = !$entry instanceof ThroughEntry ? 'update' : ($entry->isLocal() ? 'local' : ($entry->newestIsInstalled() === true ? 'replace' : 'update'));
            $groups[$group][] = $name;
        }
        $actions = [];
        foreach ($groups as $group => $names) {
            $verb = ['local' => \count($names) === 1 ? 'change your own package' : 'change your own packages', 'replace' => 'replace'][$group] ?? 'replace or update';
            $actions[] = $verb.' '.self::dependents($names);
        }

        return implode(', or ', $actions);
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
        $list = ListWords::of(array_merge($names, $more > 0 ? [$more.' more'] : []));
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
