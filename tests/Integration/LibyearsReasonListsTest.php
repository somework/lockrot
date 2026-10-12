<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Analyzer\Libyears;
use Lockrot\Json\KnownValues;
use Lockrot\Json\Schemas;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;

/**
 * The reasons a package goes unmeasured are listed in the code, in both report-2 schemas'
 * `libyearsUnmeasured`, and in the report block's `required` and `properties`, all in the block's
 * key order.
 */
final class LibyearsReasonListsTest extends TestCase
{
    public function testEveryListOfReasonsIsTheCodesInItsOrder(): void
    {
        $report = JsonPath::decodeFile(Schemas::path(Schemas::REPORT, 2));
        $explain = JsonPath::decodeFile(Schemas::path(Schemas::EXPLAIN, 2));
        $field = ['definitions', 'libyearsUnmeasured', KnownValues::KEYWORD];
        $block = ['properties', 'libyears', 'properties', 'unmeasured'];

        self::assertSame(Libyears::REASONS, JsonPath::arrayAt($report, $field), 'the report schema\'s x-known-values');
        self::assertSame(Libyears::REASONS, JsonPath::arrayAt($explain, $field), 'the explain schema\'s x-known-values');
        self::assertSame(Libyears::REASONS, JsonPath::arrayAt($report, array_merge($block, ['required'])), 'the block\'s required keys');
        self::assertSame(Libyears::REASONS, array_map('strval', array_keys(JsonPath::arrayAt($report, array_merge($block, ['properties'])))), 'the block\'s properties');
    }
}
