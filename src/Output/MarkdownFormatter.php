<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Verdict\Finding;

/**
 * A PR-comment-shaped Markdown report: a heading with the flagged/checked counts, a table of the
 * flagged findings (or every finding with `--all`), the report's notes as a bullet list, and a
 * `<sub>` footer carrying the full summary line — meant to be posted verbatim, e.g.
 * `composer lockrot --format=markdown > comment.md` piped into `gh pr comment --body-file`.
 *
 * The heading always uses the same flagged/checked counts as the install-time block
 * (InstallSummaryFormatter::header()), so it follows `Report::flagged()` alone. The table follows
 * the rows this call would actually print — flagged only, or every finding under `--all` — and is
 * omitted when those are empty. The two conditions are not the same one: `--all` on a report with
 * nothing flagged prints the "no dependency rot found in N packages" heading *and* a full table of
 * every checked package underneath, which is what `--all` was asked for.
 *
 * Priority leads the row: the table keeps the report's own order, which is by priority, so a
 * reviewer reads down the first column and stops where the rows stop applying to the project. The
 * verdict keeps its own column next to it — the two axes answer different questions.
 *
 * Table cells escape the two characters that would otherwise break a Markdown table or collapse
 * multiple logical rows into one: `|` becomes `\|`, and any line break becomes a single space. The
 * verdict cell is bold only when FormatContext::levelOf() is not `note` — the same rule GitHub
 * annotations and GitLab severities use, so a reviewer's eye is drawn to exactly the findings that
 * can fail the build. The `Via` chain is joined with "›", which reads more naturally inline in a
 * table cell than the plain ">" the annotation formats use.
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
            $lines[] = $baseline->summaryLine();
        }

        if ($rows !== []) {
            $lines[] = '';
            $lines[] = self::HEADER_ROW;
            $lines[] = self::SEPARATOR_ROW;
            foreach ($rows as $finding) {
                $lines[] = $this->row($finding, $baseline);
            }
        }

        $notes = $this->notes($report, $baseline);
        if ($notes !== []) {
            $lines[] = '';
            foreach ($notes as $note) {
                $lines[] = '- note: '.$note;
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

        return '| '.$finding->priority().' | `'.self::cell($finding->package()).'` | '.self::cell($finding->version())
            .' | '.$verdict.' | '.self::cell($this->evidence($finding)).' | '.self::cell($this->via($finding)).' |';
    }

    private function evidence(Finding $finding): string
    {
        $evidence = $finding->evidence();
        if ($finding->allowlistReason() !== null) {
            $evidence = ($evidence === '' ? '' : $evidence.'; ').'allowlisted: '.$finding->allowlistReason();
        }

        return $evidence;
    }

    private function via(Finding $finding): string
    {
        $chain = $finding->chain();
        if ($chain === []) {
            return '?';
        }
        array_pop($chain);

        return $chain === [] ? 'direct' : implode(' › ', $chain);
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
            .'. Run `composer lockrot --format=json` for details.</sub>';
    }

    /** Escapes the two characters that would otherwise break a Markdown table cell. */
    private static function cell(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);

        return str_replace('|', '\\|', $value);
    }
}
