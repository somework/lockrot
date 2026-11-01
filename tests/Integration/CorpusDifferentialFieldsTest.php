<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\CorpusDifferential;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;

/**
 * The corpus differential runs with `--fail-on=high`, and the model documents carry each finding's gate decision at that
 * value. A gate key that the differential leaves out lets a regression of lockrot's gate pass the comparison on real locks.
 */
final class CorpusDifferentialFieldsTest extends TestCase
{
    private const MODEL = __DIR__.'/../fixtures/corpus/model-report-2';

    public function testTheDifferentialComparesEveryGateKeyOfTheModelFindings(): void
    {
        $gateKeys = [];
        foreach (array_diff(glob(self::MODEL.'/*.json') ?: [], [self::MODEL.'/provenance.json']) as $path) {
            foreach (JsonPath::arrayAt(JsonPath::decodeFile($path), ['findings']) as $finding) {
                foreach (\is_array($finding) && \is_array($finding['gate'] ?? null) ? array_keys($finding['gate']) : [] as $key) {
                    $gateKeys['gate.'.$key] = true;
                }
            }
        }
        self::assertNotSame([], $gateKeys, 'the model findings carry a gate object');

        self::assertSame([], array_values(array_diff(array_keys($gateKeys), CorpusDifferential::FIELDS)));
    }
}
