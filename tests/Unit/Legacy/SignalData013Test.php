<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Legacy;

use Lockrot\Legacy\SignalData013;
use Lockrot\Signal\Data\AbandonedData;
use Lockrot\Signal\Data\ExposedPackage;
use Lockrot\Signal\Data\ExposureData;
use Lockrot\Signal\Signal;
use PHPUnit\Framework\TestCase;

/** The report-1 view of a signal's data, which the text formats print. */
final class SignalData013Test extends TestCase
{
    public function testTheKeysThatReport2AddsAreLeftOut(): void
    {
        $signal = new Signal(Signal::S1, Signal::LEVEL_HIGH, 'marked abandoned', new AbandonedData('lock', 'acme/next', 'https://packagist.org/packages/acme/next'));

        self::assertSame(['replacement' => 'acme/next'], SignalData013::of($signal));
    }

    public function testS7NamesEachPackageByItsReport1Word(): void
    {
        $signal = new Signal(Signal::S7, Signal::LEVEL_INFO, 'pulls in 2 flagged packages', new ExposureData([
            new ExposedPackage('acme/old', ['acme/app', 'acme/old'], 'low', 'stale', ['stale']),
            new ExposedPackage('acme/unsafe', ['acme/app', 'acme/unsafe'], 'high', null, ['vulnerable']),
        ]));

        self::assertSame(['flagged' => 2, 'packages' => [
            ['package' => 'acme/old', 'verdict' => 'stale', 'chain' => ['acme/app', 'acme/old']],
            ['package' => 'acme/unsafe', 'verdict' => 'vulnerable', 'chain' => ['acme/app', 'acme/unsafe']],
        ]], SignalData013::of($signal));
    }

    public function testASignalWithoutDataHasNone(): void
    {
        self::assertSame([], SignalData013::of(new Signal(Signal::S5, Signal::LEVEL_WARN, 'old promise')));
    }

    public function testReport1sS9LevelIsWarnWhateverTheSeverity(): void
    {
        self::assertSame(Signal::LEVEL_WARN, SignalData013::level(new Signal(Signal::S9, Signal::LEVEL_HIGH, '1 advisory')));
        self::assertSame(Signal::LEVEL_HIGH, SignalData013::level(new Signal(Signal::S1, Signal::LEVEL_HIGH, 'marked abandoned')));
    }
}
