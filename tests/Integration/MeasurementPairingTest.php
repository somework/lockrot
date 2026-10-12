<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;

/**
 * A measurement at key K is null exactly when `K_unmeasured` beside it is set, in every object of
 * every fixture document, at any depth.
 */
final class MeasurementPairingTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/';

    public function testEveryNullMeasurementHasItsReasonAndOnlyANullOne(): void
    {
        $files = array_merge([self::FIXTURES.'flags/cases.json', self::FIXTURES.'flags/composite.json'], (array) glob(self::FIXTURES.'schema/documents/*/*.json'));
        $pairs = 0;
        foreach ($files as $file) {
            $pairs += self::walk(JsonPath::decodeFile((string) $file), basename((string) $file));
        }

        self::assertGreaterThan(100, $pairs, 'the fixtures hold measurements');
    }

    /** @param array<mixed> $node */
    private static function walk(array $node, string $where): int
    {
        $pairs = 0;
        foreach ($node as $key => $value) {
            $key = (string) $key;
            $measured = substr($key, 0, -\strlen('_unmeasured'));
            if (substr($key, -\strlen('_unmeasured')) === '_unmeasured' && \array_key_exists($measured, $node)) {
                self::assertNotSame($node[$measured] === null, $value === null, $where.': '.$measured.' is null exactly when '.$key.' is set');
                ++$pairs;
            }
            if (\is_array($value)) {
                $pairs += self::walk($value, $where.'.'.$key);
            }
        }

        return $pairs;
    }
}
