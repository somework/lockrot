<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal;

use Lockrot\Exception\ConfigException;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use PHPUnit\Framework\TestCase;

final class ThresholdsTest extends TestCase
{
    public function testFromArrayDefaults(): void
    {
        $thresholds = Thresholds::fromArray([]);
        self::assertSame(3, $thresholds->releaseWarnYears());
        self::assertSame(5, $thresholds->releaseHighYears());
        self::assertSame(3, $thresholds->pushWarnYears());
        self::assertSame(5, $thresholds->pushHighYears());
    }

    public function testConstructorDefaults(): void
    {
        $thresholds = new Thresholds();
        self::assertSame(3, $thresholds->releaseWarnYears());
        self::assertSame(5, $thresholds->releaseHighYears());
        self::assertSame(3, $thresholds->pushWarnYears());
        self::assertSame(5, $thresholds->pushHighYears());
    }

    public function testConstructorThrowsOnNonPositiveYears(): void
    {
        $this->expectException(ConfigException::class);
        new Thresholds(0);
    }

    public function testLevelForBoundaries(): void
    {
        self::assertNull(Thresholds::levelFor(2.9, 3, 5));
        self::assertSame(Signal::LEVEL_WARN, Thresholds::levelFor(3.0, 3, 5));
        self::assertSame(Signal::LEVEL_WARN, Thresholds::levelFor(4.9, 3, 5));
        self::assertSame(Signal::LEVEL_HIGH, Thresholds::levelFor(5.0, 3, 5));
        self::assertSame(Signal::LEVEL_HIGH, Thresholds::levelFor(10.0, 3, 5));
    }

    public function testFromArrayCustomValues(): void
    {
        $thresholds = Thresholds::fromArray([
            'release-warn-years' => 1,
            'release-high-years' => 2,
            'push-warn-years' => 4,
            'push-high-years' => 6,
        ]);
        self::assertSame(1, $thresholds->releaseWarnYears());
        self::assertSame(2, $thresholds->releaseHighYears());
        self::assertSame(4, $thresholds->pushWarnYears());
        self::assertSame(6, $thresholds->pushHighYears());
    }

    public function testFromArrayNonIntThrows(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('extra.lockrot.release-warn-years must be an integer');
        Thresholds::fromArray(['release-warn-years' => 'not-a-number']);
    }

    public function testFromArrayDigitStringThrows(): void
    {
        // The schema is the only layer allowed to accept/reject the shape of extra.lockrot values;
        // fromArray() must not resurrect the old ctype_digit leniency for a caller that bypasses it.
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('extra.lockrot.release-warn-years must be an integer');
        Thresholds::fromArray(['release-warn-years' => '4']);
    }
}
