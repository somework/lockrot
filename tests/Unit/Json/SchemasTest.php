<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Json;

use Lockrot\Json\Schemas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where the bundled schema files are: one file per document and number, under the name a release
 * ships it as, and no file for a number lockrot does not ship.
 */
final class SchemasTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function documents(): iterable
    {
        foreach ([Schemas::REPORT, Schemas::EXPLAIN, Schemas::BASELINE, Schemas::CONFIG] as $document) {
            yield $document => [$document];
        }
    }

    /**
     * @dataProvider documents
     */
    #[DataProvider('documents')]
    public function testEachDocumentShipsNumberOneUnderItsNumberedName(string $document): void
    {
        self::assertSame([1], Schemas::numbers($document));
        self::assertSame('lockrot-'.$document.'-1.schema.json', Schemas::fileName($document, 1));

        $path = Schemas::path($document, 1);
        self::assertSame('lockrot-'.$document.'-1.schema.json', basename($path));
        self::assertSame(realpath(__DIR__.'/../../../resources'), realpath(\dirname($path)));
        self::assertFileExists($path);
    }

    /**
     * @dataProvider documents
     */
    #[DataProvider('documents')]
    public function testANumberLockrotShipsNoFileForHasNoPath(string $document): void
    {
        foreach ([0, 2, -1] as $number) {
            try {
                Schemas::path($document, $number);
            } catch (\InvalidArgumentException $e) {
                self::assertSame('lockrot ships no '.$document.'-'.$number.' schema', $e->getMessage());
                continue;
            }
            self::fail($document.'-'.$number.' has a path');
        }
    }

    public function testADocumentLockrotDoesNotWriteHasNoNumbers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('lockrot ships no sarif schema');

        Schemas::numbers('sarif');
    }

    public function testTheUrlNamesTheDocumentAndNumber(): void
    {
        self::assertSame('https://lockrot.dev/schema/report-1.json', Schemas::url(Schemas::REPORT, 1));
        self::assertSame('https://lockrot.dev/schema/config-2.json', Schemas::url(Schemas::CONFIG, 2));
    }
}
