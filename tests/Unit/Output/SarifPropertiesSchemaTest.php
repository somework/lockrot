<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use PHPUnit\Framework\TestCase;

/**
 * The bags lockrot writes in SARIF, held to tests/fixtures/sarif/lockrot-sarif-properties-2.schema.json
 * and its strict twin. tools/schema/build_sarif_properties_schema.py builds the schema from report-2,
 * and tools/schema/make_negatives_sarif.py writes the negatives from documents/B.sarif.json.
 */
final class SarifPropertiesSchemaTest extends TestCase
{
    use ValidatesJsonSchemas;

    private const FIXTURES = __DIR__.'/../../fixtures/';
    private const SCHEMA = self::FIXTURES.'sarif/lockrot-sarif-properties-2.schema.json';

    private static function schemaObject(): object
    {
        $schema = json_decode((string) file_get_contents(self::SCHEMA));
        self::assertIsObject($schema);

        return $schema;
    }

    /** @return iterable<string, array{string}> */
    public static function documents(): iterable
    {
        $files = array_merge(glob(self::FIXTURES.'golden/formats/*.sarif.sarif') ?: [], [self::FIXTURES.'sarif/documents/B.sarif.json']);
        sort($files);
        foreach ($files as $file) {
            yield basename($file) => [$file];
        }
    }

    /**
     * @dataProvider documents
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('documents')]
    public function testEveryWrittenSarifValidatesAgainstThePropertiesSchemaAndItsStrictTwin(string $file): void
    {
        // The run-level `advisories-unchecked` record names no package, and no bag of the schema fits it.
        $document = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($document);
        $results = JsonPath::arrayAt($document, ['runs', 0, 'results']);
        $run = JsonPath::arrayAt($document, ['runs', 0]);
        $run['results'] = array_values(array_filter($results, static fn ($result): bool => !\is_array($result) || ($result['ruleId'] ?? null) !== 'lockrot/advisories-unchecked'));
        $document['runs'] = [$run];
        $json = (string) json_encode($document);

        $this->assertValidAgainst(self::schemaObject(), $json, basename($file));
        $this->assertValidAgainst(self::schemaObject(), $json, basename($file), true);
    }

    /** @return iterable<string, array{string}> */
    public static function negatives(): iterable
    {
        $files = glob(self::FIXTURES.'sarif/negative/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            yield basename($file) => [$file];
        }
    }

    /**
     * A name with `strict-` after its number is rejected by the strict twin alone.
     *
     * @dataProvider negatives
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('negatives')]
    public function testEveryNegativeIsRejected(string $file): void
    {
        $strict = preg_match('/^\d+-strict-/', basename($file)) === 1;

        self::assertNotSame([], $this->errorsAgainst(self::schemaObject(), (string) file_get_contents($file), $strict), basename($file));
    }

    public function testTheNegativesAreTheTwentySevenThatTheGeneratorWrites(): void
    {
        self::assertCount(27, iterator_to_array(self::negatives()));
    }
}
