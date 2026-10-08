<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Json;

use Lockrot\Json\Schemas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An editor shows a schema's descriptions on hover, so each property has one, and none carries text
 * that a consumer cannot use: a design section, a review round, an item or decision id, a measured
 * count, an internal PHP symbol, a repository path or the history of an unshipped draft. A `-1`
 * file ships as it was published and is not read here.
 */
final class SchemaDescriptionsTest extends TestCase
{
    /**
     * A port of `SHIP_BANNED` in tools/schema/ship_text.py, which the builders apply: a change there
     * changes this pattern in the same commit.
     */
    private const BANNED = '{§|SPEC|\bround \d|attack round|schema round|critic round|revision[- ]?\d|‡|r3/|\bcorpus\b|\bI\d+[a-c]?\b'
        .'|\b\d+ of \d+\b|\bgenerated\b|lockrot\.dev\'s|\((?:calls|says)\b'
        .'|config-1 unchanged|report-1 unchanged|\b[A-Z][A-Za-z]+(?:\\\\[A-Z][A-Za-z0-9]+)+|::[a-z]+|docs/[a-z-]+\.md'
        .'|`(?!(?:FlagSentence|GateText|MoveText|ScoreText|SignalSentence|NoteSentence|RunGateText|AgeText|GitHub|GitLab)`)[A-Z][a-z]+[A-Z][A-Za-z]*`'
        .'|\b[CW]\d{2}\b'
        .'|\b[QO]\d{1,2}\b'
        .'|[Rr]enamed from|\bare gone\b|\bis gone\b|\bis dropped[:.]|\bwas flagged\b}';

    /**
     * @dataProvider numberedSchemas
     */
    #[DataProvider('numberedSchemas')]
    public function testEveryPropertyIsDescribed(string $path): void
    {
        self::assertSame([], self::undescribed(self::decode($path), '#'));
    }

    /**
     * @dataProvider numberedSchemas
     */
    #[DataProvider('numberedSchemas')]
    public function testNoDescriptionCarriesTextAConsumerCannotUse(string $path): void
    {
        $found = [];
        foreach (self::descriptions(self::decode($path), '#') as $at => $description) {
            if (preg_match(self::BANNED, $description, $match) === 1) {
                $found[] = $at.': "'.$match[0].'" in '.$description;
            }
        }

        self::assertSame([], $found);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function textsAndWhetherTheyAreBanned(): iterable
    {
        yield 'a design section' => ['see §7.6', true];
        yield 'a review round' => ['added in attack round 2', true];
        yield 'an invariant id' => ['holds I21', true];
        yield 'an item id' => ['the C69 note', true];
        yield 'a decision id' => ['as Q7 decided', true];
        yield 'a measured count' => ['79 of 79 findings', true];
        yield 'an internal PHP symbol' => ['read by Legacy\Priority013', true];
        yield 'a static call' => ['`ReleaseBranch::label`', true];
        yield 'a repository doc path' => ['docs/verdicts.md says', true];
        yield 'a backticked class name' => ['`FixFinder` decides', true];
        yield 'draft history' => ['Renamed from `do`.', true];
        yield 'a public grammar name' => ['`FlagSentence` renders it', false];
        yield 'a host name' => ['`GitHub` or `GitLab`', false];
        yield 'a flag and a grade' => ['`abandoned` at grade `high`', false];
        yield 'a document name' => ['report-2 and explain-2', false];
    }

    /**
     * @dataProvider textsAndWhetherTheyAreBanned
     */
    #[DataProvider('textsAndWhetherTheyAreBanned')]
    public function testThePatternTellsUsableTextFromHistory(string $text, bool $banned): void
    {
        self::assertSame($banned, preg_match(self::BANNED, $text) === 1, $text);
    }

    public function testAPropertyWithoutADescriptionIsFound(): void
    {
        $schema = ['properties' => ['a' => ['type' => 'string'], 'b' => ['description' => 'B.'], 'c' => ['$ref' => '#/definitions/c']]];

        self::assertSame(['#.a'], self::undescribed($schema, '#'));
    }

    /** @return iterable<string, array{string}> every numbered file from 2 on */
    public static function numberedSchemas(): iterable
    {
        foreach ([Schemas::REPORT, Schemas::EXPLAIN, Schemas::BASELINE, Schemas::CONFIG] as $document) {
            foreach (Schemas::numbers($document) as $number) {
                if ($number >= 2) {
                    yield $document.'-'.$number => [Schemas::path($document, $number)];
                }
            }
        }
    }

    /** @return array<mixed, mixed> */
    private static function decode(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded, $path);

        return $decoded;
    }

    /**
     * The properties with neither a description nor a reference, outside the `anyOf`, `allOf` and
     * `not` branches, whose properties restate members the object declares.
     *
     * @param mixed $node
     *
     * @return list<string>
     */
    private static function undescribed($node, string $at): array
    {
        if (!\is_array($node)) {
            return [];
        }
        $found = [];
        $properties = $node['properties'] ?? null;
        if (\is_array($properties) && !preg_match('{/(?:anyOf|allOf|not)(?:/|$)}', $at)) {
            foreach ($properties as $name => $property) {
                if (\is_array($property) && !isset($property['description']) && !isset($property['$ref'])) {
                    $found[] = $at.'.'.$name;
                }
            }
        }
        foreach ($node as $key => $child) {
            $found = array_merge($found, self::undescribed($child, $at.'/'.$key));
        }

        return $found;
    }

    /**
     * @param mixed $node
     *
     * @return array<string, string> by JSON pointer
     */
    private static function descriptions($node, string $at): array
    {
        if (!\is_array($node)) {
            return [];
        }
        $found = \is_string($node['description'] ?? null) ? [$at => $node['description']] : [];
        foreach ($node as $key => $child) {
            if ($key !== 'description') {
                $found += self::descriptions($child, $at.'/'.$key);
            }
        }

        return $found;
    }
}
