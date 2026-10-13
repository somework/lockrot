<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Json\KnownValues;
use Lockrot\Json\Schemas;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A document that the strict twin accepts, the published schema accepts too, so a test validates a
 * document against the strict twin alone. The twin only adds restrictions. A restriction loosens
 * the schema under a `not`, and in a `oneOf` branch that can overlap another branch, so it must
 * appear in neither. A known value that misses the `pattern` it replaces loosens the node too.
 */
final class StrictTwinNarrowsTest extends TestCase
{
    use ValidatesJsonSchemas;

    public function testTheStrictTwinOfEveryPublishedSchemaOnlyNarrowsIt(): void
    {
        $checked = 0;
        foreach ([Schemas::REPORT, Schemas::EXPLAIN, Schemas::BASELINE, Schemas::CONFIG] as $document) {
            foreach (Schemas::numbers($document) as $number) {
                $schema = self::schemaAt(Schemas::path($document, $number));
                self::assertInstanceOf(\stdClass::class, $schema);
                self::assertSame([], self::widenings($schema), $document.'-'.$number);
                ++$checked;
            }
        }
        self::assertGreaterThanOrEqual(8, $checked, 'the published schemas');
    }

    /** @return iterable<string, array{string, string}> a schema whose strict twin accepts more somewhere, and the path */
    public static function widening(): iterable
    {
        yield 'a closed value under not' => ['{"not": {"type": "string", "pattern": "^a", "x-known-values": ["a"]}}', '#/not'];
        yield 'a closed object under not' => ['{"properties": {"k": {"not": {"type": "object", "properties": {"a": {}}}}}}', '#/properties/k/not'];
        yield 'a closed oneOf branch beside a branch that can overlap it' => ['{"oneOf": [{"type": "object", "properties": {"a": {}}}, {"type": "object"}]}', '#/oneOf/0'];
        yield 'a closed oneOf branch that admits null beside null' => ['{"oneOf": [{"type": ["string", "null"], "pattern": "^a", "x-known-values": ["a"]}, {"type": "null"}]}', '#/oneOf/0'];
        yield 'a known value that misses the pattern' => ['{"type": "string", "pattern": "^a", "x-known-values": ["a", "b"]}', '#: b'];
    }

    /** @dataProvider widening */
    #[DataProvider('widening')]
    public function testTheCheckFindsEachWayTheTwinCanAcceptMore(string $schema, string $at): void
    {
        $decoded = json_decode($schema);
        self::assertInstanceOf(\stdClass::class, $decoded);

        self::assertSame([$at], self::widenings($decoded));
    }

    public function testANullableOneOfMayBeClosed(): void
    {
        $decoded = json_decode('{"oneOf": [{"type": "object", "properties": {"a": {}}}, {"type": "null"}]}');
        self::assertInstanceOf(\stdClass::class, $decoded);

        self::assertSame([], self::widenings($decoded));
    }

    /** @return list<string> each place where the strict twin of the schema accepts what the schema refuses */
    private static function widenings(\stdClass $published): array
    {
        $faults = [];
        self::walk($published, self::strictTwin(KnownValues::closed($published)), '#', $faults);

        return $faults;
    }

    /**
     * @param mixed        $published
     * @param mixed        $strict
     * @param list<string> $faults
     */
    private static function walk($published, $strict, string $path, array &$faults): void
    {
        if (!$published instanceof \stdClass || !$strict instanceof \stdClass) {
            return;
        }
        $vars = get_object_vars($published);
        $known = $vars[KnownValues::KEYWORD] ?? null;
        $pattern = $vars['pattern'] ?? null;
        if (\is_array($known) && \is_string($pattern) && !isset($vars['enum'])) {
            foreach ($known as $value) {
                // As justinrainbow/json-schema delimits a pattern.
                if (\is_string($value) && preg_match('~'.str_replace('~', '\\~', $pattern).'~u', $value) !== 1) {
                    $faults[] = $path.': '.$value;
                }
            }
        }
        foreach ($vars as $keyword => $value) {
            $twin = $strict->{$keyword} ?? null;
            if ($keyword === 'not') {
                if (json_encode($value) !== json_encode($twin)) {
                    $faults[] = $path.'/not';
                }
            } elseif ($keyword === 'oneOf' && \is_array($value) && \is_array($twin)) {
                foreach ($value as $i => $branch) {
                    if (json_encode($branch) !== json_encode($twin[$i] ?? null) && !self::onlyBesideNull($value, (int) $i)) {
                        $faults[] = $path.'/oneOf/'.$i;
                    }
                    self::walk($branch, $twin[$i] ?? null, $path.'/oneOf/'.$i, $faults);
                }
            } elseif (\is_array($value) && \is_array($twin)) {
                foreach ($value as $i => $item) {
                    self::walk($item, $twin[$i] ?? null, $path.'/'.$keyword.'/'.$i, $faults);
                }
            } else {
                self::walk($value, $twin, $path.'/'.$keyword, $faults);
            }
        }
    }

    /**
     * Whether the branch at `$at` admits no null and every other branch admits null alone: no value
     * can match two branches, so a narrowed branch cannot turn a refused value into an accepted one.
     *
     * @param array<mixed> $branches
     */
    private static function onlyBesideNull(array $branches, int $at): bool
    {
        $types = self::types($branches[$at]);
        if ($types === null || \in_array('null', $types, true)) {
            return false;
        }
        foreach ($branches as $i => $branch) {
            if ($i !== $at && self::types($branch) !== ['null']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $node
     *
     * @return list<string>|null the node's `type`, or null when it names none
     */
    private static function types($node): ?array
    {
        if (!$node instanceof \stdClass) {
            return null;
        }
        $vars = get_object_vars($node);
        $type = $vars['type'] ?? null;
        if (\is_string($type)) {
            return [$type];
        }
        if (\is_array($type)) {
            return array_values(array_filter($type, 'is_string'));
        }
        return null;
    }
}
