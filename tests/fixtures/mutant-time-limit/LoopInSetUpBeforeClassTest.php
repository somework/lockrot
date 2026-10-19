<?php

declare(strict_types=1);

namespace Lockrot\Tests\Fixtures\MutantTimeLimit;

use PHPUnit\Framework\TestCase;

final class LoopInSetUpBeforeClassTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        while (getenv('INFECTION') === '1') {
            usleep(1000);
        }
    }

    public function testNeverRuns(): void
    {
        $this->addToAssertionCount(1);
    }
}
