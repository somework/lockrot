<?php

declare(strict_types=1);

namespace Lockrot\Baseline;

use JsonSchema\Validator;
use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;
use Lockrot\Json\SchemaErrors;
use Lockrot\Json\SchemaPayload;
use Lockrot\Json\Schemas;

/**
 * Validates a baseline file against resources/lockrot-baseline-2.schema.json, with the
 * justinrainbow/json-schema validator that Composer bundles, as {@see \Lockrot\Config\ConfigSchema}
 * does. BaselineFile refuses a baseline-1 file before it gets here. A file that names another
 * number, or none, fails against baseline-2 with the schema error.
 *
 * An unreadable baseline is a configuration error, never an absent baseline: docs/baseline.md,
 * "When lockrot cannot read the file".
 *
 * @internal
 */
final class BaselineSchema
{
    private static ?object $schema = null;

    /** @param array<string, mixed> $baseline the decoded baseline document */
    public static function validate(array $baseline): void
    {
        // Assigned to a variable first: Validator::validate() takes its first argument by reference.
        $payload = self::payload($baseline);

        $validator = new Validator();
        $validator->validate($payload, self::schema());

        if ($validator->isValid()) {
            return;
        }

        throw SchemaErrors::exception('baseline file is invalid:', self::schema(), $payload, $validator);
    }

    /**
     * `json_decode(..., true)` turns `{}` into `[]`, and an advisory map keyed `"0"`, `"1"` into a
     * list. So an empty `findings` and every non-empty `advisories` map become objects again. Every
     * other value stays as read, so a real JSON array is rejected.
     *
     * Do not replace {@see SchemaPayload} with the library's JSON round trip. That round trip
     * validates a key that starts with a NUL byte as an empty object, and throws on a number too
     * large for a float.
     *
     * @param array<string, mixed> $baseline
     */
    private static function payload(array $baseline): object
    {
        $findings = $baseline['findings'] ?? null;
        if ($findings === []) {
            $baseline['findings'] = new \stdClass();
        } elseif (\is_array($findings)) {
            foreach ($findings as $package => $entry) {
                $advisories = \is_array($entry) ? ($entry['advisories'] ?? null) : null;
                if (\is_array($advisories) && $advisories !== [] && JsonReader::isList($advisories)) {
                    $entry['advisories'] = SchemaPayload::of($advisories, 'baseline file');
                    $findings[$package] = $entry;
                }
            }
            $baseline['findings'] = $findings;
        }

        return SchemaPayload::of($baseline, 'baseline file');
    }

    private static function schema(): object
    {
        if (self::$schema !== null) {
            return self::$schema;
        }

        $path = Schemas::path(Schemas::BASELINE, Baseline::SCHEMA);
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

        return self::$schema = $decoded;
    }
}
