<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;

/**
 * The install-time block: a report compressed into at most ten Composer IO lines, meant to be read
 * in passing while `composer require` runs, not studied.
 *
 * Two blocks, never both: the flagged one, and — when nothing is flagged but a lookup failed — a
 * shorter "could not be checked" one. Only a run where nothing is flagged *and* every lookup
 * succeeded prints nothing at all, so silence always means "checked, and clean".
 *
 * {@see MAX_LINES} counts logical lines — one array entry in the returned list, one line as
 * IOInterface::writeError() writes it — not rendered terminal rows; a long evidence line can still
 * wrap past one row in a narrow terminal.
 *
 * Unlike {@see TableFormatter} this returns lines rather than a rendered string, because
 * IOInterface::writeError() takes string|string[] and applies Composer's own styling to each.
 */
final class InstallSummaryFormatter
{
    public const MAX_LINES = 10;
    public const MAX_NOTES = 2;

    /** Header and footer always take one line each; the rest is shared by findings and notes. */
    private const FIXED_LINES = 2;

    private const FOOTER = 'Run composer lockrot for details.';

    /**
     * @return list<string> Composer IO-formatted lines; [] only when nothing is flagged *and*
     *                      every lookup succeeded
     */
    public function format(Report $report): array
    {
        $flagged = $report->flagged();
        if ($flagged === []) {
            // A package whose metadata never arrived is `unknown`, which sits below the flagged
            // threshold — so an exhausted budget or an unreachable repository would otherwise print
            // nothing at all and read as a clean install. Say what could not be checked instead.
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
            $lines[] = '  note: '.$note;
        }
        $lines[] = self::FOOTER;

        return $lines;
    }

    /**
     * Header, the notes carrying the reason (e.g. "Repository metadata unavailable for 4 packages:
     * not checked: install-time budget exhausted"), footer — at most 4 lines.
     *
     * @return list<string>
     */
    private function uncheckedLines(Report $report): array
    {
        $checked = $report->packagesChecked();
        $lines = [\sprintf(
            '<warning>lockrot: %d of %d changed %s could not be checked</warning>',
            $report->byVerdict()[Verdict::UNKNOWN],
            $checked,
            $checked === 1 ? 'package' : 'packages'
        )];
        foreach (\array_slice($report->notes(), 0, self::MAX_NOTES) as $note) {
            $lines[] = '  note: '.$note;
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
     * The verdict, the package, what was observed about it and the chain it arrives by. Neither the
     * other direct requirements that reach it nor what it pulls in (S7) belong here: the block is
     * read while `composer require` runs, and both answer questions asked over the full report.
     */
    private function findingLine(Finding $finding): string
    {
        return \sprintf(
            '  <comment>%-12s</comment>%s %s: %s%s',
            $finding->verdict(),
            $finding->package(),
            $finding->version(),
            $finding->ownEvidence(),
            Via::suffix($finding, ' > ', false)
        );
    }
}
