<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunNote;
use Lockrot\Config\Gate;
use Lockrot\Score\Standing;
use Lockrot\Verdict\Finding;

/**
 * A pull-request comment or a job summary as Markdown. Every text from the lock, a
 * repository or an advisory goes through {@see text()} or {@see code()}, so it renders as written.
 *
 * @internal
 */
final class MarkdownFormatter implements FormatterInterface
{
    private const HEADER_ROW = '| Verdict | Package | Version | Flags | Why | Via |';
    private const SEPARATOR_ROW = '|---|---|---|---|---|---|';

    private FormatContext $context;

    public function __construct(FormatContext $context)
    {
        $this->context = $context;
    }

    /**
     * With the context's markdown budget set (`--markdown-max-bytes`), rows are left out from the
     * end, whole, until the document fits; a last row counts them.
     */
    public function format(Report $report, bool $showAll = false): string
    {
        $gate = $this->context->gateOf($report);
        $head = [$this->heading($report), '', self::gateLine(RunGateText::parts($gate, $this->context->runOf($report)))];
        $rows = [];
        foreach ($this->context->recordFindings($report, $showAll) as $finding) {
            $rows[] = $this->row($finding, $report, $gate);
        }
        $footer = $this->footer($report);
        $budget = $this->context->markdownMaxBytes();
        $full = self::assemble($head, $rows, $footer, \count($rows));
        if ($budget === null || \strlen($full) <= $budget) {
            return $full;
        }
        $room = $budget - \strlen(self::assemble($head, [self::more(\count($rows))], $footer, \count($rows)));
        $shown = [];
        foreach ($rows as $row) {
            $room -= \strlen($row) + 1;
            if ($room < 0) {
                break;
            }
            $shown[] = $row;
        }
        $shown[] = self::more(\count($rows) - \count($shown));

        return self::assemble($head, $shown, $footer, \count($rows));
    }

    /** The last row of a cut document: how many findings it leaves out. */
    private static function more(int $omitted): string
    {
        return '| … | '.$omitted.' more '.($omitted === 1 ? 'finding' : 'findings').': see the JSON report | | | | |';
    }

    /**
     * @param list<string> $head
     * @param list<string> $rows
     * @param list<string> $footer
     */
    private static function assemble(array $head, array $rows, array $footer, int $total): string
    {
        $lines = $head;
        if ($total > 0) {
            $lines = array_merge($lines, ['', self::HEADER_ROW, self::SEPARATOR_ROW], $rows);
        }

        return implode("\n", array_merge($lines, [''], $footer))."\n";
    }

    private function heading(Report $report): string
    {
        $flagged = \count($report->graded());
        $noun = $report->packagesChecked() === 1 ? 'package' : 'packages';
        if ($flagged > 0) {
            return \sprintf('### lockrot: %d of %d %s flagged — %s', $flagged, $report->packagesChecked(), $noun, RunFooter::gradeCounts($report));
        }
        foreach ($report->runNotes() as $note) {
            if ($note->code() === RunNote::ADVISORIES_NOT_CHECKED && ($note->data()['reason'] ?? null) === RunNote::ADVISORIES_OFFLINE) {
                return \sprintf('### lockrot: nothing flagged in %d %s — security advisories not checked (offline)', $report->packagesChecked(), $noun);
            }
        }

        return \sprintf('### lockrot: nothing flagged in %d %s', $report->packagesChecked(), $noun);
    }

    /**
     * `gate.text` with its first segment in bold.
     *
     * @param non-empty-list<string> $parts
     */
    private static function gateLine(array $parts): string
    {
        $line = '**'.self::text(array_shift($parts)).'**';
        foreach ($parts as $part) {
            $line .= ' · '.self::text($part);
        }

        return $line;
    }

    private function row(Finding $finding, Report $report, Gate $gate): string
    {
        $baseline = $report->baseline();
        $standing = $baseline === null ? null : $baseline->standingOf($finding->package());
        $gateStanding = $gate->standingOf($finding->package());
        $verdict = $finding->verdict();
        if ($gateStanding !== null && $gateStanding->fails() && ($standing === null || $standing->status() !== Standing::KNOWN)) {
            $verdict = '**'.$verdict.'**';
        }
        $suffix = FindingBlock::baselineSuffix($finding, $standing);
        $why = [self::code(FindingBlock::scoreLine($finding).($suffix === null ? '' : ' '.$suffix))];
        $gateLine = FindingBlock::gateLine($finding, $gateStanding);
        if ($gateLine !== null) {
            $why[] = self::code($gateLine);
        }
        $move = FindingBlock::move($finding);
        if ($move !== null) {
            $why[] = '**Do:** '.self::text($move);
        }
        foreach (FindingBlock::evidence($finding) as $flag => $summary) {
            $why[] = '*'.$flag.':* '.self::text($summary);
        }
        foreach ([FindingBlock::markingLine($finding), FindingBlock::ageLine($finding), FindingBlock::pullsIn($finding), FindingBlock::checksMissing($finding), FindingBlock::checksAccepted($finding)] as $line) {
            if ($line !== null) {
                $why[] = self::labelled($line);
            }
        }
        $allowlist = FindingBlock::allowlist($finding);
        if ($allowlist !== null) {
            $why[] = '*'.$allowlist[0].':* '.self::text($allowlist[1]);
        }

        return '| '.$verdict.' | '.self::code($finding->package()).' | '.self::text($finding->version())
            .' | '.implode(' · ', FindingBlock::headerFlags($finding, $standing)).' | '.implode('<br>', $why)
            .' | '.self::text($finding->isDirect() ? 'direct' : FindingBlock::dependents($finding)).' |';
    }

    /** The age line and the abandoned-marking line set their label in italics. */
    private static function labelled(string $line): string
    {
        if (strpos($line, 'age: ') === 0) {
            return '*age:* '.self::text(substr($line, 5));
        }
        $marking = 'abandoned marking ignored';
        if (strpos($line, $marking) === 0) {
            return '*'.$marking.'*'.self::text(substr($line, \strlen($marking)));
        }

        return self::text($line);
    }

    /** @return list<string> the footer lines, in their order */
    private function footer(Report $report): array
    {
        $spans = [];
        $name = static function (string $package) use (&$spans): string {
            $placeholder = \sprintf("\x00%d\x00", \count($spans));
            // The key is the placeholder as self::text() writes it, because the spans go in after it.
            $spans[self::text($placeholder)] = self::code($package);

            return $placeholder;
        };
        $baseline = $report->baseline();
        $lines = [];
        foreach ([RunFooter::flags($report), RunFooter::depth($report, $name), RunFooter::security($report, false), $report->libyears()->line(), RunFooter::exposure($report, $name), RunFooter::advisories($report), RunFooter::moveCheck($report, $name), RunFooter::phpWindow($report, $name), $baseline === null ? null : $baseline->summaryLine()] as $line) {
            if ($line !== null) {
                $lines[] = strtr(self::text($line), $spans);
            }
        }
        $updateNow = RunFooter::updateNow($report);
        if ($updateNow !== null) {
            $lines[] = '';
            $lines[] = '**Update now:** '.self::text(RunFooter::platformStep($report)).self::code($updateNow);
        }
        $notes = $report->notes();
        $stale = $baseline === null ? null : $baseline->staleNote();
        if ($stale !== null) {
            $notes[] = $stale;
        }
        if ($notes !== []) {
            $lines[] = '';
            foreach ($notes as $note) {
                $lines[] = '- note: '.self::text($note);
            }
        }
        $lines[] = '';
        $lines[] = '<sub>'.self::text(($report->packagesChecked() === 1 ? '1 package checked' : $report->packagesChecked().' packages checked').' — '.lcfirst(RunFooter::dataAsOf($report))).'. Run `composer lockrot --format=json` for details.</sub>';

        return $lines;
    }

    /**
     * The characters that carry meaning in Markdown or start HTML. CommonMark lets a backslash
     * escape any ASCII punctuation (https://spec.commonmark.org/current/#backslash-escapes), and
     * GitHub renders `\<` as a literal `<`. `&` is escaped too, so an entity such as `&lt;` cannot
     * smuggle a tag past the `<` escape.
     */
    private const ESCAPED = ['\\', '|', '`', '*', '_', '[', ']', '<', '>', '&', '~', '#'];

    /** Plain text for a table cell or a bullet: one line, nothing that renders as markup. */
    private static function text(string $value): string
    {
        $value = TerminalText::neutralise(str_replace(["\r\n", "\r", "\n"], ' ', $value));
        foreach (self::ESCAPED as $character) {
            $value = str_replace($character, '\\'.$character, $value);
        }
        // GitHub notifies the user or team of an `@name` and links a bare URL or email address, and
        // the lock of a fork's pull request is its author's text. An `@` after a letter or a digit
        // with no dotted domain after it is neither, such as the stability flag of `~0.4@dev`.
        $value = (string) preg_replace('/(?<![A-Za-z0-9])@|@(?=[A-Za-z0-9_\\\\-]+\\.[A-Za-z0-9])/', '@&#8203;', str_replace('://', '\\://', $value));

        return (string) preg_replace('/(www)\./i', '$1\\\\.', $value);
    }

    /**
     * A code span. Backslash escapes do not apply inside one, so the delimiter is one backtick
     * longer than the longest run inside, as CommonMark requires. `|` is still escaped: GitHub's
     * table parser reads it before the code span.
     */
    private static function code(string $value): string
    {
        $value = TerminalText::neutralise(str_replace(["\r\n", "\r", "\n"], ' ', $value));
        $value = str_replace('|', '\\|', $value);
        $longest = 0;
        preg_match_all('/`+/', $value, $runs);
        foreach ($runs[0] as $run) {
            $longest = max($longest, \strlen($run));
        }
        $fence = str_repeat('`', $longest + 1);
        if ($longest > 0) {
            $value = ' '.$value.' ';
        }

        return $fence.$value.$fence;
    }
}
