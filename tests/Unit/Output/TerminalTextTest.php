<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Output\TerminalText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TerminalTextTest extends TestCase
{
    /**
     * @dataProvider escapes
     */
    #[DataProvider('escapes')]
    public function testEscapeWritesEveryUnsafeCharacterAsAnEscape(string $text, string $shown): void
    {
        self::assertSame($shown, TerminalText::escape($text));
    }

    /** @return iterable<string, array{string, string}> */
    public static function escapes(): iterable
    {
        yield 'plain text is left alone' => ['install-time', 'install-time'];
        yield 'console tags are text' => ['<<fg=red>>', '<<fg=red>>'];
        yield 'a backslash is escaped itself' => ['a\\<b', 'a\\\\<b'];
        yield 'newline, tab and carriage return by name' => ["a\nb\tc\rd", 'a\\nb\\tc\\rd'];
        yield 'other C0 bytes in hex' => ["\0\x07\x1B[31m", '\\x00\\x07\\x1B[31m'];
        yield 'DEL' => ["a\x7F", 'a\\x7F'];
        yield 'a C1 control' => ["a\u{85}b\u{9B}", 'a\\u{0085}b\\u{009B}'];
        yield 'the line and paragraph separators' => ["a\u{2028}b\u{2029}", 'a\\u{2028}b\\u{2029}'];
        yield 'a right-to-left override' => ["a\u{202E}fdp.exe", 'a\\u{202E}fdp.exe'];
        yield 'every other bidi control' => ["\u{061C}\u{200E}\u{200F}\u{202A}\u{202B}\u{202C}\u{202D}\u{2066}\u{2067}\u{2068}\u{2069}", '\\u{061C}\\u{200E}\\u{200F}\\u{202A}\\u{202B}\\u{202C}\\u{202D}\\u{2066}\\u{2067}\\u{2068}\\u{2069}'];
        yield 'a byte order mark' => ["\u{FEFF}key", '\\u{FEFF}key'];
        yield 'printable UTF-8 is left alone' => ['ключ-é-€-😀', 'ключ-é-€-😀'];
        yield 'a soft hyphen and the zero-width characters' => ["a\u{AD}b\u{200B}c\u{200C}d\u{200D}e\u{2060}f\u{2064}", 'a\\u{00AD}b\\u{200B}c\\u{200C}d\\u{200D}e\\u{2060}f\\u{2064}'];
        yield 'the other characters that print as nothing' => ["\u{34F}\u{115F}\u{17B4}\u{180E}\u{206F}\u{3164}\u{FE0F}\u{FFA0}\u{FFF0}", '\\u{034F}\\u{115F}\\u{17B4}\\u{180E}\\u{206F}\\u{3164}\\u{FE0F}\\u{FFA0}\\u{FFF0}'];
        yield 'four-byte characters that print as nothing' => ["v\u{1BCA0}\u{1D173}\u{E0041}\u{E0FFF}", 'v\\u{1BCA0}\\u{1D173}\\u{E0041}\\u{E0FFF}'];
        yield 'the characters next to the escaped ranges are left alone' => ["\u{A0}\u{AC}\u{AE}\u{2027}\u{202F}\u{205F}\u{2070}\u{FEFE}\u{1D172}\u{E1000}", "\u{A0}\u{AC}\u{AE}\u{2027}\u{202F}\u{205F}\u{2070}\u{FEFE}\u{1D172}\u{E1000}"];
        yield 'an invalid byte in hex' => ["a\xFFb", 'a\\xFFb'];
        yield 'a truncated sequence byte by byte' => ["a\xE2\x80", 'a\\xE2\\x80'];
        yield 'an overlong encoding byte by byte' => ["\xC0\xAF", '\\xC0\\xAF'];
        yield 'an encoded surrogate byte by byte' => ["\xED\xA0\x80", '\\xED\\xA0\\x80'];
        yield 'a code point past U+10FFFF byte by byte' => ["\xF4\x90\x80\x80", '\\xF4\\x90\\x80\\x80'];
        yield 'empty' => ['', ''];
    }

    /**
     * Two different texts never look the same once escaped: the escape character is escaped too.
     *
     * @dataProvider distinctPairs
     */
    #[DataProvider('distinctPairs')]
    public function testDistinctTextsAreShownDistinctly(string $one, string $other): void
    {
        self::assertNotSame(TerminalText::escape($one), TerminalText::escape($other));
    }

    /** @return iterable<string, array{string, string}> */
    public static function distinctPairs(): iterable
    {
        yield 'a newline and a backslash-n' => ["a\nb", 'a\\nb'];
        yield 'a tag and an escaped tag' => ['<c', '\\<c'];
        yield 'a byte and its hex escape' => ["\xFF", '\\xFF'];
        yield 'a bidi control and its escape' => ["\u{202E}", '\\u{202E}'];
    }

    public function testATextWithinTheLimitIsShownWhole(): void
    {
        self::assertSame(str_repeat('f', 255), TerminalText::escape(str_repeat('f', 255), 255));
    }

    public function testATextPastTheLimitIsCutWithAnEllipsis(): void
    {
        self::assertSame(str_repeat('f', 255).'…', TerminalText::escape(str_repeat('f', 256), 255));
        self::assertSame(str_repeat('f', 255).'…', TerminalText::escape(str_repeat('f', 100000), 255));
    }

    /** The limit counts the text as shown: an escape takes the room it prints in. */
    public function testTheLimitCountsEscapesAsShown(): void
    {
        self::assertSame(str_repeat('\\n', 5).'…', TerminalText::escape(str_repeat("\n", 20), 11));
    }

    /** An escape or a multi-byte character is shown whole or not at all, never cut in half. */
    public function testTheCutNeverSplitsAnEscapeOrACharacter(): void
    {
        self::assertSame('ab…', TerminalText::escape("ab\x1Bcd", 4));
        self::assertSame('abc…', TerminalText::escape('abc€', 5));
        self::assertSame('abc€', TerminalText::escape('abc€', 6));
    }

    /** The cut lands on the limit exactly: one byte fewer drops the last one, one more goes past it. */
    public function testTheCutKeepsEverythingThatFits(): void
    {
        self::assertSame('abcd…', TerminalText::escape('abcdefgh', 4));
        self::assertSame('ab\\t…', TerminalText::escape("ab\tcd", 4));
        self::assertSame('a\\tbc…', TerminalText::escape("a\tbcdef", 5));
    }

    /** neutralise() is for text people read rather than compare: a Windows path keeps its backslashes. */
    public function testNeutraliseLeavesBackslashesAlone(): void
    {
        self::assertSame('C:\\project\\composer.json \\x1B[31m', TerminalText::neutralise("C:\\project\\composer.json \x1B[31m"));
    }

    public function testNeutraliseEscapesEverythingElseEscapeDoes(): void
    {
        self::assertSame('a\\nb\\u{202E}c\\xFF<d>', TerminalText::neutralise("a\nb\u{202E}c\xFF<d>"));
    }

    /** The runner reads `::` at a line start and `##[` anywhere in a line as a workflow command. */
    public function testALogLineStartsNoWorkflowCommandAndHoldsNoLegacyCommand(): void
    {
        self::assertSame(
            "boom\n\\x3A:error::pwned ##\\x5Bgroup]x\n  \\x3A:warning::y\n#0 a::b() \\x1B",
            TerminalText::neutraliseLogLines("boom\n::error::pwned ##[group]x\n  ::warning::y\n#0 a::b() \x1B"),
        );
    }

    public function testAWarningIsPlainWhenTheOutputIsNotDecorated(): void
    {
        self::assertSame('lockrot: <b>x</b>', TerminalText::warning('lockrot: <b>x</b>', false));
    }

    /** Composer's own `warning` style, black on yellow, around the text exactly as given. */
    public function testAWarningIsBlackOnYellowWhenTheOutputIsDecorated(): void
    {
        self::assertSame("\033[30;43mlockrot: <b>x</b>\033[39;49m", TerminalText::warning('lockrot: <b>x</b>', true));
    }

    public function testAnErrorIsPlainWhenTheOutputIsNotDecorated(): void
    {
        self::assertSame("lockrot: bad\n  - <x>", TerminalText::error("lockrot: bad\n  - <x>", false));
    }

    /** Symfony's `error` style, white on red, line by line so no colour runs into the next line's margin. */
    public function testAnErrorIsWhiteOnRedLineByLineWhenTheOutputIsDecorated(): void
    {
        self::assertSame(
            "\033[37;41mlockrot: bad\033[39;49m\n\033[37;41m  - <x>\033[39;49m",
            TerminalText::error("lockrot: bad\n  - <x>", true)
        );
    }
}
