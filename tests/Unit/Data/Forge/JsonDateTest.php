<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Forge;

use Lockrot\Data\Forge\JsonDate;
use PHPUnit\Framework\TestCase;

final class JsonDateTest extends TestCase
{
    public function testOnlyAParsableStringIsADate(): void
    {
        $date = JsonDate::parse('2015-11-16T16:31:37Z');
        self::assertNotNull($date);
        self::assertSame('2015-11-16T16:31:37+00:00', $date->format(\DATE_ATOM));
        self::assertNull(JsonDate::parse('not a date'));
        self::assertNull(JsonDate::parse(''));
        self::assertNull(JsonDate::parse(null));
        self::assertNull(JsonDate::parse(1447691497));
        self::assertNull(JsonDate::parse(['2015-11-16']));
    }
}
