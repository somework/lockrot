<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Config;

use Lockrot\Config\UnknownKeyWarnings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

final class UnknownKeyWarningsTest extends TestCase
{
    public function testEachWarningIsOneLockrotWarningLine(): void
    {
        self::assertSame(
            [
                '<warning>lockrot: unknown key extra.lockrot.install-tme ignored (did you mean install-time?)</warning>',
                '<warning>lockrot: unknown key extra.lockrot.slack-webhook ignored</warning>',
            ],
            (new UnknownKeyWarnings())->lines(['install-tme' => 'off', 'slack-webhook' => 'https://example.com'])
        );
    }

    public function testNothingUnknownIsNoLines(): void
    {
        self::assertSame([], (new UnknownKeyWarnings())->lines(['fail-on' => 'none', 'x-ci' => true]));
    }

    /** A key is the project's text, not markup: `<error>` in it must print as written, not restyle the line. */
    public function testAKeyIsEscapedForTheConsoleFormatter(): void
    {
        $lines = (new UnknownKeyWarnings())->lines(['<error>a</error>' => 1]);

        self::assertSame(['<warning>lockrot: unknown key extra.lockrot.'.OutputFormatter::escape('<error>a</error>').' ignored</warning>'], $lines);
        $formatter = new OutputFormatter(false, ['warning' => new OutputFormatterStyle('black', 'yellow')]);
        self::assertSame('lockrot: unknown key extra.lockrot.<error>a</error> ignored', $formatter->format($lines[0]));
    }

    /**
     * `composer lockrot` reads composer.json twice, and one process could run the install-time
     * summary and the command both: a warning is printed once however often it is computed.
     */
    public function testAWarningAlreadyPrintedIsNotPrintedAgain(): void
    {
        $warnings = new UnknownKeyWarnings();

        self::assertCount(1, $warnings->lines(['install-tme' => 'off']));
        self::assertSame([], $warnings->lines(['install-tme' => 'off']));
        self::assertSame(
            ['<warning>lockrot: unknown key extra.lockrot.slack-webhook ignored</warning>'],
            $warnings->lines(['install-tme' => 'off', 'slack-webhook' => 'https://example.com'])
        );
    }

    public function testTwoGuardsDoNotShareWhatTheyPrinted(): void
    {
        (new UnknownKeyWarnings())->lines(['install-tme' => 'off']);

        self::assertCount(1, (new UnknownKeyWarnings())->lines(['install-tme' => 'off']));
    }

    public function testTheProcessGuardIsOneInstance(): void
    {
        self::assertSame(UnknownKeyWarnings::process(), UnknownKeyWarnings::process());
    }
}
