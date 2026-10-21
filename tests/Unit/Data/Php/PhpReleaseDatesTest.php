<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Php;

use Lockrot\Clock;
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

    public function testMinorsAreTheMinorsReleasedOnOrBeforeTheClocksDayOldestFirst(): void
    {
        $dates = PhpReleaseDates::load();

        self::assertSame(['8.3', '8.4'], \array_slice($dates->minors(Clock::fixed('2024-11-21T00:00:00+00:00')), -2));
        self::assertSame(['8.2', '8.3'], \array_slice($dates->minors(Clock::fixed('2024-11-20T23:59:59+00:00')), -2));
        self::assertSame('8.4', \array_slice($dates->minors(Clock::fixed('2024-11-21T01:00:00+08:00')), -1)[0]);
        self::assertSame('5.3', $dates->minors(Clock::fixed('2026-10-01T00:00:00+00:00'))[0]);
    }

    public function testMinorsSortsByVersionNotByFileOrder(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lockrot-php-ga-dates-');
        self::assertIsString($path);
        try {
            file_put_contents($path, '{"8.10": "2030-01-01", "8.9": "2029-01-01", "7.4": "2019-11-28"}');

            self::assertSame(['7.4', '8.9', '8.10'], PhpReleaseDates::load($path)->minors(Clock::fixed('2031-01-01T00:00:00+00:00')));
        } finally {
            unlink($path);
        }
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
        $path = tempnam(sys_get_temp_dir(), 'lockrot-php-ga-dates-');
        self::assertIsString($path);
        file_put_contents($path, '{"8.0": "not a date"}');

        $thrown = null;
        try {
            PhpReleaseDates::load($path);
        } catch (ConfigException $e) {
            $thrown = $e;
        } finally {
            unlink($path);
        }

        self::assertInstanceOf(ConfigException::class, $thrown);
        self::assertSame('Invalid PHP GA date for "8.0" in '.$path, $thrown->getMessage(), 'the minor and the file are both named');
        self::assertInstanceOf(\Exception::class, $thrown->getPrevious(), 'the parse failure is kept as the cause');
        self::assertSame(0, $thrown->getCode(), 'lockrot carries no exception codes; a run exits on its verdict, not on these');
    }

    /**
     * A GA date is a calendar day read as midnight UTC. Reading it in the machine's own timezone
     * instead moves every PHP-version age by up to a day, depending on where the run happens.
     */
    public function testGaDatesAreReadAsUtcWhateverTheMachineTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');

        try {
            $ga80 = PhpReleaseDates::load()->gaDate('8.0');
            self::assertNotNull($ga80);
            self::assertSame('2020-11-26T00:00:00+00:00', $ga80->format(\DATE_ATOM));
        } finally {
            date_default_timezone_set($previous);
        }
    }
}
