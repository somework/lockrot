<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Priority;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * The default format: a grouped, width-aware list.
 *
 * Findings are grouped by priority, headed by their level and count, and each row is two lines of
 * prose rather than a cell in a five-column box — a box only reads on a terminal wide enough to
 * hold the widest evidence string, which is not a terminal anybody has. Nothing machine-readable
 * depends on this shape; `--format=json` exists for that.
 *
 * Every string that comes from the analysed project is passed through
 * {@see OutputFormatter::escape()} before it is written, so a `<` in a constraint, a package name
 * or a note can never be read as a console tag. Styles use explicit closing tags rather than `</>`
 * because the colour table, not the shorthand, is what symfony/console 2.8 and 5.4 agree on.
 */
final class TableFormatter implements FormatterInterface
{
    /** `old-promise`, the longest bare verdict: the label column never gets narrower than this. */
    private const MIN_LABEL_WIDTH = 11;

    /** However narrow the terminal claims to be, text is never wrapped tighter than this. */
    private const MIN_WRAP_WIDTH = 20;

    /** Columns of whitespace before the label and between the label and the text. */
    private const GAP = 2;

    private FormatContext $context;

    public function __construct(FormatContext $context)
    {
        $this->context = $context;
    }

    public function format(Report $report, bool $showAll = false): string
    {
        $baseline = $report->baseline();
        $rows = $showAll ? $report->findings() : $report->flagged();
        $lines = $rows === []
            ? [self::escape(\sprintf('No dependency rot found in %d packages.', $report->packagesChecked()))]
            : $this->groupLines($rows, $baseline);
        foreach ($this->summaryLines($report, $baseline) as $line) {
            $lines[] = $line;
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The grouped rows, followed by the blank line that separates them from the summary block.
     *
     * @param list<Finding> $rows
     *
     * @return list<string>
     */
    private function groupLines(array $rows, ?BaselineComparison $baseline): array
    {
        $labelWidth = $this->labelWidth($rows, $baseline);
        $indent = self::GAP + $labelWidth + self::GAP;
        $wrap = max(self::MIN_WRAP_WIDTH, $this->context->terminalWidth() - $indent);
        $lines = [];
        foreach ($this->groups($rows) as $priority => $group) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = '<options=bold>'.self::escape(self::header((string) $priority, \count($group))).'</options=bold>';
            foreach ($group as $finding) {
                foreach ($this->rowLines($finding, $baseline, $labelWidth, $indent, $wrap) as $line) {
                    $lines[] = $line;
                }
            }
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * The rows split by priority, in {@see Priority::all()} order, empty levels dropped. The order
     * inside a group is the report's own, which already sorts by priority first.
     *
     * @param list<Finding> $rows
     *
     * @return array<string, list<Finding>>
     */
    private function groups(array $rows): array
    {
        $groups = array_fill_keys(Priority::all(), []);
        foreach ($rows as $finding) {
            $groups[$finding->priority()][] = $finding;
        }

        return array_filter($groups, static fn (array $group): bool => $group !== []);
    }

    /** `critical (2)`; the unflagged rows `--all` adds are headed by what they are instead. */
    private static function header(string $priority, int $count): string
    {
        return ($priority === Priority::NONE ? 'not flagged' : $priority).' ('.$count.')';
    }

    /**
     * One row: the label, the package, the version and where it is required from on the first line,
     * the evidence on the lines after it. Both halves are wrapped to the terminal and every
     * continuation line is indented past the label column, so the label stays the only thing in the
     * left margin.
     *
     * @return list<string>
     */
    private function rowLines(Finding $finding, ?BaselineComparison $baseline, int $labelWidth, int $indent, int $wrap): array
    {
        $label = $this->label($finding, $baseline);
        $head = str_repeat(' ', self::GAP)
            .$this->styled($finding, self::escape($label))
            .str_repeat(' ', $labelWidth - \strlen($label) + self::GAP);
        $margin = str_repeat(' ', $indent);
        $tail = self::wrap($finding->package().' '.$finding->version().'  '.self::via($finding), $wrap);
        $lines = [$head.array_shift($tail)];
        foreach ($tail as $line) {
            $lines[] = $margin.$line;
        }
        $evidence = self::evidence($finding);
        if ($evidence !== '') {
            foreach (self::wrap($evidence, $wrap) as $line) {
                $lines[] = $margin.$line;
            }
        }

        return $lines;
    }

    /**
     * Widest label among the rows about to be printed, so the column fits without measuring the
     * findings that are not shown.
     *
     * @param list<Finding> $rows
     */
    private function labelWidth(array $rows, ?BaselineComparison $baseline): int
    {
        $width = self::MIN_LABEL_WIDTH;
        foreach ($rows as $finding) {
            $width = max($width, \strlen($this->label($finding, $baseline)));
        }

        return $width;
    }

    /**
     * The verdict, annotated with how the baseline sees this package: `(baseline)` for a finding the
     * project already accepted, `(was stale)` for one that has got worse since. A new finding, and
     * every row of a run with no baseline, shows the bare verdict.
     */
    private function label(Finding $finding, ?BaselineComparison $baseline): string
    {
        if ($baseline === null) {
            return $finding->verdict();
        }
        $status = $baseline->statusOf($finding->package());
        if ($status === BaselineComparison::KNOWN) {
            return $finding->verdict().' (baseline)';
        }
        if ($status === BaselineComparison::WORSENED) {
            return $finding->verdict().' (was '.(string) $baseline->previousVerdictOf($finding->package()).')';
        }

        return $finding->verdict();
    }

    /**
     * Colour by priority rather than by verdict: the same verdict deserves a different amount of a
     * reader's attention depending on how this project pulls the package in. Low and unflagged rows
     * are left alone so the coloured ones stand out.
     */
    private function styled(Finding $finding, string $escapedLabel): string
    {
        $priority = $finding->priority();
        if ($priority === Priority::CRITICAL || $priority === Priority::HIGH) {
            return '<fg=red>'.$escapedLabel.'</fg=red>';
        }
        if ($priority === Priority::MEDIUM) {
            return '<fg=yellow>'.$escapedLabel.'</fg=yellow>';
        }

        return $escapedLabel;
    }

    /** `direct`, `via a › b`, or `?` when nothing in the project reaches the package. */
    private static function via(Finding $finding): string
    {
        $chain = $finding->chain();
        if ($chain === []) {
            return '?';
        }
        array_pop($chain);

        return $chain === [] ? 'direct' : 'via '.implode(' › ', $chain);
    }

    private static function evidence(Finding $finding): string
    {
        $evidence = $finding->evidence();
        if ($finding->allowlistReason() !== null) {
            $evidence = ($evidence === '' ? '' : $evidence.'; ').'allowlisted: '.$finding->allowlistReason();
        }

        return $evidence;
    }

    /**
     * Wrapped to $wrap columns, long words cut rather than allowed to overflow, each resulting line
     * escaped. Wrapping happens before escaping: escaping inserts backslashes the terminal never
     * shows, and counting those would wrap early. Lines are right-trimmed — a run of spaces in the
     * source text can otherwise end a line with invisible padding.
     *
     * @return list<string>
     */
    private static function wrap(string $text, int $wrap): array
    {
        $lines = [];
        foreach (explode("\n", wordwrap($text, $wrap, "\n", true)) as $line) {
            $lines[] = self::escape(rtrim($line));
        }

        return $lines;
    }

    /**
     * The block every run ends with: the counts, the priority totals, what the baseline made of the
     * run, the data date, and the notes.
     *
     * @return list<string>
     */
    private function summaryLines(Report $report, ?BaselineComparison $baseline): array
    {
        $lines = [self::escape($report->summaryLine()), self::escape($report->prioritySummaryLine())];
        if ($baseline !== null) {
            $lines[] = self::escape($baseline->summaryLine());
        }
        $lines[] = self::escape(\sprintf(
            'Data as of %s (package repositories, GitHub). Run composer lockrot --format=json for details.',
            $report->generatedAt()->format('Y-m-d')
        ));
        foreach ($report->notes() as $note) {
            $lines[] = 'note: '.self::escape($note);
        }
        $stale = $baseline === null ? null : $baseline->staleNote();
        if ($stale !== null) {
            $lines[] = 'note: '.self::escape($stale);
        }

        return $lines;
    }

    /**
     * symfony/console's own escaping, which both bundled versions ship (5.4.47
     * Formatter/OutputFormatter.php:43, 2.8.52 same file:34).
     */
    private static function escape(string $text): string
    {
        return OutputFormatter::escape($text);
    }
}
