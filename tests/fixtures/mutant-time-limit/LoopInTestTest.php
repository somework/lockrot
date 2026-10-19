<?php

declare(strict_types=1);

namespace Lockrot\Tests\Fixtures\MutantTimeLimit;

use PHPUnit\Framework\TestCase;

final class LoopInTestTest extends TestCase
{
    public function testLoops(): void
    {
        while (getenv('INFECTION') === '1') {
            usleep(1000);
        }
    }
}
