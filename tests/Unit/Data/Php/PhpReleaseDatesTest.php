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
     * instead would move every PHP-version age by up to a day depending on where the run happens.
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
