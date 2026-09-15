<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Output\FormatContext;
use Lockrot\Output\TerminalWidth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Terminal;

/**
 * The four sources are exposed as named methods rather than hidden inside detect(), because two of
 * them cannot be reached through detect() from this test suite: symfony/console 5.4 is what
 * require-dev resolves, so Terminal always exists here and always answers, which makes the
 * Application and default steps dead ends until the plugin runs under Composer 2.2.25's bundled
 * 2.8. Testing each source directly is what keeps them covered on this side.
 */
final class TerminalWidthTest extends TestCase
{
    public function testColumnsFromTheEnvironmentWins(): void
    {
        self::assertSame(99, TerminalWidth::detect(['COLUMNS' => '99'], null));
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
        self::assertSame((new Terminal())->getWidth(), TerminalWidth::fromConsoleTerminal());
        self::assertSame(max(FormatContext::MIN_WIDTH, (new Terminal())->getWidth()), TerminalWidth::detect([], null));
    }

    public function testAConsoleApplicationWithoutTheOlderDimensionsMethodSaysNothing(): void
    {
        self::assertNull(TerminalWidth::fromApplication(new Application()));
        self::assertNull(TerminalWidth::fromApplication(null));
    }

    /**
     * symfony/console 2.8 has no Terminal class and reports the width through
     * Application::getTerminalDimensions() instead (2.8.52 Application.php:736). 5.4 dropped the
     * method, so the only way to exercise the branch on this side is an application that declares
     * it — which is exactly the shape the 2.8 class has.
     */
    public function testAnApplicationThatStillReportsDimensionsSuppliesTheWidth(): void
    {
        self::assertSame(72, TerminalWidth::fromApplication($this->applicationReporting(72, 24)));
    }

    public function testAnApplicationThatCannotTellTheWidthSaysNothing(): void
    {
        self::assertNull(TerminalWidth::fromApplication($this->applicationReporting(null, null)));
        self::assertNull(TerminalWidth::fromApplication($this->applicationReporting(0, 24)));
    }

    public function testTheResultNeverFallsBelowTheMinimum(): void
    {
        self::assertSame(FormatContext::MIN_WIDTH, TerminalWidth::detect(['COLUMNS' => '10'], null));
    }

    private function applicationReporting(?int $width, ?int $height): Application
    {
        return new class ($width, $height) extends Application {
            private ?int $width;
            private ?int $height;

            public function __construct(?int $width, ?int $height)
            {
                parent::__construct();
                $this->width = $width;
                $this->height = $height;
            }

            /** @return array{0: int|null, 1: int|null} */
            public function getTerminalDimensions(): array
            {
                return [$this->width, $this->height];
            }
        };
    }
}
