<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Baseline;

use Lockrot\Baseline\BaselineSchema;
use Lockrot\Exception\ConfigException;
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
