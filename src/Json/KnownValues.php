<?php

declare(strict_types=1);

namespace Lockrot\Json;

/**
 * The strict reading of a published schema (docs/schema.md#open-sets): each `x-known-values` list
 * becomes the `enum` and its `pattern` goes. An open map is `patternProperties` plus an
 * `x-known-keys` list: the known keys become its only properties, each typed by every regex it
 * matches, else by `additionalProperties`. A node that already has an `enum` or `properties` keeps
 * it. An object that is a value (a `default`, an `enum` member) is not read as a schema.
 *
 * @internal
 */
final class KnownValues
{
    public const KEYWORD = 'x-known-values';

    public const KEYS = 'x-known-keys';

    /** Keywords whose value is one schema. */
    private const ONE = ['additionalItems', 'additionalProperties', 'items', 'not'];

    /** Keywords whose value is a list of schemas. */
    private const LIST = ['allOf', 'anyOf', 'items', 'oneOf'];

    /** Keywords whose value maps names to schemas. */
    private const MAP = ['definitions', 'dependencies', 'patternProperties', 'properties'];

    /** A copy of the schema read strictly. The schema given stays unchanged. */
    public static function closed(\stdClass $schema): \stdClass
    {
        $copy = clone $schema;
        foreach (get_object_vars($copy) as $keyword => $value) {
            if ($value instanceof \stdClass && \in_array($keyword, self::ONE, true)) {
                $copy->{$keyword} = self::closed($value);
            } elseif ($value instanceof \stdClass && \in_array($keyword, self::MAP, true)) {
                $copy->{$keyword} = self::closedMembers($value);
            } elseif (\is_array($value) && \in_array($keyword, self::LIST, true)) {
                $copy->{$keyword} = array_map(static fn ($item) => $item instanceof \stdClass ? self::closed($item) : $item, $value);
            }
        }

        $known = $copy->{self::KEYWORD} ?? null;
        if (\is_array($known) && !property_exists($copy, 'enum')) {
            $copy->enum = $known;
            unset($copy->pattern);
        }

        $keys = $copy->{self::KEYS} ?? null;
        if (\is_array($keys) && !property_exists($copy, 'properties')) {
            $copy->properties = self::knownKeys($copy, $keys);
            unset($copy->patternProperties);
            $copy->additionalProperties = false;
        }

        return $copy;
    }

    /**
     * Each known key of a map with the schema a validator holds it to: that of every
     * `patternProperties` regex it matches, else `additionalProperties`. The result omits a key that
     * no schema admits.
     *
     * @param array<mixed> $keys
     */
    private static function knownKeys(\stdClass $map, array $keys): \stdClass
    {
        $patterns = ($map->patternProperties ?? null) instanceof \stdClass ? get_object_vars($map->patternProperties) : [];
        $others = $map->additionalProperties ?? true;
        $properties = new \stdClass();
        foreach ($keys as $key) {
            if (!\is_string($key)) {
                continue;
            }
            $schemas = [];
            foreach ($patterns as $regex => $schema) {
                // As justinrainbow/json-schema delimits a pattern.
                if (preg_match('~'.str_replace('~', '\\~', (string) $regex).'~u', $key) === 1) {
                    $schemas[] = $schema;
                }
            }
            if ($schemas === [] && $others !== false) {
                $schemas[] = $others === true ? new \stdClass() : $others;
            }
            if ($schemas !== []) {
                $properties->{$key} = \count($schemas) === 1 ? $schemas[0] : (object) ['allOf' => $schemas];
            }
        }

        return $properties;
    }

    private static function closedMembers(\stdClass $map): \stdClass
    {
        $copy = new \stdClass();
        foreach (get_object_vars($map) as $name => $member) {
            $copy->{$name} = $member instanceof \stdClass ? self::closed($member) : $member;
        }

        return $copy;
    }
}
