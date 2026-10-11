<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Legacy;

use Lockrot\Legacy\SignalData013;
use Lockrot\Signal\Data\AbandonedData;
use Lockrot\Signal\Data\ExposedPackage;
use Lockrot\Signal\Data\ExposureData;
use Lockrot\Signal\Data\NoReleaseData;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\SignalDataStub;
use PHPUnit\Framework\TestCase;

/** The report-1 view of a signal's data, which the text formats print. */
final class SignalData013Test extends TestCase
{
    public function testTheKeysThatReport2AddsAreLeftOut(): void
    {
        $signal = new Signal(Signal::LEVEL_HIGH, 'marked abandoned', new AbandonedData('lock', 'acme/next', 'https://packagist.org/packages/acme/next'));

        self::assertSame(['replacement' => 'acme/next'], SignalData013::toReport1Array($signal));
    }

    public function testS7NamesEachPackageByItsReport1Word(): void
    {
        $signal = new Signal(Signal::LEVEL_INFO, 'pulls in 2 flagged packages', new ExposureData([
            new ExposedPackage('acme/old', ['acme/app', 'acme/old'], 'low', 'stale', ['stale']),
            new ExposedPackage('acme/unsafe', ['acme/app', 'acme/unsafe'], 'high', null, ['vulnerable']),
        ]));

        self::assertSame(['flagged' => 2, 'packages' => [
            ['package' => 'acme/old', 'verdict' => 'stale', 'chain' => ['acme/app', 'acme/old']],
            ['package' => 'acme/unsafe', 'verdict' => 'vulnerable', 'chain' => ['acme/app', 'acme/unsafe']],
        ]], SignalData013::toReport1Array($signal));
    }

    public function testASignalThatReport2AddsNoKeyToKeepsAll(): void
    {
        $signal = new Signal(Signal::LEVEL_WARN, 'last release 2021-05-05', new NoReleaseData('2021-05-05T00:00:00+00:00', '1.0.0', 5.0, null));

        self::assertSame(['last_release' => '2021-05-05T00:00:00+00:00', 'last_version' => '1.0.0', 'years' => 5.0, 'dated_by' => null], SignalData013::toReport1Array($signal));
    }

    public function testReport1sS9LevelIsWarnWhateverTheSeverity(): void
    {
        self::assertSame(Signal::LEVEL_WARN, SignalData013::level(new Signal(Signal::LEVEL_HIGH, '1 advisory', SignalDataStub::of(Signal::S9, []))));
        self::assertSame(Signal::LEVEL_HIGH, SignalData013::level(new Signal(Signal::LEVEL_HIGH, 'marked abandoned', SignalDataStub::of(Signal::S1, []))));
    }
}
