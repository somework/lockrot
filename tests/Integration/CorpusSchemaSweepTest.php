<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\ValidatesJsonSchemas;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The corpus-scale report-2 documents of the model are valid against lockrot's schema. One takes
 * seconds to validate, so this test is in the sweep group: CoverageTest reads them for coverage only.
 *
 * @coversNothing
 *
 * @group sweep
 */
#[CoversNothing]
#[Group('sweep')]
final class CorpusSchemaSweepTest extends TestCase
{
    use ValidatesJsonSchemas;

    private const DOCUMENTS = __DIR__.'/../fixtures/schema/documents/';

    public function testEveryCorpusScaleDocumentIsValid(): void
    {
        $paths = [];
        foreach (['corpus/*.json.gz', 'coverage/baselined/*.json.gz', 'coverage/variants/*.json.gz'] as $pattern) {
            $paths = array_merge($paths, glob(self::DOCUMENTS.$pattern) ?: []);
        }
        self::assertCount(137, $paths, 'tests/fixtures/schema/documents/provenance.json');
        $invalid = [];
        foreach ($paths as $path) {
            $errors = $this->errors('report', (string) file_get_contents('compress.zlib://'.$path), false);
            if ($errors !== []) {
                $invalid[] = basename($path).': '.implode('; ', \array_slice($errors, 0, 3));
            }
        }

        self::assertSame([], $invalid);
    }
}
