<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Legacy;

use Lockrot\Legacy\AdvisoryFacts013;
use Lockrot\Legacy\SignalData013;
use Lockrot\Signal\Signal;
use PHPUnit\Framework\TestCase;

/** The report-1 view of a signal's data, which the text formats print. */
final class SignalData013Test extends TestCase
{
    private const ROW013 = ['id' => 'PKSA-1', 'cve' => null, 'title' => 'a title', 'link' => null, 'severity' => 'high', 'reported_at' => null, 'affected_versions' => '<2.0', 'fixed_by' => '2.0.0', 'fixed_on_branch' => true];

    public function testS9sReport2RowsGiveWayToTheReport1Rows(): void
    {
        $signal = new Signal(Signal::S9, Signal::LEVEL_HIGH, '1 advisory', ['advisories' => [['id' => 'PKSA-1', 'fix' => ['kind' => 'update']]], 'releases_read' => true, 'complete' => true]);

        $data = SignalData013::of($signal, new AdvisoryFacts013([self::ROW013], true));

        self::assertSame(['advisories' => [self::ROW013], 'releases_read' => true], $data);
        self::assertSame(Signal::LEVEL_WARN, SignalData013::level($signal));
    }

    public function testS9RowsWithoutAFixAreKept(): void
    {
        $rows = [['id' => 'PKSA-1', 'fixed_by' => null]];
        $signal = new Signal(Signal::S9, Signal::LEVEL_WARN, '1 advisory', ['advisories' => $rows, 'releases_read' => false]);

        self::assertSame(['advisories' => $rows, 'releases_read' => false], SignalData013::of($signal, new AdvisoryFacts013([self::ROW013], true)));
    }

    public function testAnotherSignalKeepsItsOwnAdvisoriesKey(): void
    {
        $data = ['advisories' => [['id' => 'PKSA-1', 'fix' => ['kind' => 'update']]]];
        $signal = new Signal(Signal::S5, Signal::LEVEL_WARN, 'old promise', $data);

        self::assertSame($data, SignalData013::of($signal, new AdvisoryFacts013([self::ROW013], true)));
        self::assertSame(Signal::LEVEL_WARN, SignalData013::level(new Signal(Signal::S5, Signal::LEVEL_WARN, 'old promise')));
    }
}
