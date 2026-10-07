<?php

declare(strict_types=1);

namespace Lockrot\Config;

use JsonSchema\Validator;
use Lockrot\Exception\ConfigException;
use Lockrot\Json\KnownValues;
use Lockrot\Json\SchemaPayload;
use Lockrot\Json\Schemas;

/**
 * Validates `extra.lockrot` against resources/lockrot-config-1.schema.json, with the
 * justinrainbow/json-schema validator that Composer bundles.
 *
 * The published schema leaves `format` open, so an editor with an older copy does not flag a format
 * that a later release adds. lockrot reads it strictly ({@see KnownValues::closed()}), so a mistyped
 * `format` is a validation error, exit 2, even when `--format` overrides it.
 * See docs/schema.md#open-sets.
 *
 * @internal
 */
final class ConfigSchema
{
    /** A config document carries no number of its own, so this constant selects the schema file. */
    public const NUMBER = 1;

    private static ?object $schema = null;

    /** @param array<string, mixed> $lockrotExtra `extra.lockrot` of composer.json, not the whole `extra` */
    public static function validate(array $lockrotExtra): void
    {
        // The validator reads JSON objects as PHP objects, and composer.json arrives as arrays.
        $data = SchemaPayload::of($lockrotExtra, 'extra.lockrot');

        $validator = new Validator();
        $validator->validate($data, self::schema());

        if ($validator->isValid()) {
            return;
        }

        $lines = ['extra.lockrot is invalid:'];
        foreach ($validator->getErrors() as $error) {
            // The validator documents every error as {property, message, ...}, so this narrows the
            // type for PHPStan. Skipping a malformed entry hides nothing: the method still throws.
            if (!\is_array($error) || !\is_string($error['property'] ?? null) || !\is_string($error['message'] ?? null)) {
                continue;
            }
            $lines[] = \sprintf('  - %s: %s', $error['property'], $error['message']);
        }

        throw new ConfigException(implode("\n", $lines));
    }

    private static function schema(): object
    {
        if (self::$schema !== null) {
            return self::$schema;
        }

        $path = Schemas::path(Schemas::CONFIG, self::NUMBER);
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigException('Cannot read lockrot config schema from '.$path);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new ConfigException('Cannot read '.$path);
        }

        $decoded = json_decode($contents);
        if (!$decoded instanceof \stdClass) {
            throw new ConfigException($path.' must contain a JSON object');
        }

        return self::$schema = KnownValues::closed($decoded);
    }
}
