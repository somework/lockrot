<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use JsonSchema\Validator;
use Lockrot\Json\Schemas;
use Lockrot\Output\JsonFormatter;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;

/** A committed case, run through the real analyzer, gives the score its document records. */
final class CaseHydratorTest extends TestCase
{
    /**
     * The cases whose projection onto this pull request leaves a finding that report-2 rejects.
     * not-found‡: PR 4d writes the activity check that blocks S4. The projection drops it with
     * `activity` and keeps `liveness_complete: false`.
     */
    private const INVALID_UNTIL = ['not-found‡'];

    public function testCaseAScoresItsRecordedScoreText(): void
    {
        $case = CaseHydrator::case('A');
        $recorded = JsonPath::arrayAt($case, ['finding']);

        $document = CaseHydrator::report($case)->toArray();

        $finding = null;
        foreach (JsonPath::arrayAt($document, ['findings']) as $row) {
            self::assertIsArray($row);
            if (JsonPath::stringAt($row, ['package']) === JsonPath::stringAt($recorded, ['package'])) {
                $finding = $row;
            }
        }
        self::assertNotNull($finding, 'the case package has a finding');
        self::assertSame('32 = vulnerable 32 [critical advisory]', JsonPath::stringAt($recorded, ['score', 'text']), 'the case records its score text');
        self::assertSame(JsonPath::stringAt($recorded, ['score', 'text']), JsonPath::stringAt($finding, ['score', 'text']));
        self::assertSame(JsonPath::stringAt($recorded, ['verdict']), JsonPath::stringAt($finding, ['verdict']));
    }

    /** Case C has a release without a date: lockrot reads it as undated, and the case still hydrates. */
    public function testACaseWithAnUndatedReleaseHydrates(): void
    {
        $case = CaseHydrator::case('C');
        $undated = 0;
        foreach (JsonPath::arrayAt($case, ['inputs', 'releases']) as $release) {
            $undated += \is_array($release) && \array_key_exists('time', $release) && $release['time'] === null ? 1 : 0;
        }
        self::assertGreaterThan(0, $undated, 'case C records an undated release');

        $packages = array_column(JsonPath::arrayAt(CaseHydrator::report($case)->toArray(), ['findings']), 'package');

        self::assertContains(JsonPath::stringAt($case, ['finding', 'package']), $packages);
    }

    /** Every case's recorded `finding`, with or without inputs, is a report-2 finding. */
    public function testEveryCaseFindingValidatesAgainstReport2(): void
    {
        $schema = JsonPath::decodeFile(Schemas::path(Schemas::REPORT, JsonFormatter::SCHEMA));
        $finding = json_decode((string) json_encode(['$ref' => '#/definitions/finding', 'definitions' => JsonPath::arrayAt($schema, ['definitions'])]));
        $cases = JsonPath::decodeFile(__DIR__.'/../fixtures/flags/cases.json');
        $cases = JsonPath::has($cases, ['cases']) ? JsonPath::arrayAt($cases, ['cases']) : $cases;
        self::assertNotSame([], $cases);

        $invalid = [];
        foreach ($cases as $case) {
            self::assertIsArray($case);
            $validator = new Validator();
            $document = json_decode((string) json_encode(JsonPath::arrayAt($case, ['finding'])));
            $validator->validate($document, $finding);
            if (!$validator->isValid()) {
                $invalid[] = JsonPath::stringAt($case, ['id']);
            }
        }

        self::assertSame(self::INVALID_UNTIL, $invalid);
    }

    public function testACaseWithoutInputsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CaseHydrator::report(['id' => 'X', 'inputs' => null]);
    }
}
