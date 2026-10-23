<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Gate G-E (tools/corpus/expected-diff.php) passes only when its line rule holds: an allowed line can
 * change or appear, but a dropped one fails the check. Its selftest states the cases.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class ExpectedDiffSelftestTest extends TestCase
{
    public function testTheExpectedDiffCheckPassesItsSelftest(): void
    {
        $process = new Process([\PHP_BINARY, __DIR__.'/../../tools/corpus/expected-diff.php', '--selftest']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }
}
