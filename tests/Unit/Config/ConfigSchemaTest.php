<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Config;

use Lockrot\Config\ConfigSchema;
use Lockrot\Config\UnknownKeys;
use Lockrot\Exception\ConfigException;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\FailOn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigSchemaTest extends TestCase
{
    public function testEmptyArrayIsValid(): void
    {
        ConfigSchema::validate([]);
        $this->addToAssertionCount(1);
    }

    public function testFailOnAcceptsThePrioritiesToo(): void
    {
        foreach (['critical', 'high', 'medium', 'low'] as $priority) {
            ConfigSchema::validate(['fail-on' => $priority]);
        }
        $this->addToAssertionCount(4);
    }

    /** The schema's hand-written enum and the resolver's list are the same list, in both directions. */
    public function testTheSchemaEnumIsExactlyWhatFailOnAccepts(): void
    {
        $schema = json_decode((string) file_get_contents(__DIR__.'/../../../resources/lockrot-config.schema.json'), true);
        self::assertIsArray($schema);
        $properties = $schema['properties'] ?? null;
        self::assertIsArray($properties);
        $failOn = $properties['fail-on'] ?? null;
        self::assertIsArray($failOn);
        self::assertSame(FailOn::allowed(), $failOn['enum'] ?? null);
    }

    public function testFullValidConfigIsValid(): void
    {
        ConfigSchema::validate([
            'fail-on' => 'silent',
            'target-php' => '8.4',
            'format' => 'json',
            'include-dev' => true,
            'install-time' => 'off',
            'install-time-strict' => true,
            'install-time-budget' => 45,
            'baseline' => 'ci/lockrot-baseline.json',
            'release-warn-years' => 2,
            'release-high-years' => 4,
            'push-warn-years' => 2,
            'push-high-years' => 4,
            'ignore' => [
                ['package' => 'acme/legacy', 'reason' => 'internal fork', 'version' => '^1.0', 'expires' => '2027-01-31'],
            ],
        ]);
        $this->addToAssertionCount(1);
    }

    /** @dataProvider schemaFormats */
    #[DataProvider('schemaFormats')]
    public function testEveryDocumentedFormatPassesTheSchema(string $format): void
    {
        ConfigSchema::validate(['format' => $format]);
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function schemaFormats(): iterable
    {
        foreach (['table', 'json', 'github', 'sarif'] as $format) {
            yield $format => [$format];
        }
    }

    public function testUnknownKeysAreAllowed(): void
    {
        ConfigSchema::validate(['totally-unknown-key' => 'value']);
        $this->addToAssertionCount(1);
    }

    /** The reserved names are unknown keys like any other to the schema: accepted, whatever they hold. */
    public function testReservedKeysStillValidate(): void
    {
        ConfigSchema::validate(['extensions' => ['acme/x' => ['k' => 1]], 'x-ci' => 'y']);
        ConfigSchema::validate(['extensions' => 'anything', 'ignore' => [['package' => 'a/b', 'reason' => 'r', 'x-ticket' => 'ACME-1']]]);
        $this->addToAssertionCount(2);
    }

    /**
     * The unknown-key warning knows the settings from a hand-written list; the schema is the
     * contract. Sorted on both sides because the list is kept in alphabetical order, which is what
     * breaks a tie between two equally near keys.
     */
    public function testTheKnownKeysAreExactlyTheSchemaProperties(): void
    {
        $keys = array_keys(self::schemaProperties());
        sort($keys);

        self::assertSame($keys, UnknownKeys::KNOWN);
    }

    public function testTheKnownIgnoreKeysAreExactlyTheIgnoreItemProperties(): void
    {
        $ignore = self::schemaProperties()['ignore'] ?? null;
        self::assertIsArray($ignore);
        $keys = array_keys(JsonPath::arrayAt($ignore, ['items', 'properties']));
        sort($keys);

        self::assertSame($keys, UnknownKeys::KNOWN_IN_IGNORE);
    }

    /** The warning walks one level down, into `ignore` entries: a second nested object would go unchecked. */
    public function testIgnoreIsTheOnlyNestedObjectInTheSchema(): void
    {
        $nested = [];
        foreach (self::schemaProperties() as $name => $property) {
            self::assertIsArray($property);
            $items = $property['items'] ?? [];
            self::assertIsArray($items);
            if (isset($property['properties']) || isset($items['properties'])) {
                $nested[] = $name;
            }
        }

        self::assertSame(['ignore'], $nested);
    }

    /** @return array<array-key, mixed> */
    private static function schemaProperties(): array
    {
        $schema = json_decode((string) file_get_contents(__DIR__.'/../../../resources/lockrot-config.schema.json'), true);
        self::assertIsArray($schema);

        return JsonPath::arrayAt($schema, ['properties']);
    }

    /**
     * A key starting with a NUL byte is valid JSON no PHP object can hold. The library's own
     * array-to-object conversion decoded it to null and validated `(object) null` — an empty object,
     * so every other key went unchecked — and this `fail-on` passed.
     *
     * @param array<string, mixed> $extra
     *
     * @dataProvider keysNoObjectCanHold
     */
    #[DataProvider('keysNoObjectCanHold')]
    public function testAKeyStartingWithANulByteIsAnErrorNamingWhereItIs(array $extra, string $where): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("extra.lockrot is invalid:\n  - ".$where.': a key starting with a NUL byte cannot be read');
        ConfigSchema::validate($extra);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function keysNoObjectCanHold(): iterable
    {
        yield 'at the top' => [['fail-on' => 'dead', "\0k" => 1], '\000k'];
        yield 'inside an ignore entry' => [['ignore' => [['package' => 'a/b', 'reason' => 'x', "\0" => 1]]], 'ignore[0].\000'];
        yield 'inside an unknown key' => [['custom' => ['deep' => ["\0k" => true]]], 'custom.deep.\000k'];
    }

    /** A NUL byte anywhere else in a key is an ordinary character. */
    public function testANulByteInsideAKeyIsAnOrdinaryCharacter(): void
    {
        ConfigSchema::validate(["k\0" => 1, 'ignore' => [['package' => 'a/b', 'reason' => 'x', "x\0y" => 1]]]);
        $this->addToAssertionCount(1);
    }

    /**
     * 1e400 reads as INF, which json_encode() refuses: the library's conversion threw its own
     * exception rather than a configuration error. INF now reaches the schema, which says what is
     * wrong with it wherever an integer is wanted, and leaves it alone under a key it does not know.
     */
    public function testANumberTooLargeForAFloatIsJudgedByTheSchema(): void
    {
        ConfigSchema::validate(['custom' => \INF, 'nested' => ['value' => -\INF]]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('  - release-warn-years: ');
        ConfigSchema::validate(['release-warn-years' => \INF]);
    }

    /**
     * Below the top level an empty array stays an array — `ignore: []` is valid — and a map whose keys
     * are not 0..n-1 is an object, not a list, exactly as json_encode() would have written it.
     */
    public function testNestedValuesKeepTheirJsonShape(): void
    {
        ConfigSchema::validate(['ignore' => []]);
        ConfigSchema::validate(['ignore' => [['package' => 'a/b', 'reason' => 'x', 'extra' => []]]]);
        $this->addToAssertionCount(2);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('  - ignore: ');
        ConfigSchema::validate(['ignore' => [1 => ['package' => 'a/b', 'reason' => 'x']]]);
    }

    /** The top level is an object even when it is empty, and an empty key is an ordinary unknown key. */
    public function testAnEmptyObjectAtTheTopIsStillAnObject(): void
    {
        ConfigSchema::validate([]);
        ConfigSchema::validate(['' => 1]);
        $this->addToAssertionCount(2);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @dataProvider invalidConfigs
     */
    #[DataProvider('invalidConfigs')]
    public function testInvalidPropertyIsReportedWithItsPath(array $extra, string $expectedPropertyPath): void
    {
        try {
            ConfigSchema::validate($extra);
            self::fail('Expected a ConfigException naming '.$expectedPropertyPath);
        } catch (ConfigException $e) {
            self::assertStringStartsWith('extra.lockrot is invalid:', $e->getMessage());
            self::assertStringContainsString('  - '.$expectedPropertyPath.':', $e->getMessage());
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidConfigs(): iterable
    {
        yield 'fail-on not in enum' => [['fail-on' => 'dead'], 'fail-on'];
        yield 'target-php bad pattern' => [['target-php' => 'v8.4'], 'target-php'];
        yield 'format not in enum' => [['format' => 'xml'], 'format'];
        yield 'include-dev wrong type' => [['include-dev' => 'yes'], 'include-dev'];
        // `summary` is not an accepted install-time value.
        yield 'install-time not in enum' => [['install-time' => 'summary'], 'install-time'];
        yield 'install-time-strict wrong type' => [['install-time-strict' => 'yes'], 'install-time-strict'];
        yield 'install-time-budget wrong type' => [['install-time-budget' => '5'], 'install-time-budget'];
        yield 'install-time-budget below minimum' => [['install-time-budget' => 0], 'install-time-budget'];
        yield 'install-time-budget above maximum' => [['install-time-budget' => 121], 'install-time-budget'];
        yield 'baseline wrong type' => [['baseline' => true], 'baseline'];
        yield 'baseline empty string' => [['baseline' => ''], 'baseline'];
        yield 'release-warn-years digit string' => [['release-warn-years' => '4'], 'release-warn-years'];
        yield 'release-warn-years below minimum' => [['release-warn-years' => 0], 'release-warn-years'];
        yield 'release-high-years wrong type' => [['release-high-years' => 'many'], 'release-high-years'];
        yield 'push-warn-years below minimum' => [['push-warn-years' => 0], 'push-warn-years'];
        yield 'push-high-years wrong type' => [['push-high-years' => 'many'], 'push-high-years'];
        yield 'ignore not an array' => [['ignore' => 'acme/legacy'], 'ignore'];
        yield 'ignore entry not an object' => [['ignore' => ['acme/legacy']], 'ignore[0]'];
        yield 'ignore entry missing reason' => [['ignore' => [['package' => 'acme/legacy']]], 'ignore[0].reason'];
        yield 'ignore entry missing package' => [['ignore' => [['reason' => 'because']]], 'ignore[0].package'];
        yield 'ignore entry empty package' => [['ignore' => [['package' => '', 'reason' => 'because']]], 'ignore[0].package'];
        yield 'ignore entry empty reason' => [['ignore' => [['package' => 'a/b', 'reason' => '']]], 'ignore[0].reason'];
        yield 'ignore entry bad expires pattern' => [['ignore' => [['package' => 'a/b', 'reason' => 'x', 'expires' => 'soon']]], 'ignore[0].expires'];
    }
}
