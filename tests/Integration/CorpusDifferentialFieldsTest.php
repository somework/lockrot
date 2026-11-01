<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\CorpusDifferential;
use PHPUnit\Framework\TestCase;

/**
 * The corpus differential runs with `--fail-on=high`, and the model documents carry each finding's gate decision at that
 * value. A gate key that the differential leaves out lets a regression of lockrot's gate pass the only comparison on real locks.
 */
final class CorpusDifferentialFieldsTest extends TestCase
{
    private const MODEL = __DIR__.'/../fixtures/corpus/model-report-2';

    public function testTheDifferentialComparesEveryGateKeyOfTheModelFindings(): void
    {
        $gateKeys = [];
        foreach (glob(self::MODEL.'/*.json') ?: [] as $path) {
            $document = json_decode((string) file_get_contents($path), true);
            foreach (is_array($document) && is_array($document['findings'] ?? null) ? $document['findings'] : [] as $finding) {
                foreach (array_keys((array) ($finding['gate'] ?? [])) as $key) {
                    $gateKeys['gate.'.$key] = true;
                }
            }
        }
        self::assertNotSame([], $gateKeys, 'the model findings carry a gate object');

        self::assertSame([], array_values(array_diff(array_keys($gateKeys), CorpusDifferential::FIELDS)));
    }
}
