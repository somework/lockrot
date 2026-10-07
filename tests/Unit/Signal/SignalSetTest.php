<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal;

use Lockrot\Clock;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use PHPUnit\Framework\TestCase;

final class SignalSetTest extends TestCase
{
    public function testEvaluateSortsSignalsById(): void
    {
        $rule = static function (?Signal $signal): SignalRule {
            return new class ($signal) implements SignalRule {
                private ?Signal $signal;

                public function __construct(?Signal $signal)
                {
                    $this->signal = $signal;
                }

                public function evaluate(PackageFacts $facts): ?Signal
                {
                    return $this->signal;
                }
            };
        };

        $set = new SignalSet([
            $rule(new Signal(Signal::S6, Signal::LEVEL_WARN, 'six')),
            $rule(new Signal(Signal::S3, Signal::LEVEL_HIGH, 'three')),
            $rule(null),
            $rule(new Signal(Signal::S1, Signal::LEVEL_HIGH, 'one')),
        ]);
        $signals = $set->evaluate(FactsBuilder::facts(FactsBuilder::package()));

        self::assertSame([Signal::S1, Signal::S3, Signal::S6], array_map(static fn (Signal $s): string => $s->id(), $signals));
    }

    public function testTheDefaultSetKeepsTheTargetAndTheProjectPhpForTheFixKinds(): void
    {
        $floor = SignalSet::default(Clock::fixed('2026-10-01T00:00:00+00:00'), new Thresholds(), '8.4', PhpReleaseDates::load(), '>=8.2')->phpFloor();

        self::assertSame('8.2.0', $floor->lowestAsString());
        self::assertFalse($floor->admitsTarget('<8.4'));
    }

    public function testASetBuiltWithoutAFloorHasNoTargetAndNoProjectPhp(): void
    {
        $floor = (new SignalSet([]))->phpFloor();

        self::assertNull($floor->lowestAsString());
        self::assertNull($floor->admitsTarget('<8.4'));
    }

    public function testASetKeepsTheFloorItWasGiven(): void
    {
        $floor = new PhpFloor('8.4');

        self::assertSame($floor, (new SignalSet([], $floor))->phpFloor());
    }
}
