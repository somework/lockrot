<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Symfony\Component\Console\Formatter\OutputFormatterStyle;

/**
 * Text from outside lockrot — a composer.json key, an exception message — made safe to write to a
 * terminal as it is, and lockrot's own stderr lines coloured without Symfony's tag formatter.
 *
 * The formatter is the wrong tool for text lockrot did not write. OutputFormatter::escape() on the
 * symfony/console 5.4 releases inside Composer's PHARs leaves the second `<` of `<<` live, so a key
 * like `<<fg=red>>` can throw from the formatter or open an OSC-8 link, a long run of `<b` exhausts
 * PCRE's JIT and lets a style leak past its closing tag, and a `\<` loses its backslash. Callers
 * write these lines with OutputInterface::OUTPUT_RAW or IOInterface::writeErrorRaw() instead, which
 * the formatter never sees, on symfony/console 2.8 (Composer 2.2 LTS) and 5.4 alike; the colour, when
 * the output is decorated, comes from {@see OutputFormatterStyle::apply()}, which parses nothing.
 *
 * @internal
 */
final class TerminalText
{
    /** Marks a text cut at its limit. Printable, and never produced by an escape. */
    public const ELLIPSIS = '…';

    /**
     * One unit of text per match, every byte covered by exactly one alternative: a backslash, one
     * C0 control or DEL, a run of printable ASCII, one well-formed UTF-8 character (RFC 3629: no
     * overlong forms, no surrogates, nothing past U+10FFFF), or one byte that starts none of those.
     */
    private const UNIT = '/
        \\\\
        |[\x00-\x1F\x7F]
        |[\x20-\x5B\x5D-\x7E]+
        |[\xC2-\xDF][\x80-\xBF]
        |\xE0[\xA0-\xBF][\x80-\xBF]
        |[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}
        |\xED[\x80-\x9F][\x80-\xBF]
        |\xF0[\x90-\xBF][\x80-\xBF]{2}
        |[\xF1-\xF3][\x80-\xBF]{3}
        |\xF4[\x80-\x8F][\x80-\xBF]{2}
        |[\x80-\xFF]
    /x';

    /** The C0 controls with a name everyone reads; the rest are written in hex. */
    private const NAMED = ["\n" => '\\n', "\r" => '\\r', "\t" => '\\t'];

    /**
     * Code points that are valid UTF-8 and still unsafe to print: the C1 controls (U+0080–U+009F),
     * which some terminals obey as escape sequences; the line and paragraph separators, which break
     * a line; the Unicode Bidi_Control characters, which reorder what follows them; and the byte
     * order mark, which prints as nothing. Each pair is an inclusive range.
     */
    private const UNSAFE = [
        [0x80, 0x9F],
        [0x061C, 0x061C],
        [0x200E, 0x200F],
        [0x2028, 0x202E],
        [0x2066, 0x2069],
        [0xFEFF, 0xFEFF],
    ];

    /**
     * $text with every character that could break the line, recolour or reorder the terminal, or
     * print as nothing written as an escape — `\n`, `\r`, `\t`, `\xNN` for any other C0 byte, DEL or
     * an invalid UTF-8 byte, `\u{NNNN}` for an unsafe code point — and the backslash itself as `\\`,
     * so two different texts are never shown alike.
     *
     * Past $maxBytes of what is shown, the text is cut and {@see self::ELLIPSIS} follows. An escape or
     * a character is shown whole or not at all, so the cut can land a few bytes short of the limit.
     */
    public static function escape(string $text, int $maxBytes = \PHP_INT_MAX): string
    {
        return self::render($text, true, $maxBytes);
    }

    /**
     * {@see self::escape()} with the backslash left as it is, for text people read rather than
     * compare, such as an exception message with a Windows path in it.
     */
    public static function neutralise(string $text): string
    {
        return self::render($text, false, \PHP_INT_MAX);
    }

    /** $line in Composer's `warning` style, black on yellow, when $decorated; as it is otherwise. */
    public static function warning(string $line, bool $decorated): string
    {
        return $decorated ? (new OutputFormatterStyle('black', 'yellow'))->apply($line) : $line;
    }

    /**
     * $text in Symfony's `error` style, white on red, when $decorated; as it is otherwise. Each line
     * is coloured on its own, as the formatter does, so the colour never runs on into the margin.
     */
    public static function error(string $text, bool $decorated): string
    {
        if (!$decorated) {
            return $text;
        }
        $style = new OutputFormatterStyle('white', 'red');

        return implode("\n", array_map([$style, 'apply'], explode("\n", $text)));
    }

    private static function render(string $text, bool $escapeBackslash, int $maxBytes): string
    {
        // Every unit is shown in at least as many bytes as it takes, so the first $maxBytes + 1 bytes
        // are enough to fill the limit and to know the text went past it. An arbitrarily long key
        // costs no more than a short one.
        if ($maxBytes < \PHP_INT_MAX) {
            $text = substr($text, 0, $maxBytes + 1);
        }
        preg_match_all(self::UNIT, $text, $matches);
        $shown = '';
        foreach ($matches[0] as $unit) {
            $piece = self::show($unit, $escapeBackslash);
            if (\strlen($shown) + \strlen($piece) > $maxBytes) {
                // A run of printable ASCII shows byte for byte, so it can be cut anywhere.
                if (self::isPrintableRun($unit)) {
                    $shown .= substr($piece, 0, $maxBytes - \strlen($shown));
                }

                return $shown.self::ELLIPSIS;
            }
            $shown .= $piece;
        }

        return $shown;
    }

    private static function show(string $unit, bool $escapeBackslash): string
    {
        if ($unit === '\\') {
            return $escapeBackslash ? '\\\\' : '\\';
        }
        if (self::isPrintableRun($unit)) {
            return $unit;
        }
        if (\strlen($unit) === 1) {
            return self::NAMED[$unit] ?? \sprintf('\\x%02X', \ord($unit));
        }
        $codePoint = self::codePoint($unit);

        return self::isUnsafe($codePoint) ? \sprintf('\\u{%04X}', $codePoint) : $unit;
    }

    private static function isPrintableRun(string $unit): bool
    {
        return preg_match('/^[\x20-\x5B\x5D-\x7E]+$/', $unit) === 1;
    }

    /**
     * The code point of one well-formed UTF-8 character of two to four bytes: the lead byte's
     * payload bits (it has one more marker bit than the character has bytes), then six bits from
     * each continuation byte. Decoded by hand because ext-mbstring is not a requirement.
     */
    private static function codePoint(string $character): int
    {
        $length = \strlen($character);
        $codePoint = \ord($character[0]) & (0xFF >> ($length + 1));
        for ($i = 1; $i < $length; ++$i) {
            $codePoint = ($codePoint << 6) | (\ord($character[$i]) & 0x3F);
        }

        return $codePoint;
    }

    private static function isUnsafe(int $codePoint): bool
    {
        foreach (self::UNSAFE as [$first, $last]) {
            if ($codePoint >= $first && $codePoint <= $last) {
                return true;
            }
        }

        return false;
    }
}
