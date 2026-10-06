<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Analyzer\Libyears;
use Lockrot\Json\KnownValues;
use Lockrot\Json\Schemas;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Output\JsonFormatter;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;

/**
 * The reasons a package goes unmeasured are listed in five places — the code, both schemas'
 * `libyears_unmeasured`, and the report block's `required` and `properties` — and all five list them
 * in one order, the block's key order. The order they are checked in is a different list, spelled
 * out as a numbered list in the field's description; it names the same four.
 */
final class LibyearsReasonListsTest extends TestCase
{
    /** The order {@see Libyears::measure()} checks them in; LibyearsTest pins each step. */
    private const PRECEDENCE = [
        Libyears::NOT_FROM_COMPOSER_REPOSITORY,
        Libyears::METADATA_UNAVAILABLE,
        Libyears::BRANCH_SNAPSHOT,
        Libyears::NO_STABLE_RELEASE_DATE,
    ];

    public function testEveryListOfReasonsIsTheCodesInItsOrder(): void
    {
        $report = JsonPath::decodeFile(Schemas::path(Schemas::REPORT, JsonFormatter::SCHEMA));
        $explain = JsonPath::decodeFile(Schemas::path(Schemas::EXPLAIN, ExplainFormatter::SCHEMA));
        $field = ['definitions', 'finding', 'properties', 'libyears_unmeasured', 'oneOf', 0, KnownValues::KEYWORD];
        $block = ['properties', 'libyears', 'properties', 'unmeasured'];

        self::assertSame(Libyears::REASONS, JsonPath::arrayAt($report, $field), 'the report schema\'s x-known-values');
        self::assertSame(Libyears::REASONS, JsonPath::arrayAt($explain, $field), 'the explain schema\'s x-known-values');
        self::assertSame(Libyears::REASONS, JsonPath::arrayAt($report, array_merge($block, ['required'])), 'the block\'s required keys');
        self::assertSame(Libyears::REASONS, array_map('strval', array_keys(JsonPath::arrayAt($report, array_merge($block, ['properties'])))), 'the block\'s properties');
    }

    public function testTheDescriptionNumbersThePrecedenceOverTheSameFourReasons(): void
    {
        $sorted = self::PRECEDENCE;
        sort($sorted);
        $reasons = Libyears::REASONS;
        sort($reasons);
        self::assertSame($reasons, $sorted);

        $description = JsonPath::stringAt(JsonPath::decodeFile(Schemas::path(Schemas::REPORT, JsonFormatter::SCHEMA)), ['definitions', 'finding', 'properties', 'libyears_unmeasured', 'description']);
        preg_match_all('/(\d)\. ([a-z_]+)/', $description, $matches);
        self::assertSame(['1', '2', '3', '4'], $matches[1]);
        self::assertSame(self::PRECEDENCE, $matches[2]);
        self::assertStringContainsString('exactly the findings whose `from_composer_repository` is false', $description, 'the first reason is the typed flag, not a list of repository types');
    }
}
