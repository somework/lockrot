<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Php;

use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Exception\ConfigException;
use PHPUnit\Framework\TestCase;

final class PhpReleaseDatesTest extends TestCase
{
    public function testKnownDates(): void
    {
        $dates = PhpReleaseDates::load();
        $ga80 = $dates->gaDate('8.0');
        self::assertNotNull($ga80);
        self::assertSame('2020-11-26', $ga80->format('Y-m-d'));
        $ga84 = $dates->gaDate('8.4');
        self::assertNotNull($ga84);
        self::assertSame('2024-11-21', $ga84->format('Y-m-d'));
        self::assertNull($dates->gaDate('9.9'));
    }

    public function testMinorOf(): void
    {
        self::assertSame('8.4', PhpReleaseDates::minorOf('8.4.25'));
        self::assertSame('8.0', PhpReleaseDates::minorOf('8'));
        self::assertSame('7.4', PhpReleaseDates::minorOf('7.4.0RC1'));
    }

    public function testMinorOfReturnsNonVersionInputUnchanged(): void
    {
        self::assertSame('>=7.4', PhpReleaseDates::minorOf('>=7.4'));
    }

    public function testLoadThrowsConfigExceptionOnMalformedDate(): void
    {
        $path = sys_get_temp_dir().'/lockrot-php-ga-dates-'.uniqid().'.json';
        file_put_contents($path, '{"8.0": "not a date"}');
        try {
            $this->expectException(ConfigException::class);
            $this->expectExceptionMessageMatches('{"8\.0"}');
            PhpReleaseDates::load($path);
        } finally {
            unlink($path);
        }
    }
}
