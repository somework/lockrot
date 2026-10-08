<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Data\Forge\RepoRef;
use Lockrot\Security\Fix;
use Lockrot\Security\Severity;
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
     * @param array<string, array<string, mixed>> $data the data of each signal that fired, by id
     */
    public static function maintenance(string $flag, array $data): string
    {
        $parts = [];
        foreach (self::SIGNALS[$flag] ?? [] as $id) {
            if (isset($data[$id])) {
                $parts[] = self::signal($id, $data[$id]);
            }
        }

        return implode('; ', $parts);
    }

    /**
     * The `vulnerable` sentence: the counted advisories by severity, what the installed branch
     * fixes, and the deciding advisory, called "worst" only when its severity is the worst.
     *
     * @param list<array<string, mixed>>                                                    $rows     the S9 rows, the deciding one marked
     * @param array<string, int>                                                            $counts   counted advisories per severity
     * @param ?array{fixed: int, unknown: int, of: int, fix_kind: ?string}                  $installed the installed branch row's fixes
     */
    public static function vulnerable(array $rows, array $counts, string $worst, string $decidingSeverity, ?string $branch, ?array $installed): string
    {
        $n = \count($rows);
        $listed = [];
        foreach (Severity::DISPLAY_ORDER as $severity) {
            if (($counts[$severity] ?? 0) > 0) {
                $listed[] = $counts[$severity].' '.$severity;
            }
        }
        $deciding = $rows[0];
        foreach ($rows as $row) {
            if (($row['deciding'] ?? false) === true) {
                $deciding = $row;
            }
        }
        $word = $n === 1 ? 'advisory' : ($decidingSeverity === $worst ? 'worst' : 'weighs most');
        $name = \is_string($deciding['cve'] ?? null) ? $deciding['cve'] : self::scalar($deciding['id'] ?? '');

        return \sprintf('%d %s: %s%s; %s: %s %s', $n, $n === 1 ? 'advisory' : 'advisories', implode(', ', $listed), self::branchTail($branch, $installed), $word, $name, self::scalar($deciding['title'] ?? ''));
    }

    /** @param ?array{fixed: int, unknown: int, of: int, fix_kind: ?string} $installed */
    private static function branchTail(?string $branch, ?array $installed): string
    {
        if ($branch === null || $installed === null) {
            return '';
        }
        ['fixed' => $fixed, 'unknown' => $unknown, 'of' => $of, 'fix_kind' => $kind] = $installed;
        if ($unknown === $of) {
            return ' — fix not known on '.$branch;
        }
        $label = $fixed > 0 && $kind !== null && $kind !== Fix::UPDATE ? ' ('.(self::FIX_LABEL[$kind] ?? $kind).')' : '';
        if ($fixed === 0) {
            $tail = ' — none fixed on '.$branch;
        } elseif ($fixed === $of) {
            $tail = ' — all fixed on '.$branch.$label;
        } else {
            $tail = \sprintf(' — %d of %d fixed on %s%s', $fixed, $of, $branch, $label);
        }

        return $tail.($unknown > 0 ? \sprintf('; %d not known', $unknown) : '');
    }

    /** @param array<string, mixed> $d */
    private static function signal(string $id, array $d): string
    {
        switch ($id) {
            case Signal::S1:
                $by = ($d['marked_by'] ?? null) === 'lock' ? 'in composer.lock' : 'by its registry';

                return 'marked abandoned '.$by.(\is_string($d['replacement'] ?? null) && $d['replacement'] !== '' ? ', replacement: '.$d['replacement'] : '');
            case Signal::S3:
                return 'its repository is archived on '.RepoRef::label(self::scalar($d['forge'] ?? RepoRef::GITHUB));
            case Signal::S2:
                return 'last release '.self::ago(self::scalar($d['last_release'] ?? ''), $d['years'] ?? null, $d['dated_by'] ?? null);
            case Signal::S4:
                return 'last '.self::scalar($d['activity'] ?? 'push').' '.self::ago(self::scalar($d['last_push'] ?? ''), $d['years'] ?? null, null);
            case Signal::S5:
                $major = explode('.', self::scalar($d['target_major'] ?? ''))[0];

                return \sprintf('released %s for PHP %s (php "%s"), before PHP %s existed (%s GA %s); admits %s untested', substr(self::scalar($d['released'] ?? ''), 0, 10), self::scalar($d['written_for_php'] ?? null), self::scalar($d['php_constraint'] ?? null), $major, self::scalar($d['target_major'] ?? null), self::scalar($d['ga_date'] ?? null), self::scalar($d['target_php'] ?? null));
            case Signal::S6:
                return 'pinned to '.(($d['reason'] ?? null) === 'branch_snapshot' ? 'branch snapshot ' : '').self::scalar($d['version'] ?? null);
            default:
                return self::s8($d);
        }
    }

    /** @param array<string, mixed> $d */
    private static function s8(array $d): string
    {
        $out = 'branch '.self::scalar($d['branch'] ?? null).' last released '.self::ago(self::scalar($d['branch_last_release'] ?? ''), $d['years'] ?? null, $d['dated_by'] ?? null);
        $newest = \sprintf('%s released %s (%s)', self::scalar($d['newest_branch'] ?? null), self::scalar($d['newest_version'] ?? null), substr(self::scalar($d['newest_release'] ?? ''), 0, 10));
        if (($d['newest_within_reach'] ?? false) === true) {
            return $out.'; '.$newest;
        }
        $out .= \sprintf("; %s, needs php %s above the %s's php %s", $newest, self::scalar($d['newest_php'] ?? null), ($d['floor_source'] ?? null) === 'project' ? 'project' : 'target', self::scalar($d['floor_php'] ?? null));
        if (\is_string($d['reachable_branch'] ?? null)) {
            return $out.\sprintf('; %s released %s (%s)', $d['reachable_branch'], self::scalar($d['reachable_version'] ?? null), substr(self::scalar($d['reachable_release'] ?? ''), 0, 10));
        }

        return $out.'; no releasing branch within reach';
    }

    /**
     * @param mixed $years
     * @param mixed $datedBy
     */
    private static function ago(string $date, $years, $datedBy): string
    {
        return substr($date, 0, 10).' ('.(\is_int($years) || \is_float($years) ? \sprintf('%.1f', $years) : self::scalar($years)).' years ago'.(\is_string($datedBy) ? ', dated by '.$datedBy.')' : ')');
    }

    /** @param mixed $value */
    private static function scalar($value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
