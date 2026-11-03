<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The corpus differential compares lockrot with these model documents field by field, so a model
 * document that the published schema refuses would pin lockrot to a shape that no consumer reads.
 *
 * @coversNothing
 *
 * @group covers-nothing
 * @group schema-sweep
 */
#[CoversNothing]
#[Group('covers-nothing')]
#[Group('schema-sweep')]
final class CorpusModelDocumentsSchemaTest extends TestCase
{
    use ValidatesJsonSchemas;

    private const MODEL = __DIR__.'/../fixtures/corpus/model-report-2/';

    /** @return iterable<string, array{string}> */
    public static function documents(): iterable
    {
        foreach (array_keys(JsonPath::arrayAt(JsonPath::decodeFile(self::MODEL.'provenance.json'), ['outputs'])) as $name) {
            yield (string) $name => [(string) $name];
        }
    }

    /**
     * @dataProvider documents
     */
    #[DataProvider('documents')]
    public function testTheModelDocumentIsValidAgainstReport2(string $name): void
    {
        $json = (string) file_get_contents(self::MODEL.$name);

        self::assertSame('https://lockrot.dev/schema/report-2.json', JsonPath::stringAt(JsonPath::decodeFile(self::MODEL.$name), ['$schema']));
        self::assertSame([], \array_slice($this->errors('report', $json, false), 0, 5));
    }

    public function testTheProvenanceNamesEveryModelDocument(): void
    {
        $names = array_map('basename', glob(self::MODEL.'*.json') ?: []);
        $documents = array_values(array_diff($names, ['provenance.json']));
        $named = array_map('strval', array_keys(JsonPath::arrayAt(JsonPath::decodeFile(self::MODEL.'provenance.json'), ['outputs'])));
        sort($documents);
        sort($named);

        self::assertNotSame([], $named);
        self::assertSame($documents, $named);
    }
}
