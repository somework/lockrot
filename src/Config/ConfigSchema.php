<?php

declare(strict_types=1);

namespace Lockrot\Config;

use JsonSchema\Validator;
use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;

/**
 * Validates the shape of composer.json's extra.lockrot against
 * resources/lockrot-config.schema.json, using Composer's own bundled justinrainbow/json-schema
 * validator.
 *
 * @internal
 */
final class ConfigSchema
{
    private static ?object $schema = null;

    /** @param array<string, mixed> $lockrotExtra contents of composer.json extra.lockrot */
    public static function validate(array $lockrotExtra): void
    {
        $data = self::toObject($lockrotExtra, '');

        $validator = new Validator();
        $validator->validate($data, self::schema());

        if ($validator->isValid()) {
            return;
        }

        $lines = ['extra.lockrot is invalid:'];
        foreach ($validator->getErrors() as $error) {
            // Every error the validator produces is documented as {property, message, ...}, so this
            // narrows for PHPStan rather than guards against a real gap: skipping a malformed entry
            // here never hides the failure itself, since the method still throws below either way,
            // at worst with a shorter list of lines than errors reported.
            if (!\is_array($error) || !\is_string($error['property'] ?? null) || !\is_string($error['message'] ?? null)) {
                continue;
            }
            $lines[] = \sprintf('  - %s: %s', $error['property'], $error['message']);
        }

        throw new ConfigException(implode("\n", $lines));
    }

    /**
     * The validator reads JSON objects as PHP objects, and composer.json arrives as arrays, so the
     * array form is turned back into the object form here, one value at a time.
     *
     * The library's own BaseConstraint::arrayToObjectRecursive() does it with a json_encode() and
     * json_decode() round trip, and checks only the encode. A key starting with a NUL byte is valid
     * JSON that no PHP object can hold, so the decode returned null — and `(object) null`, an empty
     * object, passed the schema: the whole of extra.lockrot went unvalidated, and a gate configured
     * beside such a key ran with fail-on `none`. A number too large for a float (1e400, read as INF)
     * failed the encode instead, with a library exception the command did not report as a
     * configuration error. Here the first is a ConfigException naming the key, and INF simply
     * reaches the schema, which rejects it wherever a known key wants an integer.
     *
     * The top level is always an object: `{}` and `[]` both decode to [] with json_decode(..., true),
     * and extra.lockrot has already been checked to be an object. Below it, [] stays an array —
     * the round trip read it that way too, and `ignore: []` has to stay valid.
     *
     * @param array<array-key, mixed> $array
     */
    private static function toObject(array $array, string $path): object
    {
        $object = new \stdClass();
        foreach ($array as $key => $value) {
            $key = (string) $key;
            $keyPath = $path === '' ? $key : $path.'.'.$key;
            if (strpos($key, "\0") === 0) {
                throw new ConfigException(\sprintf(
                    "extra.lockrot is invalid:\n  - %s: a key starting with a NUL byte cannot be read",
                    addcslashes($keyPath, "\0..\37")
                ));
            }
            $object->{$key} = self::toJsonValue($value, $keyPath);
        }

        return $object;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function toJsonValue($value, string $path)
    {
        if (!\is_array($value)) {
            return $value;
        }
        if ($value !== [] && !JsonReader::isList($value)) {
            return self::toObject($value, $path);
        }
        $list = [];
        foreach ($value as $index => $item) {
            $list[] = self::toJsonValue($item, $path.'['.$index.']');
        }

        return $list;
    }

    private static function schema(): object
    {
        if (self::$schema !== null) {
            return self::$schema;
        }

        $path = __DIR__.'/../../resources/lockrot-config.schema.json';
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigException('Cannot read lockrot config schema from '.$path);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new ConfigException('Cannot read '.$path);
        }

        $decoded = json_decode($contents);
        if (!\is_object($decoded)) {
            throw new ConfigException($path.' must contain a JSON object');
        }

        self::$schema = $decoded;

        return $decoded;
    }
}
