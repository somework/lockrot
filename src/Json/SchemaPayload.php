<?php

declare(strict_types=1);

namespace Lockrot\Json;

use Lockrot\Exception\ConfigException;

/**
 * Turns a document read with json_decode(..., true) back into the object form the bundled
 * justinrainbow/json-schema validator reads, for {@see \Lockrot\Config\ConfigSchema} and
 * {@see \Lockrot\Baseline\BaselineSchema} alike.
 *
 * The library's own BaseConstraint::arrayToObjectRecursive() does it with a json_encode() and
 * json_decode() round trip, and checks only the encode. A key starting with a NUL byte is valid JSON
 * that no PHP object can hold, so the decode returned null — and `(object) null`, an empty object,
 * was what got validated. A number too large for a float (1e400, read as INF) failed the encode
 * instead, with a library exception no caller reported as a configuration error. Here the first is
 * a ConfigException naming the key, and INF simply reaches the schema, which rejects it wherever a
 * known key wants an integer or a string.
 *
 * The top level is always an object: `{}` and `[]` both decode to [] with json_decode(..., true),
 * and every caller has already checked that it read an object. Below it, [] stays an array — the
 * round trip read it that way too — and a value that already is an object is passed through as is.
 *
 * @internal
 */
final class SchemaPayload
{
    /**
     * @param array<array-key, mixed> $document
     * @param string                  $subject what the document is, as the error names it:
     *                                         "<subject> is invalid:"
     *
     * @throws ConfigException on a key starting with a NUL byte, naming where it is
     */
    public static function of(array $document, string $subject): object
    {
        return self::toObject($document, '', $subject);
    }

    /** @param array<array-key, mixed> $array */
    private static function toObject(array $array, string $path, string $subject): object
    {
        $object = new \stdClass();
        foreach ($array as $key => $value) {
            $key = (string) $key;
            $keyPath = $path === '' ? $key : $path.'.'.$key;
            if (strpos($key, "\0") === 0) {
                throw new ConfigException(\sprintf(
                    "%s is invalid:\n  - %s: a key starting with a NUL byte cannot be read",
                    $subject,
                    addcslashes($keyPath, "\0..\37")
                ));
            }
            $object->{$key} = self::toJsonValue($value, $keyPath, $subject);
        }

        return $object;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function toJsonValue($value, string $path, string $subject)
    {
        if (!\is_array($value)) {
            return $value;
        }
        if ($value !== [] && !JsonReader::isList($value)) {
            return self::toObject($value, $path, $subject);
        }
        $list = [];
        foreach ($value as $index => $item) {
            $list[] = self::toJsonValue($item, $path.'['.$index.']', $subject);
        }

        return $list;
    }
}
