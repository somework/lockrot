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
 * Application and default steps unreachable until the plugin runs under Composer 2.2.25's bundled
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
        self::assertSame((new Terminal())->getWidth(), TerminalWidth::fromConsoleTerminal([]));
        self::assertSame(max(FormatContext::MIN_WIDTH, (new Terminal())->getWidth()), TerminalWidth::detect([], null));
    }

    /**
     * symfony's Terminal reads COLUMNS itself, and far more leniently than step 1 does:
     * `(int) trim(getenv('COLUMNS'))` turns `abc` into 0. Handing it a value step 1 has already
     * rejected would answer 0, and the clamp would turn that into the narrowest width lockrot
     * accepts rather than the documented default. So a COLUMNS that is present but unusable takes
     * the whole variable out of play.
     */
    public function testAnUnusableColumnsIsNotHandedToTheConsoleTerminalEither(): void
    {
        self::assertNull(TerminalWidth::fromConsoleTerminal(['COLUMNS' => 'abc']));
        self::assertNull(TerminalWidth::fromConsoleTerminal(['COLUMNS' => '0']));
        self::assertSame(FormatContext::DEFAULT_WIDTH, TerminalWidth::detect(['COLUMNS' => 'abc'], null));
        self::assertSame(FormatContext::DEFAULT_WIDTH, TerminalWidth::detect(['COLUMNS' => ''], null));
    }

    /** With COLUMNS out of play, an older console's own answer is what decides — the 2.8 path. */
    public function testAnUnusableColumnsFallsThroughToTheApplication(): void
    {
        self::assertSame(72, TerminalWidth::detect(['COLUMNS' => '80.5'], $this->applicationReporting(72, 24)));
    }

    public function testAConsoleApplicationWithoutTheOlderDimensionsMethodSaysNothing(): void
    {
        self::assertNull(TerminalWidth::fromApplication(new Application()));
        self::assertNull(TerminalWidth::fromApplication(null));
    }

    /**
     * symfony/console 2.8 has no Terminal class and reports the width through
     * Application::getTerminalDimensions() instead. 5.4 dropped the method, so the only way to
     * exercise the branch on this side is an application that declares it — which is exactly the
     * shape the 2.8 class has.
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

    /** Only the first element is the width; a console that knows it but not the height still answers. */
    public function testAnApplicationThatKnowsTheWidthButNotTheHeightStillAnswers(): void
    {
        self::assertSame(72, TerminalWidth::fromApplication($this->applicationReporting(72, null)));
    }

    /**
     * Where both sources exist the newer one decides. Nothing in this suite runs without a Terminal
     * class, so without this the two steps could be swapped and every test would still pass.
     */
    public function testTheConsoleTerminalOutranksAnOlderApplication(): void
    {
        $detected = TerminalWidth::detect([], $this->applicationReporting(4242, 24));

        self::assertSame(max(FormatContext::MIN_WIDTH, (new Terminal())->getWidth()), $detected);
        self::assertNotSame(4242, $detected);
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
