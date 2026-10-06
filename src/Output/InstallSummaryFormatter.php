<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;

/**
 * The install-time block: at most ten Composer IO lines (docs/install-time.md). It returns a list,
 * because IOInterface::writeErrorRaw() writes each entry as a line, and {@see MAX_LINES} counts
 * those entries, not terminal rows. Each line is {@see ConsoleMarkup}, written raw: a raw write
 * skips Composer's sanitising, so text from the lock and the repository is neutralised
 * ({@see TerminalText::neutralise()}) as well as escaped.
 *
 * @internal
 */
final class InstallSummaryFormatter
{
    public const MAX_LINES = 10;
    /** Metadata, repository activity and advisories can each leave one note, and the block shows them all. */
    public const MAX_NOTES = 3;

    /** Header and footer always take one line each, and findings and notes share the rest. */
    private const FIXED_LINES = 2;

    private const FOOTER = 'Run composer lockrot for details.';

    /**
     * @return list<string> Composer IO lines, empty only when nothing is flagged and every lookup
     *                      succeeded
     */
    public function format(Report $report): array
    {
        $flagged = $report->flagged();
        if ($flagged === []) {
            // `unknown` is not flagged, so without this branch an exhausted budget or an unreachable
            // repository prints nothing and reads as a clean install.
            return $report->hadNetworkFailures() ? $this->uncheckedLines($report) : [];
        }

        $notes = \array_slice($report->notes(), 0, self::MAX_NOTES);
        $slots = self::MAX_LINES - self::FIXED_LINES - \count($notes);

        // When they do not all fit, one slot goes to the "… and N more" line, so one fewer finding fits.
        $shown = \count($flagged) > $slots ? \array_slice($flagged, 0, $slots - 1) : $flagged;
        $omitted = \count($flagged) - \count($shown);

        $lines = [$this->header(\count($flagged), $report->packagesChecked())];
        foreach ($shown as $finding) {
            $lines[] = $this->findingLine($finding);
        }
        if ($omitted > 0) {
            $lines[] = \sprintf('  … and %d more', $omitted);
        }
        foreach ($notes as $note) {
            $lines[] = '  note: '.self::text($note);
        }
        $lines[] = self::FOOTER;

        return $lines;
    }

    /** @return list<string> */
    private function uncheckedLines(Report $report): array
    {
        $checked = $report->packagesChecked();
        $unknown = $report->byVerdict()[Verdict::UNKNOWN];
        // Nothing is `unknown` when a later lookup failed (advisories, a repository host), and
        // "0 of 3 could not be checked" contradicts the notes.
        $lines = [$unknown === 0
            ? \sprintf('<warning>lockrot: %d changed %s checked, one check incomplete</warning>', $checked, $checked === 1 ? 'package' : 'packages')
            : \sprintf('<warning>lockrot: %d of %d changed %s could not be checked</warning>', $unknown, $checked, $checked === 1 ? 'package' : 'packages')];
        foreach (\array_slice($report->notes(), 0, self::MAX_NOTES) as $note) {
            $lines[] = '  note: '.self::text($note);
        }
        $lines[] = self::FOOTER;

        return $lines;
    }

    private function header(int $flagged, int $checked): string
    {
        return \sprintf(
            '<warning>lockrot: dependency rot in %d of %d changed %s</warning>',
            $flagged,
            $checked,
            $checked === 1 ? 'package' : 'packages'
        );
    }

    /**
     * Omits the other direct requirements and S7: the block is read in passing, and both answer
     * questions about the full report (docs/install-time.md#at-most-10-lines-always).
     */
    private function findingLine(Finding $finding): string
    {
        return \sprintf(
            '  <comment>%-12s</comment>%s',
            $finding->verdict(),
            self::text($finding->package().' '.$finding->version().': '.$finding->ownEvidence().Via::suffix($finding, ' > ', false))
        );
    }

    /** Text lockrot did not write, as markup that prints it as written and obeys nothing in it. */
    private static function text(string $text): string
    {
        return ConsoleMarkup::escape(TerminalText::neutralise($text));
    }
}
