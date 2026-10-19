<?php

declare(strict_types=1);

namespace Lockrot\Tests\Fixtures\MutantTimeLimit;

use PHPUnit\Framework\TestCase;

final class QuickTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        usleep(600000);
    }

    public function testEndsBeforeTheLimit(): void
    {
        usleep(600000);
        $this->addToAssertionCount(1);
    }
}
