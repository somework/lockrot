<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/** `--explain` reads no baseline, so no recorded explain-2 document carries a baseline standing. */
#[CoversNothing]
final class RecordedExplainHasNoBaselineTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/';

    public function testEveryRecordedExplainDocumentHasANullFindingBaseline(): void
    {
        $files = array_merge(
            glob(self::FIXTURES.'contract/explain-2/*.json') ?: [],
            glob(self::FIXTURES.'baselined/*/check/explain/*.json') ?: []
        );
        self::assertNotSame([], $files);
        foreach ($files as $file) {
            $document = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($document, $file);
            self::assertNull(JsonPath::arrayAt($document, ['finding'])['baseline'] ?? null, $file);
        }
    }
}
