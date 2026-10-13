<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Output\SarifRecords;
use Lockrot\Score\CountedAdvisory;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\BaselineEntries;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class SarifRecordsTest extends TestCase
{
    /**
     * One package with the counted advisories $now, as id => [severity, fix kind], against an entry
     * that records $recorded the same way, for the run's target PHP.
     *
     * @param array<string, array{string, string}> $now
     * @param array<string, array{string, string}> $recorded
     */
    private static function records(array $now, array $recorded): SarifRecords
    {
        $s9 = new Signal('high', 'advisories', SignalDataStub::of('S9', []));
        $counted = [];
        foreach ($now as $id => [$severity, $fixKind]) {
            $counted[] = new CountedAdvisory($id, $severity, $fixKind);
        }
        $finding = (new FindingBuilder())->withPackage('a/b')->withVersion('1.0.0')->withSignals([$s9])
            ->withFlags(FlagSet::fromSignals([$s9], null, $counted))
            ->build();
        $report = (new Report([$finding], [], new \DateTimeImmutable('2026-10-01T00:00:00+00:00'), 1, 0))
            ->withRun(new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, null, RunSettings::SOURCE_OPTION, null));
        $rows = [];
        foreach ($recorded as $id => [$severity, $fixKind]) {
            $rows[] = [$id, $severity, $fixKind];
        }
        $entry = BaselineEntries::of('a/b', '1.0.0', Verdict::STALE, '2026-06-01', $rows);

        return new SarifRecords($finding, null, BaselineComparison::compare(Baseline::of([$entry], '2026-09-14T00:00:00+00:00', '8.4', RunSettings::SOURCE_OPTION), $report, 'lockrot-baseline.json', ['a/b']));
    }

    public function testTheAdvisoriesAreKnownOnlyWhenNoneIsNewReRatedOrWithALostFix(): void
    {
        $known = ['PKSA-1' => ['high', 'update'], 'PKSA-2' => ['medium', 'update']];

        self::assertTrue(self::records($known, $known)->advisoriesKnown());
        self::assertFalse(self::records($known + ['PKSA-3' => ['low', 'update']], $known)->advisoriesKnown(), 'one new');
        self::assertFalse(self::records(['PKSA-1' => ['high', 'update'], 'PKSA-3' => ['low', 'update']], ['PKSA-1' => ['medium', 'update']])->advisoriesKnown(), 'one new and one re-rated');
        self::assertFalse(self::records(['PKSA-1' => ['high', 'update'], 'PKSA-2' => ['medium', 'none']], ['PKSA-1' => ['medium', 'update'], 'PKSA-2' => ['medium', 'update']])->advisoriesKnown(), 'one re-rated and one with a lost fix');
    }
}
