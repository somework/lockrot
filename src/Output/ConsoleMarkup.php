<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Symfony\Component\Console\Formatter\OutputFormatterStyle;

/**
 * The markup lockrot's terminal formats are written in — `<fg=red>`, `<options=bold>`, Composer's
 * `<warning>`, `\<` for a bracket of the text — and the renderer that turns it into what a terminal
 * is sent, in place of Symfony's tag formatter.
 *
 * The markup reads like Symfony's, so a formatter's output is what it always was, but only lockrot
 * reads it. Symfony's formatter cannot be trusted with text lockrot did not write, whatever the
 * escaping: OutputFormatter::escape() on the symfony/console 5.4 releases inside Composer's PHARs
 * leaves the second `<` of `<<` live, so a package name like `<<fg=red>>` throws from the formatter
 * or opens a style or an OSC-8 link, a long run of `<b` exhausts PCRE's JIT and lets a style run
 * past its closing tag, and a `\<` loses its backslash; symfony/console 2.8 (Composer 2.2 LTS)
 * unescapes `\<` but not `\>`. So {@see self::escape()} escapes every bracket and every backslash,
 * {@see self::render()} undoes exactly that without a regular expression, and the result is written
 * raw (OutputInterface::OUTPUT_RAW, IOInterface::writeErrorRaw()) on every console version alike.
 * The colours come from {@see OutputFormatterStyle::apply()}, which parses nothing, with the codes
 * the formatter used for the same tags.
 *
 * @internal
 */
final class ConsoleMarkup
{
    /**
     * Every style lockrot writes, as [foreground, background, options]: the styles its formatters
     * name, Symfony's `comment` and Composer's `warning`, which Composer registers on its IO.
     */
    private const STYLES = [
        'fg=red' => ['red', null, []],
        'fg=yellow' => ['yellow', null, []],
        'options=bold' => [null, null, ['bold']],
        'comment' => ['yellow', null, []],
        'warning' => ['black', 'yellow', []],
    ];

    /** The characters the markup gives a meaning, each escaped by a backslash in front of it. */
    private const ESCAPES = ['\\' => '\\\\', '<' => '\\<', '>' => '\\>'];

    /** Closes whichever style is open. */
    private const CLOSE_ANY = '';

    /**
     * $text as markup that renders as $text: every backslash, `<` and `>` in it escaped. For text
     * without a backslash this is what symfony/console 5.4's OutputFormatter::escape() writes for a
     * single bracket, so a formatter's markup reads as before.
     */
    public static function escape(string $text): string
    {
        return strtr($text, self::ESCAPES);
    }

    /**
     * The text $markup stands for: each escape undone, each tag gone and, when $decorated, the text
     * it encloses in its colours. A style stays open until its closing tag or `</>`; styles nest,
     * the innermost one colouring the text, as the formatter did.
     *
     * @throws \LogicException on markup lockrot does not write: an unknown style, a closing tag that
     *                         closes nothing or another style than the open one, a `<` with no `>`,
     *                         a backslash with nothing after it
     */
    public static function render(string $markup, bool $decorated): string
    {
        $rendered = '';
        /** @var list<string> $open */
        $open = [];
        $length = \strlen($markup);
        // Text up to the next backslash or tag, then that escape or tag, until the markup ends.
        $at = strcspn($markup, '\\<');
        $text = substr($markup, 0, $at);
        while ($at < $length) {
            if ($markup[$at] === '\\') {
                // Only escape() writes a backslash, always in front of the character it escapes.
                if ($at + 1 === $length) {
                    throw new \LogicException('a backslash ends lockrot\'s own markup');
                }
                $text .= $markup[$at + 1];
                $at += 2;
            } else {
                $end = strpos($markup, '>', $at);
                if ($end === false) {
                    throw new \LogicException('unterminated console tag in lockrot\'s own markup');
                }
                $rendered .= self::styled($text, $open, $decorated);
                $text = '';
                $open = self::withTag($open, substr($markup, $at + 1, $end - $at - 1));
                $at = $end + 1;
            }
            $run = strcspn($markup, '\\<', $at);
            $text .= substr($markup, $at, $run);
            $at += $run;
        }

        return $rendered.self::styled($text, $open, $decorated);
    }

    /**
     * $open with $tag applied: a style pushed, or the innermost one popped.
     *
     * @param list<string> $open
     *
     * @return list<string>
     */
    private static function withTag(array $open, string $tag): array
    {
        if (strpos($tag, '/') !== 0) {
            if (!isset(self::STYLES[$tag])) {
                throw new \LogicException('unknown console style <'.$tag.'> in lockrot\'s own markup');
            }
            $open[] = $tag;

            return $open;
        }
        $closes = substr($tag, 1);
        $innermost = array_pop($open);
        if ($innermost === null) {
            throw new \LogicException('closing tag <'.$tag.'> with no style open in lockrot\'s own markup');
        }
        if ($closes !== self::CLOSE_ANY && $closes !== $innermost) {
            throw new \LogicException('closing tag <'.$tag.'> does not close <'.$innermost.'> in lockrot\'s own markup');
        }

        return $open;
    }

    /** @param list<string> $open */
    private static function styled(string $text, array $open, bool $decorated): string
    {
        $innermost = end($open);
        if (!$decorated || $innermost === false || $text === '') {
            return $text;
        }
        [$foreground, $background, $options] = self::STYLES[$innermost];

        return (new OutputFormatterStyle($foreground, $background, $options))->apply($text);
    }
}
