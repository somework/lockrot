<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Rule;

use Lockrot\Clock;
use Lockrot\Signal\Rule\NoReleaseRule;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\TestCase;

final class NoReleaseRuleTest extends TestCase
{
    private function rule(): NoReleaseRule
    {
        return new NoReleaseRule(Clock::fixed(F::NOW), new Thresholds());
    }

    public function testHighAfterFiveYears(): void
    {
        $signal = $this->rule()->evaluate(F::facts(F::package(), F::metadata([['2.0.8', '2015-11-16T16:30:51+00:00'], ['2.0.7', '2015-04-01']])));
        self::assertNotNull($signal);
        self::assertSame(Signal::S2, $signal->id());
        self::assertSame(Signal::LEVEL_HIGH, $signal->level());
        self::assertSame('last release 2015-11-16 (10.8 years ago)', $signal->summary());
        self::assertSame([
            'last_release' => '2015-11-16T16:30:51+00:00',
            'last_version' => '2.0.8',
            'years' => 10.8,
        ], $signal->data());
    }

    public function testWarnBetweenThreeAndFive(): void
    {
        $signal = $this->rule()->evaluate(F::facts(F::package(), F::metadata([['1.0.0', '2022-05-20']])));
        self::assertNotNull($signal);
        self::assertSame(Signal::LEVEL_WARN, $signal->level());
    }

    public function testRecentReleaseIsNull(): void
    {
        self::assertNull($this->rule()->evaluate(F::facts(F::package(), F::metadata([['1.0.0', '2025-01-01']]))));
    }

    public function testBoundaryExactlyThreeYearsIsWarn(): void
    {
        $signal = $this->rule()->evaluate(F::facts(F::package(), F::metadata([['1.0.0', '2023-09-14T00:00:00+00:00']])));
        self::assertNotNull($signal);
        self::assertSame(Signal::LEVEL_WARN, $signal->level());
    }

    public function testNoMetadataOrNoStableIsNull(): void
    {
        self::assertNull($this->rule()->evaluate(F::facts(F::package())));
        self::assertNull($this->rule()->evaluate(F::facts(F::package(), F::metadata([['dev-master', '2015-01-01']]))));
        self::assertNull($this->rule()->evaluate(F::facts(F::package(), F::metadata([['1.0.0', null]]))));
    }
}
