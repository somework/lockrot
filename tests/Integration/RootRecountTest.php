<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Output\JsonFormatter;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\RootRecount;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use PHPUnit\Framework\TestCase;

/**
 * Each root block of report-2 and `run.score_rules_used` agree with the written `findings[]`: a
 * reader that decodes the document and counts again gets the written block. The documents are the
 * cases of tests/fixtures/flags/cases.json, each under fail-on values that make findings reach the
 * gate, and once with a baseline of its own findings that exempts them. AppRootRecountTest checks
 * the fixture apps.
 */
final class RootRecountTest extends TestCase
{
    private const FAIL_ON = ['low', 'abandoned', 'unchecked'];

    public function testEveryRootBlockOfEveryCaseRecountsFromItsFindings(): void
    {
        $mismatches = [];
        $seen = ['vulnerable' => 0, 'reaching' => 0, 'failing' => 0, 'exempt' => 0, 'libyears' => 0, 'data_date' => 0];
        foreach (self::documents() as $label => $document) {
            foreach (RootRecount::mismatches($document) as $mismatch) {
                $mismatches[] = $label.' '.$mismatch;
            }
            $seen['vulnerable'] += JsonPath::intAt($document, ['security', 'packages', 'vulnerable']);
            $seen['reaching'] += JsonPath::intAt($document, ['gate', 'reaching']);
            $seen['failing'] += JsonPath::intAt($document, ['gate', 'failing']);
            $seen['exempt'] += JsonPath::intAt($document, ['gate', 'exempt', 'baseline']);
            $seen['libyears'] += \is_float(JsonPath::arrayAt($document, ['libyears'])['total'] ?? null) ? 1 : 0;
            $seen['data_date'] += \is_string($document['data_date'] ?? null) ? 1 : 0;
        }

        self::assertSame([], $mismatches);
        self::assertSame([], array_keys(array_filter($seen, static fn (int $count): bool => $count === 0)), 'the cases make every counted fact occur');
    }

    /** @return iterable<string, array<mixed, mixed>> */
    private static function documents(): iterable
    {
        $cases = 0;
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(CaseHydrator::CASES), ['cases']) as $case) {
            if (!\is_array($case) || !\is_array($case['inputs'] ?? null)) {
                continue;
            }
            ++$cases;
            $id = JsonPath::stringAt($case, ['id']);
            $report = CaseHydrator::report($case);
            yield $id => self::decode($report);
            foreach (self::FAIL_ON as $failOn) {
                $run = new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::fromString($failOn), RunSettings::SOURCE_OPTION, null);
                yield $id.' --fail-on='.$failOn => self::decode($report->withRun($run));
            }
            $names = array_map(static fn (Finding $finding): string => $finding->package(), $report->findings());
            $known = $report->withBaseline(BaselineComparison::compare(Baseline::fromReport($report), $report, 'lockrot-baseline.json', $names));
            $run = new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::fromString('low'), RunSettings::SOURCE_OPTION, null);
            yield $id.' with its own baseline' => self::decode($known->withRun($run));
        }
        self::assertGreaterThan(0, $cases);
    }

    /** @return array<mixed, mixed> */
    private static function decode(Report $report): array
    {
        $document = json_decode((new JsonFormatter())->format($report), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        return $document;
    }
}
