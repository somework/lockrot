<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Analyzer\Report;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\CorpusFloor;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Score;
use Lockrot\Verdict\ScoreModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The order of the graded view: the verdict, then the security points, the score, direct before
 * transitive, and the name. A re-sort by the keys of `ScoreModel::toArray()['sort']` gives the same
 * order.
 */
final class SortTest extends TestCase
{
    private const NOW = '2026-10-01T00:00:00+00:00';

    /**
     * Each row names two findings, the first one first.
     *
     * @return iterable<string, array{Finding, Finding}>
     */
    public static function pairs(): iterable
    {
        yield 'critical before high' => [self::graded('vendor/b', [Signal::S1]), self::graded('vendor/a', [Signal::S8])];
        yield 'high before medium' => [self::graded('vendor/b', [Signal::S8]), self::graded('vendor/a', [Signal::S2])];
        yield 'medium before low' => [self::graded('vendor/b', [Signal::S2]), self::graded('vendor/a', [], [Score::advisory('PKSA-1', 'low', 'update')])];
        yield 'low before unknown' => [self::graded('vendor/b', [], [Score::advisory('PKSA-1', 'low', 'update')]), self::graded('vendor/a', [], [], null, false)];
        yield 'unknown before finished' => [self::graded('vendor/b', [], [], null, false), self::graded('vendor/a', [Signal::S2], [], self::entry('vendor/a'))];
        yield 'unknown before ok' => [self::graded('vendor/b', [], [], null, false), self::graded('vendor/a', [])];
        yield 'finished and ok as one group: by name' => [self::graded('vendor/a', []), self::graded('vendor/b', [Signal::S2], [], self::entry('vendor/b'))];
        yield 'ok and finished as one group: by name' => [self::graded('vendor/a', [Signal::S2], [], self::entry('vendor/a')), self::graded('vendor/b', [])];
        yield 'in one band, the security points first' => [self::graded('vendor/b', [], [Score::advisory('PKSA-1', 'high', 'update')]), self::graded('vendor/a', [Signal::S8], [Score::advisory('PKSA-2', 'low', 'update')])];
        yield 'the security points after the dev halving' => [self::graded('vendor/a', [], [Score::advisory('PKSA-1', 'medium', 'update')]), self::graded('vendor/b', [], [Score::advisory('PKSA-2', 'high', 'update')], null, true, null, true)];
        yield 'with equal security points, the higher score first' => [self::graded('vendor/b', [Signal::S8, Signal::S5]), self::graded('vendor/a', [Signal::S8])];
        yield 'a half point counts' => [self::graded('vendor/b', [Signal::S8, Signal::S5], [], null, true, ['vendor/root', 'vendor/b'], true), self::graded('vendor/a', [Signal::S8], [], null, true, ['vendor/root', 'vendor/a'], true)];
        yield 'with an equal score, direct first' => [self::graded('vendor/b', [Signal::S2]), self::graded('vendor/a', [Signal::S8], [], null, true, ['vendor/root', 'vendor/a'])];
        yield 'then the name' => [self::graded('vendor/a', [Signal::S8]), self::graded('vendor/b', [Signal::S8])];
        yield 'the name byte by byte' => [self::graded('Vendor/b', [Signal::S8]), self::graded('vendor/a', [Signal::S8])];
    }

    /** @dataProvider pairs */
    #[DataProvider('pairs')]
    public function testCompareGradedPutsTheFirstFindingFirst(Finding $first, Finding $second): void
    {
        self::assertLessThan(0, Report::compareGraded($first, $second));
        self::assertGreaterThan(0, Report::compareGraded($second, $first));
        self::assertSame(0, Report::compareGraded($first, $first));
        self::assertSame([$first, $second], (new Report([$second, $first], [], new \DateTimeImmutable(self::NOW), 2, 0))->sorted());
        self::assertSame([$first, $second], (new Report([$first, $second], [], new \DateTimeImmutable(self::NOW), 2, 0))->sorted());
    }

    public function testSortedKeepsEveryFindingAndGradedOnlyTheGradedOnesInTheSameOrder(): void
    {
        $ok = self::graded('vendor/ok', []);
        $low = self::graded('vendor/low', [], [Score::advisory('PKSA-1', 'low', 'update')]);
        $high = self::graded('vendor/high', [Signal::S8]);
        $report = new Report([$ok, $low, $high], [], new \DateTimeImmutable(self::NOW), 3, 0);

        self::assertSame([$high, $low, $ok], $report->sorted());
        self::assertSame([$high, $low], $report->graded());
    }

    /** @return iterable<string, array{Report}> */
    public static function corpusReports(): iterable
    {
        self::assertNotSame([], CorpusFloor::reports());
        foreach (CorpusFloor::reports() as $report) {
            yield $report['name'] => [self::floorReport($report['name'])];
        }
    }

    /**
     * The keys of `ScoreModel::toArray()['sort']`, read by path as a consumer reads them, sort every
     * corpus report's findings into the order of `Report::sorted()`.
     *
     * @dataProvider corpusReports
     */
    #[DataProvider('corpusReports')]
    public function testAResortByThePublishedKeysGivesTheEnginesOrder(Report $report): void
    {
        $sorted = $report->sorted();
        $rows = array_map([self::class, 'row'], $sorted);
        $resorted = $rows;
        $keys = ScoreModel::toArray()['sort'];
        self::assertIsArray($keys);
        usort($resorted, static function (array $a, array $b) use ($keys): int {
            foreach ($keys as $key) {
                self::assertIsArray($key);
                self::assertIsString($key['path']);
                $order = self::compareByKey($key, $a[$key['path']] ?? $key['default'], $b[$key['path']] ?? $key['default']);
                if ($order !== 0) {
                    return $order;
                }
            }

            return 0;
        });

        self::assertSame(array_column($rows, 'package'), array_column($resorted, 'package'));
    }

    /**
     * The findings of the report-2 document stand in the order that the keys of `run.score_model.sort`
     * give when they read the document's own fields by path.
     *
     * @dataProvider corpusReports
     */
    #[DataProvider('corpusReports')]
    public function testTheReport2FindingsStandInThePublishedOrder(Report $report): void
    {
        $findings = $report->toArray()['findings'];
        self::assertIsArray($findings);
        $keys = ScoreModel::toArray()['sort'];
        self::assertIsArray($keys);
        $resorted = [];
        foreach ($findings as $finding) {
            self::assertIsArray($finding);
            $resorted[] = $finding;
        }
        usort($resorted, static function (array $a, array $b) use ($keys): int {
            foreach ($keys as $key) {
                self::assertIsArray($key);
                self::assertIsString($key['path']);
                $order = self::compareByKey($key, self::valueAt($a, $key['path']) ?? $key['default'], self::valueAt($b, $key['path']) ?? $key['default']);
                if ($order !== 0) {
                    return $order;
                }
            }

            return 0;
        });

        self::assertSame(array_column($findings, 'package'), array_column($resorted, 'package'));
    }

    /** wallabag's twig carries a critical advisory, which no other critical finding there outweighs. */
    public function testWallabagsTwigIsTheFirstFindingOfItsCorpusReport(): void
    {
        $first = self::floorReport('wallabag')->sorted()[0];

        self::assertSame('twig/twig', $first->package());
        self::assertSame('critical', $first->grade());
        self::assertSame(64, $first->score()->securityHalves());
    }

    /**
     * The fields that `ScoreModel::toArray()['sort']` names, by path.
     *
     * @return array<string, mixed>
     */
    private static function row(Finding $finding): array
    {
        return [
            'verdict' => $finding->grade(),
            'score.parts.security.contribution' => $finding->score()->securityHalves() / 2,
            'score.exact' => $finding->score()->exactHalves() / 2,
            'direct' => $finding->isDirect(),
            'package' => $finding->package(),
        ];
    }

    /**
     * @param array<mixed, mixed> $finding
     *
     * @return mixed the value at a dotted path, null when a step is missing
     */
    private static function valueAt(array $finding, string $path)
    {
        $node = $finding;
        foreach (explode('.', $path) as $step) {
            if (!\is_array($node) || !\array_key_exists($step, $node)) {
                return null;
            }
            $node = $node[$step];
        }

        return $node;
    }

    /**
     * @param array<mixed> $key one entry of `sort`
     * @param mixed        $a
     * @param mixed        $b
     */
    private static function compareByKey(array $key, $a, $b): int
    {
        switch ($key['dir']) {
            case 'order':
                $group = static function ($verdict) use ($key): int {
                    self::assertIsArray($key['order']);
                    foreach ($key['order'] as $index => $verdicts) {
                        self::assertIsInt($index);
                        self::assertIsArray($verdicts);
                        if (\in_array($verdict, $verdicts, true)) {
                            return $index;
                        }
                    }
                    self::fail('the published order has no verdict '.var_export($verdict, true));
                };

                return $group($a) <=> $group($b);
            case 'desc':
                return $b <=> $a;
            case 'true_first':
                return ($b === true) <=> ($a === true);
            case 'asc':
                self::assertSame('bytes', $key['collation']);
                self::assertIsString($a);
                self::assertIsString($b);

                return strcmp($a, $b);
        }
        self::fail('a sort direction the published model does not define: '.var_export($key['dir'], true));
    }

    /**
     * @param list<string>                                              $fired      the ids of the signals that fired
     * @param list<array{id: string, severity: string, fix_kind: string}> $advisories
     * @param list<string>|null                                         $chain      the package alone when null
     */
    private static function graded(string $package, array $fired, array $advisories = [], ?AllowlistEntry $entry = null, bool $judged = true, ?array $chain = null, bool $dev = false): Finding
    {
        $signals = array_map(static fn (string $id): Signal => new Signal($id, Signal::LEVEL_WARN, ''), $fired);

        return (new FindingBuilder())->withPackage($package)->withSignals($signals)->withChain($chain ?? [$package])->withDev($dev)
            ->withFlags(FlagSet::fromSignals($signals, $entry, $advisories), $judged)->build();
    }

    private static function entry(string $package): AllowlistEntry
    {
        return new AllowlistEntry($package, null, 'interfaces', null, 'builtin');
    }

    private static function floorReport(string $name): Report
    {
        foreach (CorpusFloor::reports() as $report) {
            if ($report['name'] === $name) {
                $findings = array_map(static fn (array $recorded): Finding => CorpusFloor::finding($recorded, CorpusFloor::advisories($recorded)), $report['findings']);

                return new Report($findings, [], new \DateTimeImmutable($report['generated_at']), \count($findings), 0);
            }
        }
        self::fail('no corpus report '.$name);
    }
}
