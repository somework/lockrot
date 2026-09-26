<?php

declare(strict_types=1);

namespace Lockrot\Json;

/**
 * The strict reading of a published schema: every open set read as the enum it lists.
 *
 * The values that grow in minor releases — signal ids, S10's checks and reasons, S8's floor source,
 * the configuration's `format` — are open strings in resources/*.schema.json: a `pattern`, so a copy
 * a consumer took earlier accepts a value a later release adds, and an `x-known-values` list of the
 * values this release writes. Draft-04 validators ignore a keyword they do not know. Read strictly,
 * the list is the `enum` and the pattern goes, so exactly the known values pass and a mistyped one
 * fails with the enum's message, one line, as it did while the published files spelled the enum
 * out. {@see \Lockrot\Config\ConfigSchema} validates `extra.lockrot` this way, and the tests hold
 * lockrot's own documents to it.
 *
 * Only places where a schema sits are read: an object that is a value (a `default`, an `enum` member)
 * keeps whatever it holds. A node that already has an `enum` keeps it.
 *
 * @internal
 */
final class KnownValues
{
    public const KEYWORD = 'x-known-values';

    /** Keywords whose value is one schema. */
    private const ONE = ['additionalItems', 'additionalProperties', 'items', 'not'];

    /** Keywords whose value is a list of schemas. */
    private const LIST = ['allOf', 'anyOf', 'items', 'oneOf'];

    /** Keywords whose value maps names to schemas. */
    private const MAP = ['definitions', 'dependencies', 'patternProperties', 'properties'];

    /** A copy of the schema read strictly; the schema given is left as it was. */
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

        return $copy;
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
