<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Verdict\Finding;

/**
 * The install-time block (SPEC F5.1): a report compressed into at most ten Composer IO lines, meant
 * to be read in passing while `composer require` runs, not studied. Nothing is flagged -> nothing is
 * printed.
 *
 * Unlike {@see TableFormatter} this returns lines rather than a rendered string, because
 * IOInterface::writeError() takes string|string[] (2.10.3 IO/IOInterface.php:86, 2.2.25 :86) and
 * applies Composer's own styling to each.
 */
final class InstallSummaryFormatter
{
    public const MAX_LINES = 10;
    public const MAX_NOTES = 2;

    /** Header and footer always take one line each; the rest is shared by findings and notes. */
    private const FIXED_LINES = 2;

    /** @return list<string> Composer IO-formatted lines; [] when nothing is flagged */
    public function format(Report $report): array
    {
        $flagged = $report->flagged();
        if ($flagged === []) {
            return [];
        }

        $notes = \array_slice($report->notes(), 0, self::MAX_NOTES);
        $slots = self::MAX_LINES - self::FIXED_LINES - \count($notes);

        $shown = $flagged;
        $omitted = 0;
        if (\count($flagged) > $slots) {
            // One slot goes to the "… and N more" line, so one fewer finding fits.
            $shown = \array_slice($flagged, 0, $slots - 1);
            $omitted = \count($flagged) - \count($shown);
        }

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
        $lines[] = 'Run composer lockrot for details.';

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

    private function findingLine(Finding $finding): string
    {
        return \sprintf(
            '  <comment>%-12s</comment>%s %s: %s%s',
            $finding->verdict(),
            $finding->package(),
            $finding->version(),
            $finding->evidence(),
            $this->via($finding)
        );
    }

    /** The chain without the package itself — empty for a direct requirement, which needs no "via". */
    private function via(Finding $finding): string
    {
        $chain = $finding->chain();
        if (\count($chain) < 2) {
            return '';
        }
        array_pop($chain);

        return ' (via '.implode(' > ', $chain).')';
    }
}
