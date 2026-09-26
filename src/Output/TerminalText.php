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
     * One unit of text, matched at the offset reached so far (`\G`); every byte belongs to exactly
     * one alternative, and the group that matched says what the unit is:
     *
     * - `backslash`: one backslash;
     * - `control`: one C0 control or DEL;
     * - `printable`: a run of printable ASCII other than the backslash;
     * - `unsafe`: one well-formed character that is still unsafe to print — a C1 control
     *   (U+0080–U+009F, C2 80–9F), which some terminals obey as an escape sequence; a Unicode
     *   Bidi_Control character (U+061C, U+200E–U+200F, U+202A–U+202E, U+2066–U+2069), which reorders
     *   what follows it; the line and paragraph separators (U+2028–U+2029), which break a line; or the
     *   byte order mark (U+FEFF), which prints as nothing;
     * - `character`: any other well-formed UTF-8 character (RFC 3629: no overlong form, no surrogate,
     *   nothing past U+10FFFF);
     * - `invalid`: one byte that starts none of the above.
     */
    private const UNIT = '/\G(?:
        (?<backslash>\\\\)
        |(?<control>[\x00-\x1F\x7F])
        |(?<printable>[\x20-\x5B\x5D-\x7E]+)
        |(?<unsafe>\xC2[\x80-\x9F]|\xD8\x9C|\xE2\x80[\x8E\x8F\xA8-\xAE]|\xE2\x81[\xA6-\xA9]|\xEF\xBB\xBF)
        |(?<character>
            [\xC2-\xDF][\x80-\xBF]
            |\xE0[\xA0-\xBF][\x80-\xBF]
            |[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}
            |\xED[\x80-\x9F][\x80-\xBF]
            |\xF0[\x90-\xBF][\x80-\xBF]{2}
            |[\xF1-\xF3][\x80-\xBF]{3}
            |\xF4[\x80-\x8F][\x80-\xBF]{2}
        )
        |(?<invalid>[\x80-\xFF])
    )/x';

    /** The C0 controls with a name everyone reads; the rest are written in hex. */
    private const NAMED = ["\n" => '\\n', "\r" => '\\r', "\t" => '\\t'];

    /**
     * The length marker of a UTF-8 lead byte, by the length of the character it starts: what is left
     * after subtracting it is the top bits of the code point. Every `unsafe` character is two or
     * three bytes long.
     */
    private const LEAD_MARKER = [2 => 0xC0, 3 => 0xE0];

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
        $shown = '';
        $offset = 0;
        // One unit at a time, so an arbitrarily long key costs no more than the part that is shown.
        while (preg_match(self::UNIT, $text, $unit, \PREG_UNMATCHED_AS_NULL, $offset) === 1) {
            $offset += \strlen($unit[0]);
            $piece = self::show($unit, $escapeBackslash);
            if (\strlen($shown) + \strlen($piece) > $maxBytes) {
                // A run of printable ASCII shows byte for byte, so it can be cut anywhere; anything
                // else is shown whole or not at all.
                $fits = $unit['printable'] === null ? '' : substr($piece, 0, $maxBytes - \strlen($shown));

                return $shown.$fits.self::ELLIPSIS;
            }
            $shown .= $piece;
        }

        return $shown;
    }

    /**
     * @param array{
     *     0: string,
     *     backslash: string|null,
     *     control: string|null,
     *     printable: string|null,
     *     unsafe: string|null,
     *     character: string|null,
     *     invalid: string|null
     * } $unit a match of {@see self::UNIT}, the group that matched the only one not null
     */
    private static function show(array $unit, bool $escapeBackslash): string
    {
        $text = $unit[0];
        if ($unit['backslash'] !== null) {
            return $escapeBackslash ? '\\\\' : '\\';
        }
        if ($unit['control'] !== null) {
            return self::NAMED[$text] ?? self::hex($text);
        }
        if ($unit['invalid'] !== null) {
            return self::hex($text);
        }
        if ($unit['unsafe'] !== null) {
            return \sprintf('\\u{%04X}', self::codePoint($text));
        }

        return $text;
    }

    private static function hex(string $byte): string
    {
        return \sprintf('\\x%02X', \ord($byte));
    }

    /**
     * The code point of one two- or three-byte UTF-8 character: the lead byte without its length
     * marker, then six bits from each continuation byte. Decoded by hand because ext-mbstring is not
     * a requirement.
     */
    private static function codePoint(string $character): int
    {
        $length = \strlen($character);
        $codePoint = \ord($character[0]) - self::LEAD_MARKER[$length];
        for ($i = 1; $i < $length; ++$i) {
            $codePoint = ($codePoint << 6) | (\ord($character[$i]) & 0x3F);
        }

        return $codePoint;
    }
}
