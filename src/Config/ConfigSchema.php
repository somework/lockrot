<?php

declare(strict_types=1);

namespace Lockrot\Config;

use JsonSchema\Constraints\BaseConstraint;
use JsonSchema\Validator;
use Lockrot\Exception\ConfigException;

/**
 * Validates the shape of composer.json's extra.lockrot against
 * resources/lockrot-config.schema.json, using Composer's own bundled justinrainbow/json-schema
 * validator.
 */
final class ConfigSchema
{
    private static ?object $schema = null;

    /** @param array<string, mixed> $lockrotExtra contents of composer.json extra.lockrot */
    public static function validate(array $lockrotExtra): void
    {
        // json_decode(..., true) turns both an empty JSON object ({}) and an empty JSON array ([]) into
        // [], so by the time an empty extra.lockrot reaches here the "this was an object" information is
        // gone and arrayToObjectRecursive() cannot restore it. A bare stdClass hands the validator an
        // explicit object for this one case where the type would otherwise be ambiguous.
        $data = $lockrotExtra === [] ? new \stdClass() : BaseConstraint::arrayToObjectRecursive($lockrotExtra);

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
