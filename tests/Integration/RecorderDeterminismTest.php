<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\Contract\BaselinedScenario;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * items C78, R8: two recordings of a baselined scenario, each on a new fixture server, are byte for
 * byte the same, so a re-recording diff shows a change of lockrot and nothing else.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class RecorderDeterminismTest extends TestCase
{
    /**
     * @runInSeparateProcess
     */
    #[RunInSeparateProcess]
    public function testTwoRecordingsOfAScenarioAreByteIdentical(): void
    {
        $first = BaselinedScenario::record('wallabag-2.6', '2026-10-14T00:00:00+00:00');
        $second = BaselinedScenario::record('wallabag-2.6', '2026-10-14T00:00:00+00:00');

        self::assertNotSame([], $first);
        self::assertSame(array_keys($first), array_keys($second));
        self::assertSame($first, $second);
    }
}
