<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Output\ConsoleMarkup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

final class ConsoleMarkupTest extends TestCase
{
    /**
     * Text lockrot did not write, escaped and rendered, is the text again — coloured or not, and
     * whatever it looks like: the style and link tags symfony/console 5.4's own escape() leaves
     * half-live, a backslash before a bracket, a backslash at the very end, and a run of `<b` long
     * enough to exhaust PCRE's JIT had a regular expression been asked to read it.
     *
     * @dataProvider textsThatLookLikeMarkup
     */
    #[DataProvider('textsThatLookLikeMarkup')]
    public function testEscapedTextRendersAsWritten(string $text): void
    {
        $escaped = ConsoleMarkup::escape($text);

        self::assertSame($text, ConsoleMarkup::render($escaped, false));
        self::assertSame($text, ConsoleMarkup::render($escaped, true));
    }

    /** @return iterable<string, array{string}> */
    public static function textsThatLookLikeMarkup(): iterable
    {
        yield 'a style tag' => ['<<fg=red>>'];
        yield 'a link tag' => ['<<href=x>>'];
        yield 'an escaped tag' => ['a\\<b'];
        yield 'an escaped closing bracket' => ['a\\>b'];
        yield 'two backslashes' => ['a\\\\b'];
        yield 'a backslash alone' => ['C:\\lockrot'];
        yield 'a trailing backslash' => ['vendor/pkg\\'];
        yield 'one of lockrot\'s own tags' => ['<fg=red>x</fg=red>'];
        yield 'a closing shorthand' => ['</>'];
        yield 'a long run of <b' => [str_repeat('<b', 100000)];
        yield 'nothing' => [''];
    }

    /**
     * A backslash at the end of the escaped text is not taken as escaping the tag after it, so the
     * style still closes where lockrot closed it and the text that follows is not coloured.
     */
    public function testATrailingBackslashDoesNotSwallowTheClosingTag(): void
    {
        $markup = '<fg=red>'.ConsoleMarkup::escape('a\\').'</fg=red>'.ConsoleMarkup::escape('<b');

        self::assertSame("\033[31ma\\\033[39m<b", ConsoleMarkup::render($markup, true));
        self::assertSame('a\\<b', ConsoleMarkup::render($markup, false));
    }

    /** The style closes after the long run: the line after it is printed without any colour. */
    public function testAStyleAroundALongRunEndsWithIt(): void
    {
        $run = str_repeat('<b', 100000);
        $rendered = ConsoleMarkup::render('<fg=red>'.ConsoleMarkup::escape($run)."</fg=red>\nnext line", true);

        self::assertSame("\033[31m".$run."\033[39m\nnext line", $rendered);
    }

    /**
     * Every style lockrot writes renders exactly as Symfony's formatter rendered it before — with
     * Composer's `warning` style added, as Composer adds it — decorated and not, nested and closed
     * with `</>`. The escapes of text without a backslash are the ones symfony/console 5.4 wrote.
     *
     * @dataProvider lockrotMarkup
     */
    #[DataProvider('lockrotMarkup')]
    public function testLockrotsStylesRenderAsSymfonysFormatterRenderedThem(string $markup): void
    {
        foreach ([true, false] as $decorated) {
            $symfony = new OutputFormatter($decorated, ['warning' => new OutputFormatterStyle('black', 'yellow')]);

            self::assertSame($symfony->format($markup), ConsoleMarkup::render($markup, $decorated));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function lockrotMarkup(): iterable
    {
        yield 'a red label' => ["  <fg=red>abandoned</fg=red>    vendor/a 1.0.0\n"];
        yield 'a yellow label' => ['  <fg=yellow>stale</fg=yellow>  vendor/b'];
        yield 'a bold header' => ["<options=bold>critical (1)</options=bold>\n"];
        yield 'bold closed by the shorthand' => ['<options=bold>vendor/pkg 1.5.0</> — <fg=yellow>left-behind</fg=yellow>, priority high'];
        yield 'composer\'s warning' => ['<warning>lockrot: dependency rot in 1 of 1 changed package</warning>'];
        yield 'symfony\'s comment' => ['  <comment>abandoned   </comment>vendor/b 2.0.0'];
        yield 'nested' => ['<options=bold>a<fg=red>b</fg=red>c</options=bold>d'];
        yield 'nested three deep' => ['<options=bold>a<fg=red>b<fg=yellow>c</fg=yellow>d</fg=red>e</options=bold>f'];
        yield 'escaped brackets' => ['php '.OutputFormatter::escape('>=7.1 <8.0').' and '.ConsoleMarkup::escape('>=7.1 <8.0')];
        yield 'no markup at all' => ['plain text'];
        yield 'an empty style' => ['<fg=red></fg=red>after'];
    }

    public function testTheEscapeOfTextWithoutABackslashIsSymfonysOwn(): void
    {
        self::assertSame(OutputFormatter::escape('vendor/<info>weird >=7.1 <8.0'), ConsoleMarkup::escape('vendor/<info>weird >=7.1 <8.0'));
    }

    /**
     * The markup is lockrot's own, so a tag it does not know, a closing tag nothing opened, one that
     * closes another style than the open one, or a tag left without its `>` is a bug in lockrot:
     * rendering it any way at all would hide that.
     *
     * @dataProvider markupLockrotNeverWrites
     */
    #[DataProvider('markupLockrotNeverWrites')]
    public function testMarkupLockrotNeverWritesIsRefused(string $markup, string $message): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);

        ConsoleMarkup::render($markup, false);
    }

    /** @return iterable<string, array{string, string}> */
    public static function markupLockrotNeverWrites(): iterable
    {
        yield 'an unknown style' => ['<info>x</info>', 'unknown console style <info>'];
        yield 'a closing tag with nothing open' => ['x</>', 'closing tag </> with no style open'];
        yield 'a closing tag for another style' => ['<fg=red>x</fg=yellow>', 'closing tag </fg=yellow> does not close <fg=red>'];
        yield 'an unclosed bracket' => ['<fg=red', 'unterminated console tag'];
        yield 'a backslash at the end' => ['a\\', 'a backslash ends'];
    }
}
