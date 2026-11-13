<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Json\Schemas;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Every document lockrot-report vendors, against its published schema and its strict twin: the
 * recorded contract bundle (tools/report/record) and the real baselined scenarios.
 *
 * It covers nothing, as JsonSchemaConformanceTest does. The group parallel-rows gives each data row
 * a unit of its own in the ParaTest run of ci.yml.
 *
 * @coversNothing
 *
 * @group covers-nothing
 * @group schema-sweep
 * @group parallel-rows
 */
#[CoversNothing]
#[Group('covers-nothing')]
#[Group('schema-sweep')]
#[Group('parallel-rows')]
final class RecordedContractDocumentsSchemaTest extends TestCase
{
    use ValidatesJsonSchemas;

    private const FIXTURES = __DIR__.'/../fixtures/';

    /** @return iterable<string, array{string, string}> the schema and the path of each document, by its path below tests/fixtures */
    public static function recordedContractDocuments(): iterable
    {
        $documents = [Schemas::REPORT => ['contract/report-2/*.json', 'contract/model-only/*.json', 'baselined/*/check/report.json', 'baselined/*/check/gates-left-behind-vulnerable-high.json', 'baselined/*/check/target-*.json', 'baselined/*/regenerate/report.json', 'baselined/*/t0/report.json'],
            Schemas::EXPLAIN => ['contract/explain-2/*.json', 'baselined/*/check/explain/*.json'],
            Schemas::BASELINE => ['contract/baseline-2/*.json', 'baselined/*/lockrot-baseline.json', 'baselined/*/regenerate/lockrot-baseline.json']];
        foreach ($documents as $document => $patterns) {
            foreach ($patterns as $pattern) {
                foreach (glob(self::FIXTURES.$pattern) ?: [] as $file) {
                    yield substr($file, \strlen(self::FIXTURES)) => [$document, $file];
                }
            }
        }
    }

    public function testTheRowsHoldEveryDocumentOfTheContractBundle(): void
    {
        $bundle = [];
        foreach (['report-2', 'model-only', 'explain-2', 'baseline-2'] as $directory) {
            foreach (scandir(self::FIXTURES.'contract/'.$directory) ?: [] as $entry) {
                if (substr($entry, -5) === '.json') {
                    $bundle[] = 'contract/'.$directory.'/'.$entry;
                }
            }
        }
        $rows = array_keys(iterator_to_array(self::recordedContractDocuments()));

        self::assertGreaterThan(100, \count($rows), 'record the bundle with tools/report/record');
        self::assertSame([], array_values(array_diff($bundle, $rows)));
    }

    /**
     * @dataProvider recordedContractDocuments
     */
    #[DataProvider('recordedContractDocuments')]
    public function testTheRecordedContractDocumentValidatesAgainstItsSchemaAndItsStrictTwin(string $document, string $file): void
    {
        $json = (string) file_get_contents($file);
        $this->assertValid($document, $json, $file);
        $this->assertValid($document, $json, $file, true);
    }
}
