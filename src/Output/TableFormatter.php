<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Priority;

/**
 * The `table` format, a list grouped by priority (docs/example-run.md#the-table-format). A row is
 * prose, not a cell in a box, because a box needs a terminal wider than the widest evidence string.
 *
 * The result is {@see ConsoleMarkup}, which only {@see ConsoleMarkup::render()} reads, never
 * Symfony's tag formatter. Every string from the analysed project goes through
 * {@see ConsoleMarkup::escape()}, so a `<` or a backslash prints as written and is never a tag.
 *
 * @internal
 */
final class TableFormatter implements FormatterInterface
{
    /** The width of `old-promise` and `left-behind`, the longest bare verdicts. */
    private const MIN_LABEL_WIDTH = 11;

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
        $flagged = $report->flagged();
        $rows = $showAll ? $report->findings() : $flagged;
        $lines = $rows === []
            ? [self::escape(\sprintf('No dependency rot found in %d packages.', $report->packagesChecked())), '']
            : $this->groupLines($rows, $baseline);
        foreach ($this->summaryLines($report, $baseline, $flagged !== []) as $line) {
            $lines[] = $line;
        }

        return implode("\n", $lines)."\n";
    }

    /**
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
     * Groups in {@see Priority::all()} order. Inside a group, the order is the report's.
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

    private static function header(string $priority, int $count): string
    {
        return ($priority === Priority::NONE ? 'not flagged' : $priority).' ('.$count.')';
    }

    /**
     * @return list<string>
     */
    private function rowLines(Finding $finding, ?BaselineComparison $baseline, int $labelWidth, int $indent, int $wrap): array
    {
        $label = $this->label($finding, $baseline);
        $head = str_repeat(' ', self::GAP)
            .$this->styled($finding, self::escape($label))
            .str_repeat(' ', $labelWidth - \strlen($label) + self::GAP);
        $margin = str_repeat(' ', $indent);
        $tail = self::wrap($finding->package().' '.$finding->version().'  '.Via::inline($finding, ' › '), $wrap, true);
        $lines = [$head.array_shift($tail)];
        foreach ($tail as $line) {
            $lines[] = $margin.$line;
        }
        $evidence = $finding->evidenceLine();
        if ($evidence !== '') {
            foreach (self::wrap($evidence, $wrap, true) as $line) {
                $lines[] = $margin.$line;
            }
        }

        return $lines;
    }

    /** @param list<Finding> $rows */
    private function labelWidth(array $rows, ?BaselineComparison $baseline): int
    {
        $width = self::MIN_LABEL_WIDTH;
        foreach ($rows as $finding) {
            $width = max($width, \strlen($this->label($finding, $baseline)));
        }

        return $width;
    }

    /** The verdict, annotated by the baseline (docs/baseline.md#reading-a-baseline). */
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

    /** Colour follows priority, not verdict (docs/example-run.md#the-table-format). */
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

    /**
     * With $cut, a word longer than $wrap is cut, so a row is never wider than the terminal. Without
     * it, a path stays whole, so a reader can copy it. The text is wrapped before it is escaped: the
     * added backslashes do not print. `wordwrap()` counts bytes, which can only wrap early. The chain
     * separator is its own token and is never cut. With $cut, a single non-ASCII word longer than
     * $wrap (only an allowlist reason holds one) is cut mid-codepoint: lockrot does not require
     * ext-mbstring. Each line is right-trimmed, so spaces do not pad its end.
     *
     * @return list<string>
     */
    private static function wrap(string $text, int $wrap, bool $cut): array
    {
        $lines = [];
        foreach (explode("\n", wordwrap($text, $wrap, "\n", $cut)) as $line) {
            $lines[] = self::escape(rtrim($line));
        }

        return $lines;
    }

    /**
     * The summary block (docs/example-run.md#the-table-format). {@see Report::summaryLine()} is
     * shared with the `github` format, which pins itself against it, so only this renderer decides
     * where it folds. The priority totals print only when something is flagged, because a clean
     * report shows only zeros.
     *
     * @return list<string>
     */
    private function summaryLines(Report $report, ?BaselineComparison $baseline, bool $hasFlagged): array
    {
        $texts = [$report->summaryLine()];
        if ($hasFlagged) {
            $texts[] = $report->prioritySummaryLine();
        }
        // A clean report prints it too: a lock with nothing to flag can still be behind.
        $texts[] = $report->libyears()->line();
        $exposure = $report->exposureSummaryLine();
        if ($exposure !== '') {
            $texts[] = $exposure;
        }
        $unflagged = $report->unflaggedAdvisoriesLine();
        if ($unflagged !== '') {
            $texts[] = $unflagged;
        }
        if ($baseline !== null) {
            $texts[] = $baseline->summaryLine();
        }
        $texts[] = \sprintf(
            'Data as of %s (%s). Run composer lockrot --format=json for details.',
            $report->generatedAt()->format('Y-m-d'),
            $report->dataSourcesClause()
        );
        foreach ($report->notes() as $note) {
            $texts[] = 'note: '.$note;
        }
        $stale = $baseline === null ? null : $baseline->staleNote();
        if ($stale !== null) {
            $texts[] = 'note: '.$stale;
        }
        $wrap = max(self::MIN_WRAP_WIDTH, $this->context->terminalWidth());
        $lines = [];
        foreach ($texts as $text) {
            foreach (strpos($text, ' · ') !== false ? self::wrapBetweenItems($text, $wrap) : self::wrapKeepingCommands($text, $wrap) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** Commands the footer names: one thing to copy, so each stays on one line and can overflow. */
    private const COMMANDS = ['composer lockrot --format=json', 'composer audit'];

    /**
     * @return list<string>
     */
    private static function wrapKeepingCommands(string $text, int $wrap): array
    {
        $glued = [];
        foreach (self::COMMANDS as $command) {
            $glued[] = str_replace(' ', "\x1F", $command);
        }
        $lines = [];
        foreach (self::wrap(str_replace(self::COMMANDS, $glued, $text), $wrap, false) as $line) {
            $lines[] = str_replace("\x1F", ' ', $line);
        }

        return $lines;
    }

    /**
     * Folds a list of `label N` items joined by ` · ` between items, because a fold at any space
     * can part a label from its number. The separator stays at the end of the line it closes, and
     * an item wider than the terminal stays whole. Bytes are counted as in {@see wrap()}, including
     * the closing ` ·` of every item but the last, so a line is never wider than $wrap.
     *
     * @return list<string>
     */
    private static function wrapBetweenItems(string $text, int $wrap): array
    {
        $lines = [];
        $line = '';
        $items = explode(' · ', $text);
        $last = \count($items) - 1;
        foreach ($items as $i => $item) {
            if ($line === '') {
                $line = $item;
            } elseif (\strlen($line) + 4 + \strlen($item) + ($i === $last ? 0 : 3) <= $wrap) {
                $line .= ' · '.$item;
            } else {
                $lines[] = self::escape($line.' ·');
                $line = $item;
            }
        }
        $lines[] = self::escape($line);

        return $lines;
    }

    private static function escape(string $text): string
    {
        return ConsoleMarkup::escape($text);
    }
}
