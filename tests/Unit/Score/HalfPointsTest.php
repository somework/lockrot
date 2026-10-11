<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Score;

use Lockrot\Score\HalfPoints;
use PHPUnit\Framework\TestCase;

final class HalfPointsTest extends TestCase
{
    public function testAWholeNumberIsAnIntAndAHalfIsAFloat(): void
    {
        self::assertSame(0, HalfPoints::json(0));
        self::assertSame(9, HalfPoints::json(18));
        self::assertSame(4.5, HalfPoints::json(9));
        self::assertSame(0.5, HalfPoints::json(1));
    }

    public function testTheTextOfAHalfEndsInPointFive(): void
    {
        self::assertSame('0', HalfPoints::text(0));
        self::assertSame('9', HalfPoints::text(18));
        self::assertSame('4.5', HalfPoints::text(9));
        self::assertSame('0.5', HalfPoints::text(1));
    }
}
