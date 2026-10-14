<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Json;

use Lockrot\Json\Schemas;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;

/**
 * docs/compatibility.md#deprecation: a deprecated JSON field stays written, and the schema marks it
 * `x-deprecated: true`. report-2 deprecates the `priority` alias and its root totals.
 */
final class DeprecatedFieldsTest extends TestCase
{
    public function testReport2MarksThePriorityAliasAndItsTotalsDeprecated(): void
    {
        $schema = JsonPath::decodeFile(Schemas::path(Schemas::REPORT, 2));

        self::assertSame(['#/definitions/finding/properties/priority', '#/properties/priorities'], self::deprecated($schema, '#'));
    }

    /**
     * @param array<mixed, mixed> $node
     *
     * @return list<string>
     */
    private static function deprecated(array $node, string $path): array
    {
        $found = ($node['x-deprecated'] ?? null) === true ? [$path] : [];
        foreach ($node as $key => $child) {
            if (\is_array($child)) {
                $found = array_merge($found, self::deprecated($child, $path.'/'.$key));
            }
        }
        sort($found);

        return $found;
    }
}
