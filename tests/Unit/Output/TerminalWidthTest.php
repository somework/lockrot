<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Output\FormatContext;
use Lockrot\Output\TerminalWidth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Terminal;

/**
 * Each source is a method of its own: require-dev resolves a console whose Terminal always answers,
 * so detect() never reaches the default from this suite without a COLUMNS that is set but rejected.
 */
final class TerminalWidthTest extends TestCase
{
    public function testColumnsFromTheEnvironmentWins(): void
    {
        self::assertSame(99, TerminalWidth::detect(['COLUMNS' => '99']));
    }

    /** @return iterable<string, array{0: array<string, string>}> */
    public static function ignoredColumnsProvider(): iterable
    {
        yield 'absent' => [[]];
        yield 'empty' => [['COLUMNS' => '']];
        yield 'zero' => [['COLUMNS' => '0']];
        yield 'negative' => [['COLUMNS' => '-80']];
        yield 'not a number' => [['COLUMNS' => 'wide']];
        yield 'fractional' => [['COLUMNS' => '80.5']];
        yield 'padded' => [['COLUMNS' => ' 80']];
    }

    /**
     * @dataProvider ignoredColumnsProvider
     *
     * @param array<string, string> $env
     */
    #[DataProvider('ignoredColumnsProvider')]
    public function testOnlyAPositiveIntegerColumnsIsRead(array $env): void
    {
        self::assertNull(TerminalWidth::fromEnv($env));
    }

    public function testAPositiveColumnsIsRead(): void
    {
        self::assertSame(80, TerminalWidth::fromEnv(['COLUMNS' => '80']));
    }

    public function testWithoutColumnsTheConsoleTerminalDecides(): void
    {
        self::assertSame((new Terminal())->getWidth(), TerminalWidth::fromConsoleTerminal([]));
        self::assertSame(max(FormatContext::MIN_WIDTH, (new Terminal())->getWidth()), TerminalWidth::detect([]));
    }

    /**
     * symfony's Terminal reads COLUMNS itself, and far more leniently than step 1 does:
     * `(int) trim(getenv('COLUMNS'))` turns `abc` into 0. Handing it a value step 1 has already
     * rejected answers 0, and the clamp turns that into the narrowest width lockrot
     * accepts rather than the documented default. So a COLUMNS that is present but unusable takes
     * the whole variable out of play.
     */
    public function testAnUnusableColumnsIsNotHandedToTheConsoleTerminalEither(): void
    {
        self::assertNull(TerminalWidth::fromConsoleTerminal(['COLUMNS' => 'abc']));
        self::assertNull(TerminalWidth::fromConsoleTerminal(['COLUMNS' => '0']));
        self::assertSame(FormatContext::DEFAULT_WIDTH, TerminalWidth::detect(['COLUMNS' => 'abc']));
        self::assertSame(FormatContext::DEFAULT_WIDTH, TerminalWidth::detect(['COLUMNS' => '']));
    }

    public function testTheResultNeverFallsBelowTheMinimum(): void
    {
        self::assertSame(FormatContext::MIN_WIDTH, TerminalWidth::detect(['COLUMNS' => '10']));
    }
}
