<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Data\Forge\RepoRef;
use Lockrot\Security\BranchFixes;
use Lockrot\Security\Fix;
use Lockrot\Security\Severity;
use Lockrot\Signal\Data\AbandonedData;
use Lockrot\Signal\Data\AdvisoryRow;
use Lockrot\Signal\Data\ArchivedData;
use Lockrot\Signal\Data\LeftBehindData;
use Lockrot\Signal\Data\NoPushData;
use Lockrot\Signal\Data\NoReleaseData;
use Lockrot\Signal\Data\OldPromiseData;
use Lockrot\Signal\Data\PinnedData;
use Lockrot\Signal\Data\SignalData;
use Lockrot\Signal\Signal;

/**
 * `flags[].summary`: one sentence per fired flag, rendered from the data of the signals that raise
 * it, and for `vulnerable` from the S9 rows and `security`. Its abandoned words name the registry
 * and the repository host, where S1's own summary says "its repository".
 *
 * @internal
 */
final class FlagSentence
{
    /** The words of a fix kind other than `update` in the branch clause. */
    private const FIX_LABEL = [Fix::UPGRADE => 'upgrade', Fix::RAISE_PHP => 'php floor raise', Fix::BLOCKED => 'blocked'];

    /** The signals each maintenance flag's sentence reads, in the order it reads them. */
    public const SIGNALS = [
        FlagSet::ABANDONED => [Signal::S1, Signal::S3, Signal::S2, Signal::S4],
        FlagSet::SILENT => [Signal::S2, Signal::S4],
        FlagSet::PINNED => [Signal::S6],
        FlagSet::LEFT_BEHIND => [Signal::S8],
        FlagSet::OLD_PROMISE => [Signal::S5],
        FlagSet::STALE => [Signal::S2, Signal::S4],
        FlagSet::VULNERABLE => [Signal::S9],
    ];

    /**
     * @param list<SignalData> $data the data of each signal that fired
     *
     * @throws \LogicException for the data of a signal that no maintenance flag reads
     */
    public static function maintenance(string $flag, array $data): string
    {
        $byId = [];
        foreach ($data as $d) {
            $byId[$d->signalId()] = $d;
        }
        $parts = [];
        foreach (self::SIGNALS[$flag] ?? [] as $id) {
            if (isset($byId[$id])) {
                $parts[] = self::signal($byId[$id]);
            }
        }

        return implode('; ', $parts);
    }

    /**
     * The `vulnerable` sentence: the counted advisories by severity, what the installed branch
     * fixes, and the deciding advisory, called "worst" only when its severity is the worst.
     *
     * @param list<AdvisoryRow>  $rows   the S9 rows, the deciding one marked
     * @param array<string, int> $counts counted advisories per severity
     *
     * @throws \LogicException for rows that mark no deciding advisory
     */
    public static function vulnerable(array $rows, array $counts, string $worst, string $decidingSeverity, ?string $branch, ?BranchFixes $installed): string
    {
        $n = \count($rows);
        $listed = [];
        foreach (Severity::DISPLAY_ORDER as $severity) {
            if (($counts[$severity] ?? 0) > 0) {
                $listed[] = $counts[$severity].' '.$severity;
            }
        }
        $deciding = null;
        foreach ($rows as $row) {
            if ($row->isDeciding()) {
                $deciding = $row;
            }
        }
        if ($deciding === null) {
            throw new \LogicException('The S9 rows mark no deciding advisory for the vulnerable sentence.');
        }
        $word = $n === 1 ? 'advisory' : ($decidingSeverity === $worst ? 'worst' : 'weighs most');

        return \sprintf('%d %s: %s%s; %s: %s %s', $n, $n === 1 ? 'advisory' : 'advisories', implode(', ', $listed), self::branchTail($branch, $installed), $word, $deciding->cve() ?? $deciding->id(), (string) $deciding->title());
    }

    private static function branchTail(?string $branch, ?BranchFixes $installed): string
    {
        if ($branch === null || $installed === null) {
            return '';
        }
        $fixed = $installed->fixed();
        $unknown = $installed->unknown();
        $of = $installed->of();
        $kind = $installed->fixKind();
        if ($unknown === $of) {
            return ' — fix not known on '.$branch;
        }
        $label = $fixed > 0 && $kind !== null && $kind !== Fix::UPDATE ? ' ('.self::FIX_LABEL[$kind].')' : '';
        if ($fixed === 0) {
            $tail = ' — none fixed on '.$branch;
        } elseif ($fixed === $of) {
            $tail = ' — all fixed on '.$branch.$label;
        } else {
            $tail = \sprintf(' — %d of %d fixed on %s%s', $fixed, $of, $branch, $label);
        }

        return $tail.($unknown > 0 ? \sprintf('; %d not known', $unknown) : '');
    }

    private static function signal(SignalData $d): string
    {
        if ($d instanceof AbandonedData) {
            $replacement = $d->replacement();

            return 'marked abandoned '.($d->markedBy() === 'lock' ? 'in composer.lock' : 'by its registry').($replacement !== null && $replacement !== '' ? ', replacement: '.$replacement : '');
        }
        if ($d instanceof ArchivedData) {
            return 'its repository is archived on '.RepoRef::label($d->forge());
        }
        if ($d instanceof NoReleaseData) {
            return 'last release '.self::ago($d->lastRelease(), $d->years(), $d->datedBy());
        }
        if ($d instanceof NoPushData) {
            return 'last '.$d->activity().' '.self::ago($d->lastPush(), $d->years(), null);
        }
        if ($d instanceof OldPromiseData) {
            $major = explode('.', $d->targetMajor())[0];

            return \sprintf('released %s for PHP %s (php "%s"), before PHP %s existed (%s GA %s); admits %s untested', substr($d->released(), 0, 10), (string) $d->writtenForPhp(), $d->phpConstraint(), $major, $d->targetMajor(), $d->gaDate(), $d->targetPhp());
        }
        if ($d instanceof PinnedData) {
            return 'pinned to '.($d->reason() === 'branch_snapshot' ? 'branch snapshot ' : '').$d->version();
        }

        if ($d instanceof LeftBehindData) {
            return self::s8($d);
        }

        throw new \LogicException('Only the data of a maintenance signal has a maintenance sentence.');
    }

    private static function s8(LeftBehindData $d): string
    {
        $out = 'branch '.$d->branch().' last released '.self::ago($d->branchLastRelease(), $d->years(), $d->datedBy());
        $newest = \sprintf('%s released %s (%s)', $d->newestBranch(), $d->newestVersion(), substr($d->newestRelease(), 0, 10));
        if ($d->newestWithinReach()) {
            return $out.'; '.$newest;
        }
        $out .= \sprintf("; %s, needs php %s above the %s's php %s", $newest, (string) $d->newestPhp(), $d->floorSource() === 'project' ? 'project' : 'target', (string) $d->floorPhp());
        $reachable = $d->reachable();
        if ($reachable !== null) {
            return $out.\sprintf('; %s released %s (%s)', $reachable->branch(), $reachable->version(), substr($reachable->release(), 0, 10));
        }

        return $out.'; no releasing branch within reach';
    }

    private static function ago(string $date, ?float $years, ?string $datedBy): string
    {
        return substr($date, 0, 10).' ('.($years === null ? '' : \sprintf('%.1f', $years)).' years ago'.($datedBy === null ? ')' : ', dated by '.$datedBy.')');
    }
}
