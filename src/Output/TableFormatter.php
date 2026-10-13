<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Config\Gate;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\ScoreModel;

/**
 * The `table` format, a list grouped by grade. A row is prose, not a cell in a box,
 * because a box needs a terminal wider than the widest evidence string.
 *
 * The result is {@see ConsoleMarkup}, which only {@see ConsoleMarkup::render()} reads, never
 * Symfony's tag formatter. Every string from the analysed project goes through
 * {@see ConsoleMarkup::escape()}, so a `<` or a backslash prints as written and is never a tag.
 *
 * @internal
 */
final class TableFormatter implements FormatterInterface
{
    private const MIN_WRAP_WIDTH = 20;

    /** The block's columns: the header, the lines under it and the evidence text after its flag word. */
    private const INDENT = '  ';
    private const LINE = '      ';
    private const FLAG_WIDTH = 12;
    private const NOT_FLAGGED = 'not flagged';

    private FormatContext $context;

    public function __construct(FormatContext $context)
    {
        $this->context = $context;
    }

    public function format(Report $report, bool $showAll = false): string
    {
        $gate = $this->context->gateOf($report);
        $lines = [];
        foreach ($this->groups($showAll ? $report->sorted() : $report->graded()) as $group => $findings) {
            $colour = FindingBlock::colour($group);
            $heading = self::escape($group.' ('.\count($findings).')');
            $lines[] = '<options=bold>'.($colour === null ? $heading : '<fg='.$colour.'>'.$heading.'</fg='.$colour.'>').'</options=bold>';
            foreach ($findings as $finding) {
                foreach ($this->blockLines($finding, $report, $gate) as $line) {
                    $lines[] = $line;
                }
            }
            $lines[] = '';
        }
        foreach ($this->footerLines($report, $gate) as $line) {
            $lines[] = $line;
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The grades in verdict order, then the score-0 findings under `not flagged`. Inside a group,
     * the report's order.
     *
     * @param list<Finding> $rows
     *
     * @return array<string, list<Finding>>
     */
    private function groups(array $rows): array
    {
        $groups = array_fill_keys(array_merge(ScoreModel::GRADES, [self::NOT_FLAGGED]), []);
        foreach ($rows as $finding) {
            $groups[$finding->isGraded() ? $finding->verdict() : self::NOT_FLAGGED][] = $finding;
        }

        return array_filter($groups, static fn (array $group): bool => $group !== []);
    }

    /** @return list<string> the finding block, in its order */
    private function blockLines(Finding $finding, Report $report, Gate $gate): array
    {
        $baseline = $report->baseline();
        $standing = $baseline === null ? null : $baseline->standingOf($finding->package());
        $lines = [self::INDENT.self::escape($finding->package().' '.$finding->version().'  '.FindingBlock::reach($finding).'  ·  '.implode(' · ', FindingBlock::headerFlags($finding, $standing)))];
        $suffix = FindingBlock::baselineSuffix($finding, $standing);
        $lines[] = self::LINE.self::escape(FindingBlock::scoreLine($finding).($suffix === null ? '' : ' '.$suffix));
        $gateLine = FindingBlock::gateLine($finding, $gate->standingOf($finding->package()));
        if ($gateLine !== null) {
            $lines[] = self::LINE.self::escape($gateLine);
        }
        $move = FindingBlock::move($finding);
        if ($move !== null) {
            $lines = array_merge($lines, $this->wrapped(self::LINE.'do: ', $move, self::LINE.'    '));
        }
        foreach (FindingBlock::evidence($finding) as $flag => $summary) {
            $lines = array_merge($lines, $this->wrapped(self::LINE.str_pad($flag, self::FLAG_WIDTH).' ', $summary, str_repeat(' ', \strlen(self::LINE) + self::FLAG_WIDTH + 1)));
        }
        foreach ([[FindingBlock::markingLine($finding), 2], [FindingBlock::ageLine($finding), 5], [FindingBlock::pullsIn($finding), 0], [FindingBlock::checksMissing($finding), 0], [FindingBlock::checksAccepted($finding), 0]] as [$text, $hang]) {
            if ($text !== null) {
                $lines = array_merge($lines, $this->wrapped(self::LINE, $text, self::LINE.str_repeat(' ', $hang)));
            }
        }
        $allowlist = FindingBlock::allowlist($finding);
        if ($allowlist !== null) {
            $lines = array_merge($lines, $this->wrapped(self::LINE.$allowlist[0].'  ', $allowlist[1], str_repeat(' ', 30)));
        }

        return $lines;
    }

    /**
     * $text wrapped to the terminal, its first line after $prefix and the rest after $continuation.
     * A first line after a prefix wider than the continuation gets the room that is left.
     *
     * @return list<string>
     */
    private function wrapped(string $prefix, string $text, string $continuation): array
    {
        $width = $this->context->terminalWidth();
        $first = explode("\n", wordwrap($text, max(self::MIN_WRAP_WIDTH, $width - max(\strlen($prefix), \strlen($continuation)))), 2);
        $out = [rtrim(self::escape($prefix.$first[0]))];
        foreach (isset($first[1]) ? self::wrap($first[1], max(self::MIN_WRAP_WIDTH, $width - \strlen($continuation))) : [] as $line) {
            $out[] = self::escape($continuation).$line;
        }

        return $out;
    }

    /**
     * The run footer. The `gate:` line is red when the gate fails.
     *
     * @return list<string>
     */
    private function footerLines(Report $report, Gate $gate): array
    {
        $name = static fn (string $package): string => $package;
        $baseline = $report->baseline();
        $updateNow = RunFooter::updateNow($report);
        $texts = [
            RunFooter::counts($report),
            RunFooter::flags($report),
            RunFooter::depth($report, $name),
            RunFooter::security($report, true),
            RunFooter::fixes($report),
            $updateNow === null ? null : 'update now: '.$updateNow,
            $report->libyears()->line(),
            RunFooter::exposure($report, $name),
            RunFooter::advisories($report),
            $baseline === null ? null : $baseline->summaryLine(),
        ];
        $wrap = max(self::MIN_WRAP_WIDTH, $this->context->terminalWidth());
        $lines = [];
        foreach ($texts as $text) {
            foreach ($text === null ? [] : self::wrapBetweenItems($text, $wrap) as $line) {
                $lines[] = $line;
            }
        }
        foreach (self::wrapBetweenItems(RunGateText::render($gate, $this->context->runOf($report)), $wrap) as $line) {
            $lines[] = $gate->fails() ? '<fg=red>'.$line.'</fg=red>' : $line;
        }
        foreach (self::wrapKeepingCommands(RunFooter::dataAsOf($report).'. Run composer lockrot --format=json for details.', $wrap) as $line) {
            $lines[] = $line;
        }
        $notes = $report->notes();
        $stale = $baseline === null ? null : $baseline->staleNote();
        if ($stale !== null) {
            $notes[] = $stale;
        }
        foreach ($notes as $note) {
            foreach (self::wrapKeepingCommands('note: '.$note, $wrap) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * A word longer than $wrap stays whole, so a reader can copy a path or a command. The text is
     * wrapped before it is escaped: the added backslashes do not print. `wordwrap()` counts bytes,
     * which can only wrap early: lockrot does not require ext-mbstring. Each line is right-trimmed,
     * so spaces do not pad its end.
     *
     * @return list<string>
     */
    private static function wrap(string $text, int $wrap): array
    {
        $lines = [];
        foreach (explode("\n", wordwrap($text, $wrap, "\n")) as $line) {
            $lines[] = self::escape(rtrim($line));
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
        foreach (self::wrap(str_replace(self::COMMANDS, $glued, $text), $wrap) as $line) {
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
