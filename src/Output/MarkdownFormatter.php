<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Verdict\Finding;

/**
 * A pull-request comment as Markdown: docs/ci.md#-formatmarkdown.
 *
 * @internal
 */
final class MarkdownFormatter implements FormatterInterface
{
    private const HEADER_ROW = '| Priority | Package | Version | Verdict | Evidence | Via |';
    private const SEPARATOR_ROW = '|---|---|---|---|---|---|';

    private FormatContext $context;

    public function __construct(FormatContext $context)
    {
        $this->context = $context;
    }

    public function format(Report $report, bool $showAll = false): string
    {
        $baseline = $report->baseline();
        $rows = $showAll ? $report->findings() : $report->flagged();

        $lines = [$this->heading($report)];
        if ($baseline !== null) {
            $lines[] = self::text($baseline->summaryLine());
        }

        if ($rows !== []) {
            $lines[] = '';
            $lines[] = self::HEADER_ROW;
            $lines[] = self::SEPARATOR_ROW;
            foreach ($rows as $finding) {
                $lines[] = $this->row($finding, $baseline);
            }
        }

        $lines[] = '';
        $lines[] = self::text($report->libyears()->line());

        $exposure = $report->exposureSummaryLine();
        if ($exposure !== '') {
            $lines[] = '';
            $lines[] = self::text($exposure);
        }
        $unflagged = $report->unflaggedAdvisoriesLine();
        if ($unflagged !== '') {
            $lines[] = '';
            $lines[] = $unflagged;
        }

        $notes = $this->notes($report, $baseline);
        if ($notes !== []) {
            $lines[] = '';
            foreach ($notes as $note) {
                $lines[] = '- note: '.self::text($note);
            }
        }

        $lines[] = '';
        $lines[] = $this->footer($report);

        return implode("\n", $lines)."\n";
    }

    private function heading(Report $report): string
    {
        $flagged = \count($report->flagged());
        if ($flagged === 0) {
            return \sprintf('### lockrot: no dependency rot found in %d packages', $report->packagesChecked());
        }

        return \sprintf('### lockrot: dependency rot in %d of %d packages', $flagged, $report->packagesChecked());
    }

    private function row(Finding $finding, ?BaselineComparison $baseline): string
    {
        $level = $this->context->levelOf($finding, $baseline);
        $verdict = $level === FormatContext::LEVEL_NOTE ? $finding->verdict() : '**'.$finding->verdict().'**';

        return '| '.$finding->priority().' | '.self::code($finding->package()).' | '.self::text($finding->version())
            .' | '.$verdict.' | '.self::text($finding->evidenceLine()).' | '.self::text(Via::cell($finding, ' › ')).' |';
    }

    /** @return list<string> */
    private function notes(Report $report, ?BaselineComparison $baseline): array
    {
        $notes = $report->notes();
        $stale = $baseline === null ? null : $baseline->staleNote();
        if ($stale !== null) {
            $notes[] = $stale;
        }

        return $notes;
    }

    private function footer(Report $report): string
    {
        return '<sub>'.$report->summaryLine().' — data as of '.$report->generatedAt()->format('Y-m-d')
            .' ('.$report->dataSourcesClause().'). Run `composer lockrot --format=json` for details.</sub>';
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
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
        foreach (self::ESCAPED as $character) {
            $value = str_replace($character, '\\'.$character, $value);
        }

        return $value;
    }

    /**
     * A code span. Backslash escapes do not apply inside one, so the delimiter is one backtick
     * longer than the longest run inside, as CommonMark requires. `|` is still escaped: GitHub's
     * table parser reads it before the code span.
     */
    private static function code(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
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
