<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Config\Gate;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Move;
use Lockrot\Verdict\ShellCommand;

/**
 * The `table` format, a list grouped by grade. A row is prose, not a cell in a box,
 * because a box needs a terminal wider than the widest evidence string.
 *
 * The result is {@see ConsoleMarkup}, which only {@see ConsoleMarkup::render()} reads, never
 * Symfony's tag formatter. Every string from the analysed project goes through
 * {@see TerminalText::neutralise()} and {@see ConsoleMarkup::escape()}, so a control character
 * prints as an escape, and a `<` or a backslash prints as written and is never a tag.
 *
 * @internal
 */
final class TableFormatter implements FormatterInterface
{
    private const MIN_WRAP_WIDTH = 20;

    /** The block's columns: the header, the lines under it and the evidence text after its flag word. */
    private const INDENT = '  ';
    private const LINE = '      ';
    /** A `do:` line continues under the text after `do: `. */
    private const DO_HANG = '          ';
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
     * the report's order. The report sorts in verdict order, so the groups come in it.
     *
     * @param list<Finding> $rows
     *
     * @return array<string, list<Finding>>
     */
    private function groups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $finding) {
            $groups[$finding->isGraded() ? $finding->verdict() : self::NOT_FLAGGED][] = $finding;
        }

        return $groups;
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
        $move = $finding->isGraded() ? $finding->nextStep() : null;
        if ($move !== null) {
            $lines = array_merge($lines, $this->moveLines($move));
        }
        foreach (FindingBlock::evidence($finding) as $flag => $summary) {
            $lines = array_merge($lines, $this->wrapped(self::LINE.str_pad($flag, self::FLAG_WIDTH).' ', $summary, str_repeat(' ', \strlen(self::LINE) + self::FLAG_WIDTH + 1)));
        }
        foreach ([[FindingBlock::markingLine($finding), 2], [FindingBlock::ageLine($finding), 5], [FindingBlock::pullsIn($finding), 0], [FindingBlock::checksMissing($finding), 0], [FindingBlock::checksAccepted($finding), 0]] as [$text, $hang]) {
            if ($text !== null) {
                $lines = array_merge($lines, $this->wrapped(self::LINE, $text, str_repeat(' ', \strlen(self::LINE) + $hang)));
            }
        }
        $allowlist = FindingBlock::allowlist($finding);
        if ($allowlist !== null) {
            $lines = array_merge($lines, $this->wrapped(self::LINE.$allowlist[0].'  ', $allowlist[1], str_repeat(' ', 30)));
        }

        return $lines;
    }

    /**
     * The `do:` line: the move text wrapped, and each command that it names whole on a line of its
     * own, because a command that a wrap splits runs as two when it is copied.
     *
     * @return list<string>
     */
    private function moveLines(Move $move): array
    {
        $lines = [];
        foreach (self::commandParts($move) as [$part, $isCommand]) {
            $prefix = $lines === [] ? self::LINE.'do: ' : self::DO_HANG;
            $lines = array_merge($lines, $isCommand ? [self::escape($prefix.$part)] : $this->wrapped($prefix, $part, self::DO_HANG));
        }

        return $lines;
    }

    /** @return list<array{0: string, 1: bool}> the move text cut around each command that it names, and whether a part is one */
    private static function commandParts(Move $move): array
    {
        $text = $move->text();
        $parts = [];
        $at = 0;
        foreach ($move->commands() as $argv) {
            $command = ShellCommand::posix($argv);
            $found = $command === null ? false : strpos($text, $command, $at);
            if ($command === null || $found === false) {
                continue;
            }
            $parts[] = [trim(substr($text, $at, $found - $at)), false];
            $parts[] = [$command, true];
            $at = $found + \strlen($command);
        }
        $parts[] = [trim(substr($text, $at)), false];

        return array_values(array_filter($parts, static fn (array $part): bool => $part[0] !== ''));
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
        $out = [];
        // Neutralised before the wrap, so a line break in a string from outside never starts a line.
        foreach (explode("\n", wordwrap(TerminalText::neutralise($text), max(self::MIN_WRAP_WIDTH, $width - max(\strlen($prefix), \strlen($continuation))))) as $line) {
            $out[] = self::escape(($out === [] ? $prefix : $continuation).$line);
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
            $updateNow === null ? null : 'update now: '.RunFooter::platformStep($report).$updateNow,
            $report->libyears()->line(),
            RunFooter::exposure($report, $name),
            RunFooter::advisories($report),
            RunFooter::moveCheck($report),
            RunFooter::phpWindow($report, $name),
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
            $lines[] = $gate->fails() ? \sprintf('<fg=red>%s</fg=red>', $line) : $line;
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
     * which can only wrap early: lockrot does not require ext-mbstring.
     *
     * @return list<string>
     */
    private static function wrapNeutralised(string $text, int $wrap): array
    {
        $lines = [];
        foreach (explode("\n", wordwrap($text, $wrap, "\n")) as $line) {
            $lines[] = ConsoleMarkup::escape($line);
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
        // The glue goes in after the neutraliser, which would print it.
        foreach (self::wrapNeutralised(str_replace(self::COMMANDS, $glued, TerminalText::neutralise($text)), $wrap) as $line) {
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
        return ConsoleMarkup::escape(TerminalText::neutralise($text));
    }
}
