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
        // fromArray() must be as strict as the schema for a caller that bypasses the schema.
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('extra.lockrot.release-warn-years must be an integer');
        Thresholds::fromArray(['release-warn-years' => '4']);
    }

    public function testTheArrayHoldsEachThresholdUnderItsKey(): void
    {
        self::assertSame(['release-warn-years' => 2, 'release-high-years' => 6, 'push-warn-years' => 3, 'push-high-years' => 7], (new Thresholds(2, 6, 3, 7))->toArray());
    }

    /** A high threshold below its warn threshold would make a release `high` before it is `warn`. */
    public function testAReleaseHighBelowItsWarnIsRefusedWithBothKeys(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('extra.lockrot.release-high-years (2) must not be below extra.lockrot.release-warn-years (5)');
        Thresholds::fromArray(['release-warn-years' => 5, 'release-high-years' => 2]);
    }

    public function testAPushHighBelowItsWarnIsRefusedWithBothKeys(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('extra.lockrot.push-high-years (3) must not be below extra.lockrot.push-warn-years (4)');
        Thresholds::fromArray(['push-warn-years' => 4, 'push-high-years' => 3]);
    }

    public function testAWarnAboveTheDefaultHighIsRefused(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('extra.lockrot.release-high-years (5) must not be below extra.lockrot.release-warn-years (6)');
        Thresholds::fromArray(['release-warn-years' => 6]);
    }

    public function testAHighEqualToItsWarnIsAccepted(): void
    {
        $thresholds = Thresholds::fromArray(['release-warn-years' => 4, 'release-high-years' => 4, 'push-warn-years' => 2, 'push-high-years' => 2]);

        self::assertSame(Signal::LEVEL_HIGH, Thresholds::levelFor(4.0, $thresholds->releaseWarnYears(), $thresholds->releaseHighYears()));
        self::assertSame(2, $thresholds->pushHighYears());
    }
}
