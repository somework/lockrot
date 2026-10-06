<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Symfony\Component\Console\Formatter\OutputFormatterStyle;

/**
 * Text from outside lockrot made safe to write to a terminal as it is, and lockrot's own stderr
 * lines coloured without Symfony's tag formatter ({@see ConsoleMarkup} says why). Callers write
 * these lines with OutputInterface::OUTPUT_RAW or IOInterface::writeErrorRaw(). The colour comes
 * from {@see OutputFormatterStyle::apply()}, which parses nothing.
 *
 * @internal
 */
final class TerminalText
{
    /** Marks a text cut at its limit. Printable, and never produced by an escape. */
    public const ELLIPSIS = '…';

    /**
     * One unit of text at the offset reached so far (`\G`). Each byte belongs to one alternative.
     * `unsafe` is a well-formed character that is still unsafe to print: a C1 control, a Unicode
     * Bidi_Control character, a line or paragraph separator or the byte order mark. `character` is
     * any other UTF-8 character that RFC 3629 allows. `invalid` is one byte that starts none.
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

    private const NAMED = ["\n" => '\\n', "\r" => '\\r', "\t" => '\\t'];

    /** The UTF-8 lead-byte marker by length. Every `unsafe` character has two or three bytes. */
    private const LEAD_MARKER = [2 => 0xC0, 3 => 0xE0];

    /**
     * Writes each character that could break the line, recolour or reorder the terminal, or print
     * as nothing as an escape, and the backslash as `\\`, so two different texts never print alike
     * (docs/configuration.md#unknown-keys). Past $maxBytes of output, the text is cut and
     * {@see self::ELLIPSIS} follows. An escape or a character is shown whole or not at all, so the
     * cut can land a few bytes short of the limit.
     */
    public static function escape(string $text, int $maxBytes = \PHP_INT_MAX): string
    {
        return self::render($text, true, $maxBytes);
    }

    /** {@see self::escape()} with the backslash kept, for text people read, such as a path. */
    public static function neutralise(string $text): string
    {
        return self::render($text, false, \PHP_INT_MAX);
    }

    public static function warning(string $line, bool $decorated): string
    {
        return $decorated ? (new OutputFormatterStyle('black', 'yellow'))->apply($line) : $line;
    }

    /** Each line is coloured on its own, so the colour never runs on into the margin. */
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
                // A run of printable ASCII shows byte for byte, so it can be cut anywhere. Anything
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

    /** Decoded by hand, because ext-mbstring is not a requirement. */
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
