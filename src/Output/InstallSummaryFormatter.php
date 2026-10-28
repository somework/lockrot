<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
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
    /** The notes the block prints; a later note is counted in the footer. */
    public const MAX_NOTES = 2;

    /**
     * A finding line is a display line: past this many characters of the shown text, its prose is
     * cut at a word boundary. A command shows whole or not at all (docs/install-time.md).
     */
    public const MAX_FINDING_LINE = 160;

    private const FOOTER = 'Run composer lockrot for details';

    /**
     * @return list<string> Composer IO lines, empty only when nothing is graded, the report has no
     *                      network failure and the gate passes
     */
    public function format(Report $report): array
    {
        $graded = $report->graded();
        $run = $report->run();
        $gate = $report->gate();
        $gateLine = $gate !== null && $run !== null && $gate->fails() ? '  '.self::text(RunGateText::render($gate, $run)) : null;
        if ($graded === []) {
            // An exhausted budget or an unreachable repository grades nothing: without this branch
            // it prints nothing and reads as a clean install.
            return $report->hadNetworkFailures() || $gateLine !== null ? $this->uncheckedLines($report, $gateLine) : [];
        }
        $updateNow = $report->updateNowCommand() === null ? [] : $report->updateNow();
        $notes = self::notes($report, $run);
        $shownNotes = \array_slice($notes, 0, self::MAX_NOTES);
        // The header and the footer always print, `update now:` and the gate line when they apply.
        $slots = self::MAX_LINES - 2 - ($updateNow === [] ? 0 : 1) - ($gateLine === null ? 0 : 1) - \count($shownNotes);
        $shown = \count($graded) > $slots ? \array_slice($graded, 0, $slots - 1) : $graded;
        $omitted = \count($graded) - \count($shown);

        $lines = [\sprintf('<warning>%s</warning>', self::text(\sprintf('lockrot: %d of %d changed %s flagged — %s', \count($graded), $report->packagesChecked(), $report->packagesChecked() === 1 ? 'package' : 'packages', RunFooter::gradeCounts($report))))];
        foreach ($shown as $finding) {
            $lines[] = $this->findingLine($finding);
        }
        if ($omitted > 0) {
            $lines[] = \sprintf('  … and %d more', $omitted);
        }
        if ($updateNow !== []) {
            $hidden = \count(array_diff($updateNow, array_map(static fn (Finding $finding): string => $finding->package(), $shown)));
            $lines[] = '  update now: '.self::text(RunFooter::platformStep($report).RunFooter::updateNow($report).($hidden > 0 ? ' ('.$hidden.' not shown above)' : ''));
        }
        if ($gateLine !== null) {
            $lines[] = $gateLine;
        }
        foreach ($shownNotes as $note) {
            $lines[] = '  note: '.self::text($note);
        }
        $more = \count($notes) - \count($shownNotes);
        $lines[] = self::FOOTER.($more > 0 ? \sprintf('; %d more %s.', $more, $more === 1 ? 'note' : 'notes') : '.');

        return $lines;
    }

    /**
     * The runtime-target note first, then the run notes in their order.
     *
     * @return list<string>
     */
    private static function notes(Report $report, ?RunSettings $run): array
    {
        $notes = $report->notes();
        if ($run !== null && $report->baseline() !== null && $run->targetPhpSource() === RunSettings::SOURCE_RUNTIME) {
            array_unshift($notes, 'target php from the running PHP ('.$run->targetPhp().'); fix changes against the baseline not compared');
        }

        return $notes;
    }

    /** @return list<string> */
    private function uncheckedLines(Report $report, ?string $gateLine): array
    {
        $checked = $report->packagesChecked();
        $unknown = $report->byGrade()[Verdict::UNKNOWN];
        // Nothing is `unknown` when a later lookup failed (advisories, a repository host), and
        // "0 of 3 could not be checked" contradicts the notes.
        $lines = [$unknown === 0
            ? \sprintf('<warning>lockrot: %d changed %s checked, one check incomplete</warning>', $checked, $checked === 1 ? 'package' : 'packages')
            : \sprintf('<warning>lockrot: %d of %d changed %s could not be checked</warning>', $unknown, $checked, $checked === 1 ? 'package' : 'packages')];
        if ($gateLine !== null) {
            $lines[] = $gateLine;
        }
        foreach (\array_slice($report->notes(), 0, self::MAX_NOTES) as $note) {
            $lines[] = '  note: '.self::text($note);
        }
        $lines[] = self::FOOTER.'.';

        return $lines;
    }

    /** `<grade> [<score>] <package> <version>: <counted flags> — <the move>`, fitted by {@see fitted()}. */
    private function findingLine(Finding $finding): string
    {
        $flags = $finding->flagIds();
        $security = $finding->scoreBasis()->securityTerm();
        if ($security !== null && \count($flags) === 1) {
            $count = array_sum($finding->securityStanding()->counts());
            $flags[0] .= $count > 1 && $security->severity() !== 'low'
                ? ' ('.$security->severity().' advisory; '.$count.' in all)'
                : ' ('.$count.' '.$security->severity().' '.($count === 1 ? 'advisory' : 'advisories').')';
        }
        $move = FindingBlock::moveParts($finding);
        $grade = $finding->verdict();
        $head = \sprintf('%s [%2d] %s %s: %s', str_repeat(' ', 8 - \strlen($grade)), $finding->score()->total(), $finding->package(), $finding->version(), implode(' · ', $flags));
        $parts = array_merge([[$head.($move === null ? '' : ' — '), false]], $move ?? []);
        $colour = FindingBlock::colour($grade);

        return '  '.($colour === null ? $grade : '<fg='.$colour.'>'.$grade.'</fg='.$colour.'>').ConsoleMarkup::escape(self::fitted($parts, self::MAX_FINDING_LINE - 2 - \strlen($grade)));
    }

    /**
     * The neutralised pieces in order within $room characters. A prose piece that does not fit is
     * cut at a word boundary, and nothing follows it. The first piece with no boundary in its room
     * is cut where the room ends, and a later one shows no word. A command shows whole when the
     * prose before it fits, even past $room, because a cut command runs as another one. A cut that
     * leaves no word after a command ends with ` …`, so a copied command takes no ellipsis.
     *
     * @param list<array{0: string, 1: bool}> $parts
     */
    private static function fitted(array $parts, int $room): string
    {
        $line = '';
        $afterCommand = false;
        foreach ($parts as [$text, $isCommand]) {
            $shown = TerminalText::neutralise($text);
            $length = TerminalText::characters($shown);
            if ($isCommand || $length <= $room) {
                $line .= $shown;
                $room -= $length;
                $afterCommand = $isCommand;
                continue;
            }
            $cut = substr(TerminalText::neutralise($text, max(0, $room)), 0, -\strlen(TerminalText::ELLIPSIS));
            $space = strrpos($cut, ' ');
            $cut = $space === false ? ($line === '' ? $cut : '') : substr($cut, 0, $space);

            return $line.($afterCommand && trim($cut) === '' ? ' '.TerminalText::ELLIPSIS : $cut.TerminalText::ELLIPSIS);
        }

        return $line;
    }

    /** Text lockrot did not write, as markup that prints it as written and obeys nothing in it. */
    private static function text(string $text): string
    {
        return ConsoleMarkup::escape(TerminalText::neutralise($text));
    }
}
