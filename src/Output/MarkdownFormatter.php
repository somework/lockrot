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
 * Everything that comes from the project or from package metadata — names, versions, evidence, the
 * replacement string of an abandoned package, notes, the baseline file name — is rendered as plain
 * text: line breaks become a single space, and every Markdown or HTML punctuation character is
 * backslash-escaped, `|` included, so a crafted composer.lock can neither break the table nor put
 * an image, a tag or link markup of its own into a pull-request comment or a job summary (a bare
 * URL still autolinks, as it does in any GitHub comment). The package
 * name is a code span, delimited by more backticks than the name itself contains. The verdict cell
 * is bold only when FormatContext::levelOf() is not `note` — the same rule GitHub annotations and
 * GitLab severities use, so a reviewer's eye is drawn to exactly the findings that can fail the
 * build. The `Via` chain is joined with "›", which reads more naturally inline in a table cell than
 * the plain ">" the annotation formats use, and names the other direct requirements the package is
 * reachable from (`, also via …`). The `pulled in by:` line under the table sums that up per direct
 * requirement — see Report::exposureSummaryLine().
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
     * The characters that carry meaning in Markdown or start HTML, each backslash-escaped. CommonMark
     * lets any ASCII punctuation be escaped this way, and GitHub renders `\<` as a literal `<`, so an
     * `<img>` or a `[link](…)` in package metadata comes out as the text it is. `&` is escaped too,
     * so an entity such as `&lt;` cannot smuggle a tag past the `<` escape.
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
     * A code span. Backslash escapes do not apply inside one, so a backtick in the content is
     * handled the CommonMark way: the delimiter is one backtick longer than the longest run inside,
     * and a leading or trailing backtick gets a space of padding. `|` still has to be escaped, as
     * GitHub's table parser reads it before the code span does.
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
