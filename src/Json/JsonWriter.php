<?php

declare(strict_types=1);

namespace Lockrot\Json;

/**
 * Every JSON document lockrot writes goes through here, so a float prints in its shortest form
 * whatever `serialize_precision` the process inherited: `6.9`, never `6.9000000000000004`. The
 * setting is pinned to -1 for this one call and restored after it.
 *
 * @internal
 */
final class JsonWriter
{
    /**
     * @param mixed $value
     * @param int   $flags the `JSON_*` flags of {@see json_encode()}
     *
     * @return ?string null when the value cannot be encoded, and {@see json_last_error_msg()} says why
     */
    public static function encode($value, int $flags): ?string
    {
        $previous = \ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            $json = json_encode($value, $flags);
        } finally {
            if ($previous !== false) {
                ini_set('serialize_precision', $previous);
            }
        }

        return $json === false ? null : $json;
    }
}
