<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Score;

use Lockrot\Score\Coverage;
use Lockrot\Score\GateScore;
use Lockrot\Verdict\Score;
use Lockrot\Verdict\ScoreModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoverageTest extends TestCase
{
    /** @return iterable<string, array{?string, list<string>, list<string>}> */
    public static function coverageRows(): iterable
    {
        yield 'old-promise recorded, stale fires by ageing' => ['old-promise', ['old-promise', 'stale'], []];
        yield 'pinned recorded, stale fires' => ['pinned', ['pinned', 'stale'], []];
        yield 'abandoned recorded, old-promise fires on a target-PHP bump' => ['abandoned', ['abandoned', 'old-promise'], []];
        yield 'left-behind recorded, old-promise fires' => ['left-behind', ['left-behind', 'old-promise'], []];
        yield 'stale recorded, the package goes silent' => ['stale', ['silent'], ['silent']];
        yield 'stale recorded, old-promise fires' => ['stale', ['old-promise', 'stale'], ['old-promise']];
        yield 'old-promise recorded, left-behind fires' => ['old-promise', ['left-behind', 'old-promise'], ['left-behind']];
        yield 'old-promise recorded, two flags above it fire' => ['old-promise', ['abandoned', 'left-behind', 'old-promise'], ['abandoned', 'left-behind']];
        yield 'an entry with no flag covers none' => [null, ['left-behind', 'stale'], ['left-behind', 'stale']];
    }

    /**
     * @param list<string> $counted
     * @param list<string> $uncovered
     *
     * @dataProvider coverageRows
     */
    #[DataProvider('coverageRows')]
    public function testTheRecordedLeadCoversItselfAndEveryFlagAfterIt(?string $lead, array $counted, array $uncovered): void
    {
        self::assertSame($uncovered, Coverage::under($lead)->uncovered($counted));
    }

    public function testACoveredFlagIsCoveredByTheRecordedLead(): void
    {
        $coverage = Coverage::under('left-behind');

        self::assertTrue($coverage->covers('left-behind'));
        self::assertTrue($coverage->covers('stale'));
        self::assertFalse($coverage->covers('pinned'));
        self::assertSame('left-behind', $coverage->recordedLead());
        self::assertFalse(Coverage::under(null)->covers('stale'));
        self::assertNull(Coverage::under(null)->recordedLead());
    }

    public function testAFlagOutsideTheMaintenanceOrderIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Coverage::under('vulnerable');
    }

    /**
     * §6.4: the new maintenance part is the lead in full plus a quarter of each other uncovered flag,
     * halved by reach and dev, for every flag set and every recorded lead.
     */
    public function testTheMaskedRerunEqualsTheFormulaForEveryTailCoverage(): void
    {
        $checked = 0;
        foreach (self::flagSets() as $flags) {
            foreach (array_merge([null], array_keys(ScoreModel::POINTS)) as $lead) {
                foreach ([Score::DIRECT, Score::TRANSITIVE] as $reach) {
                    foreach ([false, true] as $dev) {
                        $uncovered = Coverage::under($lead)->uncovered($flags);
                        $gate = GateScore::of(Score::compute($flags, [], $reach, $dev), $uncovered, []);
                        self::assertSame(self::formula($uncovered, $reach, $dev), $gate->newScore()->exactHalves(), implode(',', $flags).' under '.($lead ?? '-'));
                        ++$checked;
                    }
                }
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    /** @param list<string> $uncovered in flag order */
    private static function formula(array $uncovered, string $reach, bool $dev): int
    {
        $halves = 0;
        foreach ($uncovered as $i => $flag) {
            $halves += 2 * ($i === 0 ? ScoreModel::POINTS[$flag] : intdiv(ScoreModel::POINTS[$flag], 4));
        }
        if ($reach !== Score::DIRECT) {
            $halves = intdiv($halves, 2);
        }

        return $dev ? intdiv($halves, 2) : $halves;
    }

    /** @return list<list<string>> every set of a liveness word, pinned or left-behind, and old-promise, in flag order */
    private static function flagSets(): array
    {
        $sets = [];
        foreach ([null, 'abandoned', 'silent', 'stale'] as $live) {
            foreach ([null, 'pinned', 'left-behind'] as $branch) {
                foreach ([null, 'old-promise'] as $promise) {
                    $set = array_values(array_filter([$live, $branch, $promise]));
                    if ($set !== []) {
                        $sets[] = array_values(array_intersect(ScoreModel::FLAG_ORDER, $set));
                    }
                }
            }
        }

        return $sets;
    }
}
