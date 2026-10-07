<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Legacy\Priority013;
use Lockrot\Legacy\PriorityBasis013;
use Lockrot\Legacy\Verdict013;
use Lockrot\Tests\Support\CorpusFloor;
use PHPUnit\Framework\TestCase;

/**
 * The flags and the score over the corpus floor, against what report-1 recorded. No watched project
 * configures Composer's abandoned ignore list, so every S1 is in the floor.
 */
final class CorpusFloorTest extends TestCase
{
    public function testTheFloorHoldsReport1DocumentsOfOneRelease(): void
    {
        $reports = CorpusFloor::reports();

        self::assertNotSame([], $reports);
        self::assertSame(['0.13.0'], array_values(array_unique(array_column($reports, 'lockrot'))));
    }

    public function testTheProvenanceNamesThisFloor(): void
    {
        $provenance = json_decode((string) file_get_contents(CorpusFloor::PROVENANCE), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($provenance);
        $reports = CorpusFloor::reports();

        self::assertSame(hash_file('sha256', CorpusFloor::PATH), $provenance['sha256']);
        self::assertSame(\count($reports), $provenance['reports']);
        self::assertSame(array_sum(array_map(static fn (array $report): int => \count($report['findings']), $reports)), $provenance['findings']);
    }

    /** Report-1 gives a priority to a flagged finding only, so the recorded priority selects them. */
    public function testTheLeadOfEveryFlaggedFindingIsTheReport1VerdictThatTheFloorRecorded(): void
    {
        $flagged = 0;
        foreach (CorpusFloor::reports() as $report) {
            foreach ($report['findings'] as $recorded) {
                if ($recorded['priority'] === Priority013::NONE) {
                    self::assertFalse(Verdict013::flagged($recorded['verdict']), $report['name'].' '.$recorded['package']);
                    continue;
                }
                ++$flagged;
                $finding = CorpusFloor::finding($recorded, CorpusFloor::advisories($recorded));
                self::assertSame($recorded['verdict'], $finding->verdict(), $report['name'].' '.$recorded['package'].': the first-match engine');
                self::assertSame($recorded['verdict'], $finding->lead(), $report['name'].' '.$recorded['package']);
            }
        }
        self::assertGreaterThan(0, $flagged);
    }

    /**
     * Without advisories, the grade of a flagged finding is report-1's priority before the raise for
     * an advisory that no release on the installed branch fixes. Where report-1 did not raise, it is
     * the recorded priority.
     */
    public function testTheRotOnlyGradeOfEveryFlaggedFindingIsTheRecordedPriorityBeforeTheAdvisoryRaise(): void
    {
        $raised = 0;
        $kept = 0;
        foreach (CorpusFloor::reports() as $report) {
            foreach ($report['findings'] as $recorded) {
                if ($recorded['priority'] === Priority013::NONE) {
                    continue;
                }
                $what = $report['name'].' '.$recorded['package'];
                $finding = CorpusFloor::finding($recorded);
                $rotOnly = $finding->grade();
                $steps = $finding->priorityBasis()->steps();
                $last = $steps === [] ? null : $steps[\count($steps) - 1];
                if ($last !== null && $last['reason'] === PriorityBasis013::STEP_NO_FIX_EXPECTED) {
                    ++$raised;
                    self::assertSame($last['from'], $rotOnly, $what);
                    self::assertSame($recorded['priority'], $last['to'], $what);
                    continue;
                }
                ++$kept;
                self::assertSame($recorded['priority'], $rotOnly, $what);
            }
        }
        self::assertGreaterThan(0, $raised);
        self::assertGreaterThan(0, $kept);
    }
}
