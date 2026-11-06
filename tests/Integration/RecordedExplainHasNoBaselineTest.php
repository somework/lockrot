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
        $contract = glob(self::FIXTURES.'contract/explain-2/*.json') ?: [];
        $baselined = glob(self::FIXTURES.'baselined/*/check/explain/*.json') ?: [];
        self::assertNotSame([], $contract);
        self::assertNotSame([], $baselined);
        foreach (array_merge($contract, $baselined) as $file) {
            $document = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($document, $file);
            $finding = JsonPath::arrayAt($document, ['finding']);
            self::assertArrayHasKey('baseline', $finding, $file);
            self::assertNull($finding['baseline'], $file);
        }
    }
}
