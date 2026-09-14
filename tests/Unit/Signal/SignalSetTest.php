<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal;

use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;
use Lockrot\Signal\SignalSet;
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
}
