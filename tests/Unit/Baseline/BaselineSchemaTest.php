<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Baseline;

use Lockrot\Baseline\BaselineSchema;
use Lockrot\Exception\ConfigException;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The reader's own validation of baseline-2 (`BaselineSchema::validate`, the public entry): every
 * negative of tests/fixtures/schema/negative/baseline-2/ is refused with the field it names, and
 * every committed baseline-2 document is read.
 */
final class BaselineSchemaTest extends TestCase
{
    private const NEGATIVE = __DIR__.'/../../fixtures/schema/negative/baseline-2/';
    private const DOCUMENTS = __DIR__.'/../../fixtures/schema/documents/baseline/';

    /** @return iterable<string, array{string, string}> the negative fixture, and the field its error names */
    public static function negatives(): iterable
    {
        $expect = json_decode((string) file_get_contents(self::NEGATIVE.'EXPECT.json'), true);
        self::assertIsArray($expect);
        foreach ($expect as $file => $field) {
            // The reader decodes to arrays, where `[]` and `{}` are one value: it reads an empty
            // findings list as the empty map. JsonSchemaConformanceTest refuses this fixture as published.
            if ($file !== 'findings-array.json' && \is_string($field)) {
                yield (string) $file => [(string) $file, $field];
            }
        }
    }

    /** @dataProvider negatives */
    #[DataProvider('negatives')]
    public function testEveryNegativeIsRefusedNamingItsField(string $file, string $field): void
    {
        $document = self::read(self::NEGATIVE.$file);

        if (substr($file, -12) === '.strict.json') {
            BaselineSchema::validate($document);
            $this->addToAssertionCount(1);

            return;
        }
        try {
            BaselineSchema::validate($document);
        } catch (ConfigException $e) {
            self::assertStringContainsString($field, $e->getMessage()."\n".implode("\n", $e->details()), 'the message or its -v details name the field');

            return;
        }
        self::fail($file.' was read');
    }

    public function testEveryCommittedBaseline2DocumentIsRead(): void
    {
        $paths = glob(self::DOCUMENTS.'*/*.json') ?: [];
        self::assertCount(138, $paths, 'tests/fixtures/schema/documents/provenance.json');
        foreach ($paths as $path) {
            BaselineSchema::validate(self::read($path));
        }
    }

    /** `json_decode()` reads the advisory ids `"0"` and `"1"` as a list. The reader validates them as the map that the file holds. */
    public function testAdvisoryIdsThatDecodeAsAListAreReadAsAMap(): void
    {
        $fact = ['known_since' => '2026-06-01', 'severity' => 'high', 'fix_kind' => 'update'];
        $document = json_decode((string) json_encode([
            '$schema' => 'https://lockrot.dev/schema/baseline-2.json',
            'lockrot' => ['version' => '0.14.0', 'schema' => 2],
            'generated_at' => '2026-06-01T00:00:00+00:00',
            'target_php' => '8.4',
            'target_php_source' => 'option',
            'score_model_id' => 1,
            'fix_model' => 1,
            'findings' => ['acme/a' => ['version' => '1.0.0', 'first_seen' => '2026-06-01', 'advisories' => ['0' => $fact, '1' => $fact]]],
        ]), true);
        self::assertIsArray($document);
        self::assertSame([0, 1], array_keys(JsonPath::arrayAt($document, ['findings', 'acme/a', 'advisories'])), 'the case under test');

        /** @var array<string, mixed> $document */
        BaselineSchema::validate($document);
        $this->addToAssertionCount(1);
    }

    /** @return array<string, mixed> */
    private static function read(string $path): array
    {
        $document = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($document, $path);
        unset($document['$expect']);

        /** @var array<string, mixed> $document */
        return $document;
    }
}
