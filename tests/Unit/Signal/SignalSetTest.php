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
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\SignalDataStub;
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
            $rule(new Signal(Signal::LEVEL_WARN, 'six', SignalDataStub::of(Signal::S6, []))),
            $rule(new Signal(Signal::LEVEL_HIGH, 'three', SignalDataStub::of(Signal::S3, []))),
            $rule(null),
            $rule(new Signal(Signal::LEVEL_HIGH, 'one', SignalDataStub::of(Signal::S1, []))),
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

    /** R reads composer.json's `require.php`, else the lock's `platform.php`, on the known minors of the run clock. */
    public function testTheDefaultSetReadsTheNewestPhpFromTheRootPhp(): void
    {
        $clock = Clock::fixed('2026-10-01T00:00:00+00:00');
        $dates = PhpReleaseDates::load();

        $known = SignalSet::default($clock, new Thresholds(), '8.4', $dates)->phpCap();
        $required = SignalSet::default($clock, new Thresholds(), '8.4', $dates, '~8.2.0 || ~8.3.0', '~8.1.0')->phpCap();
        $platform = SignalSet::default($clock, new Thresholds(), '8.4', $dates, null, '~8.1.0')->phpCap();
        $before = SignalSet::default(Clock::fixed('2025-11-19T00:00:00+00:00'), new Thresholds(), '8.4', $dates)->phpCap();

        self::assertSame(['8.5', 'known'], [$known->newest(), $known->source()]);
        self::assertSame(['8.3', 'require_php'], [$required->newest(), $required->source()]);
        self::assertSame(['8.1', 'platform_php'], [$platform->newest(), $platform->source()]);
        self::assertSame('8.4', $before->newest(), 'PHP 8.5 reached its GA on 2025-11-20');
    }

    /** `~8.4.6` excludes the solve PHP 8.4.5 and admits 8.4.99, the target minor's newest patch. */
    public function testASetSolvedAtAPatchTestsTheTargetOfS11AtThatPatch(): void
    {
        $set = SignalSet::default(Clock::fixed('2026-10-01T00:00:00+00:00'), new Thresholds(), '8.4', PhpReleaseDates::load());
        $facts = FactsBuilder::facts(FactsBuilder::package(['version' => '1.0.0', 'php' => '~8.4.6']));

        $pinned = self::cappedData($set->solvedAt('8.4.5')->evaluate($facts));
        $newest = self::cappedData($set->evaluate($facts));

        self::assertSame([true, false], [$pinned['excludes_target'] ?? null, $set->solvedAt('8.4.5')->phpFloor()->admitsTarget('~8.4.6')]);
        self::assertFalse($newest['excludes_target'] ?? null);
    }

    public function testASetSolvedAtAPatchKeepsItsRulesWithoutTheDefaultSet(): void
    {
        $rule = new class () implements SignalRule {
            public function evaluate(PackageFacts $facts): Signal
            {
                return new Signal(Signal::LEVEL_WARN, 'six', SignalDataStub::of(Signal::S6, []));
            }
        };
        $set = new SignalSet([$rule], new PhpFloor('8.4'));

        $solved = $set->solvedAt('8.4.5');

        self::assertFalse($solved->phpFloor()->admitsTarget('~8.4.6'));
        self::assertSame([Signal::S6], array_map(static fn (Signal $s): string => $s->id(), $solved->evaluate(FactsBuilder::facts(FactsBuilder::package()))));
    }

    /**
     * @param list<Signal> $signals
     *
     * @return array<mixed, mixed>
     */
    private static function cappedData(array $signals): array
    {
        $capped = array_values(array_filter($signals, static fn (Signal $signal): bool => $signal->id() === Signal::S11));
        self::assertCount(1, $capped);

        return JsonPath::decoded($capped[0]->data());
    }

    public function testASetBuiltWithoutAFloorHasNoNewestPhp(): void
    {
        self::assertNull((new SignalSet([]))->phpCap()->newest());
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
