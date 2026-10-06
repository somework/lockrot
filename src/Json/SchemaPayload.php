<?php

declare(strict_types=1);

namespace Lockrot\Json;

use Lockrot\Exception\ConfigException;

/**
 * Turns a document read with `json_decode(..., true)` into the object form that the bundled
 * justinrainbow/json-schema validator reads. The library's own round trip through `json_encode()`
 * and `json_decode()` hides a key that starts with a NUL byte (the decode returns null) and throws
 * on a number too large for a float. Here the key is a ConfigException, and the number (INF)
 * reaches the schema, which rejects it. The top level always becomes an object, so a caller must
 * check first that it read an object. Below it, `[]` stays an array and an object passes through
 * unchanged.
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
     * @throws ConfigException on a key that starts with a NUL byte. The message names where the key is.
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
