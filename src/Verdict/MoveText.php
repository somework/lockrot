<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Security\Holder;

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
        'metadata_not_read' => 'no move: lockrot did not read its releases, so it names none',
        'local_package' => 'no move: it is installed from a local path (your own package)',
    ];

    private const ADDS = [
        MoveFact::RELEASED_BEFORE_GA => "it was released before your target PHP's major",
        MoveFact::BRANCH_NOT_RELEASING => 'that line no longer releases while a higher line does',
    ];

    public static function render(Move $move): string
    {
        $subject = $move->subject();
        $kind = $move->kind();
        $text = self::head($move, $subject);
        if (\in_array($kind, array_merge(Move::ROW_KINDS, [Move::TAG]), true)) {
            $text .= self::leaves($move).self::unverified($move);
        }
        $holders = array_map([self::class, 'holderWords'], $move->heldBy());
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

        return $text;
    }

    /** @param list<string> $names */
    public static function dependents(array $names): string
    {
        if (\count($names) > self::DEPENDENTS_SHOWN) {
            return implode(', ', \array_slice($names, 0, self::DEPENDENTS_SHOWN)).' and '.(\count($names) - self::DEPENDENTS_SHOWN).' more';
        }

        return implode(', ', $names);
    }

    private static function head(Move $move, MoveSubject $subject): string
    {
        $package = $subject->package();
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
                return 'composer update '.$package.' (gets '.$move->newest().')'.self::clears($move);
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
                $reason = (string) $move->reason();

                return (self::NO_MOVE[$reason] ?? $reason).($reason === 'metadata_not_read' && $move->source() === FlagSet::PINNED ? '; review the snapshot' : '');
            default:
                return $move->kind();
        }
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
            $text = 'require a tag — '.$word.' is '.$version;
        } else {
            $via = self::dependents($subject->dependents());
            $text = 'move it to a tag — '.$word.' is '.$version.($via === '' ? '' : ', through '.$via);
        }
        $released = $latest === null ? null : $latest->released();
        $text .= $released === null ? '' : ' ('.substr($released, 0, 10).')';
        if ($latest !== null && $latest->relation() === 'older') {
            $text .= ', older than your snapshot ('.substr((string) $latest->snapshotTime(), 0, 10).'); review, or replace';
        }
        $text .= self::phpClause($move, $subject, false);
        foreach (self::addsByBasis($move) as $basis => $ids) {
            $text .= '; '.(self::ADDS[$basis] ?? str_replace('_', ' ', $basis)).', so '.implode(' and ', $ids).' would count';
        }

        return $text;
    }

    private static function requirement(Move $move, MoveSubject $subject): string
    {
        $check = $move->phpCheck();
        $raise = $check !== null && $check->projectAllows() === false && $check->raiseTo() !== null ? 'raise require.php to '.$check->raiseTo().', then ' : '';
        if ($subject->isDirect()) {
            $text = $raise.'require '.$subject->package().' '.$move->constraint();
        } else {
            $via = self::dependents($subject->dependents());
            $text = $raise.'move '.$subject->package().' to '.$move->constraint().($via === '' ? '' : ' through '.$via);
        }

        return $text.self::clears($move).self::phpClause($move, $subject, true);
    }

    private static function blocked(Move $move, MoveSubject $subject): string
    {
        $latest = $move->latest();
        $where = $move->source() === FlagSet::PINNED && $latest !== null ? 'the newest tag, '.$latest->version().',' : ($move->toBranch() ?? 'the fix');
        $check = $move->phpCheck();
        $requires = $check === null ? null : $check->requires();
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
        } elseif ($check->projectAllows() === false && $check->raiseSize() !== null) {
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

    private static function holderWords(Holder $holder): string
    {
        if ($holder->source() === Holder::ROOT) {
            return 'composer.json (requires '.$holder->constraint().')';
        }
        $bits = ['requires '.$holder->constraint()];
        $finding = $holder->finding();
        if ($finding !== null && $finding->isGraded()) {
            $bits[] = $finding->lead() !== null ? 'itself '.$finding->lead() : 'itself '.$finding->verdict().': '.implode(' · ', $finding->flagIds());
        }

        return $holder->package().' '.$holder->version().' ('.implode('; ', $bits).')';
    }

    private static function because(string $reason, Move $move, MoveSubject $subject): string
    {
        $resolved = \in_array($move->kind(), [Move::REQUIRE, Move::RAISE_PHP, Move::TAG], true);
        switch ($reason) {
            case Move::HOLDERS:
                return 'the packages that hold it may need to move too';
            case Move::CROSSES_MAJOR:
                return (strpos((string) $move->toBranch(), '0.') === 0 ? 'a new 0.x line (Composer treats 0.x minors as breaking)' : 'a new major').' brings its own requirements, which lockrot does not solve';
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
        $gone = array_values(array_filter($through, static fn (ThroughEntry $entry): bool => $entry->newestRequires() === false));
        $kept = array_values(array_filter($through, static fn (ThroughEntry $entry): bool => $entry->newestRequires() === true));
        $entries = $gone !== [] ? $gone : $kept;
        if ($entries === []) {
            return '';
        }
        $one = \count($entries) === 1;
        $names = array_map(static fn (ThroughEntry $entry): string => $entry->package().' ('.$entry->newestVersion().')', \array_slice($entries, 0, self::DEPENDENTS_SHOWN));
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
            $by[(string) $fact->basis()][] = $fact->id();
        }

        return $by;
    }

    /** @param list<string> $parts */
    private static function join(array $parts): string
    {
        $last = array_pop($parts);

        return $parts === [] ? (string) $last : implode(', ', $parts).' and '.$last;
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
        return preg_match('/^v?\d+(\.\d+)?\.x-dev$/', $version) === 1 && ReleaseBranch::of($version) === null;
    }
}
