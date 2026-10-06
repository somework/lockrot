<?php

declare(strict_types=1);

namespace Lockrot\Baseline;

use JsonSchema\Validator;
use Lockrot\Exception\ConfigException;
use Lockrot\Json\SchemaPayload;
use Lockrot\Json\Schemas;

/**
 * Validates a baseline file against resources/lockrot-baseline-<number>.schema.json, where the
 * number is its `lockrot.schema`. The validator is the justinrainbow/json-schema one that Composer
 * bundles, as in {@see \Lockrot\Config\ConfigSchema}.
 *
 * An unreadable baseline is a configuration error, never an absent baseline: docs/baseline.md,
 * "When lockrot cannot read the file".
 *
 * @internal
 */
final class BaselineSchema
{
    /** @var array<int, object> by schema number */
    private static array $schemas = [];

    /** @param array<string, mixed> $baseline the decoded baseline document */
    public static function validate(array $baseline): void
    {
        // Assigned to a variable first: Validator::validate() takes its first argument by reference.
        $payload = self::payload($baseline);

        $validator = new Validator();
        $validator->validate($payload, self::schema(self::numberOf($baseline)));

        if ($validator->isValid()) {
            return;
        }

        $lines = ['baseline file is invalid:'];
        foreach ($validator->getErrors() as $error) {
            // Narrows the error shape for PHPStan. Skipping a malformed entry cannot hide the
            // failure: the method throws either way.
            if (!\is_array($error) || !\is_string($error['property'] ?? null) || !\is_string($error['message'] ?? null)) {
                continue;
            }
            $lines[] = \sprintf('  - %s: %s', $error['property'], $error['message']);
        }

        throw new ConfigException(implode("\n", $lines));
    }

    /**
     * `json_decode(..., true)` turns `{}` and `[]` into `[]`, so an empty `findings` object needs a
     * `stdClass` to stay an object. Every other value stays as read, so a real JSON array is rejected.
     *
     * Do not replace {@see SchemaPayload} with the library's JSON round trip: it validates a key
     * that starts with a NUL byte as an empty object and throws on a number too large for a float.
     *
     * @param array<string, mixed> $baseline
     */
    private static function payload(array $baseline): object
    {
        if (($baseline['findings'] ?? null) === []) {
            $baseline['findings'] = new \stdClass();
        }

        return SchemaPayload::of($baseline, 'baseline file');
    }

    /**
     * A `lockrot.schema` value without a shipped schema (an unknown number, a string, a missing key)
     * falls back to Baseline::SCHEMA, so that schema refuses it.
     *
     * @param array<string, mixed> $baseline
     */
    private static function numberOf(array $baseline): int
    {
        $envelope = $baseline['lockrot'] ?? null;
        $number = \is_array($envelope) ? ($envelope['schema'] ?? null) : null;

        return \in_array($number, Schemas::numbers(Schemas::BASELINE), true) ? $number : Baseline::SCHEMA;
    }

    private static function schema(int $number): object
    {
        if (isset(self::$schemas[$number])) {
            return self::$schemas[$number];
        }

        $path = Schemas::path(Schemas::BASELINE, $number);
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigException('Cannot read lockrot baseline schema from '.$path);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new ConfigException('Cannot read '.$path);
        }

        $decoded = json_decode($contents);
        if (!\is_object($decoded)) {
            throw new ConfigException($path.' must contain a JSON object');
        }

        self::$schemas[$number] = $decoded;

        return $decoded;
    }
}
