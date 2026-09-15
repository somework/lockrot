<?php

declare(strict_types=1);

namespace Lockrot\Baseline;

use JsonSchema\Constraints\BaseConstraint;
use JsonSchema\Validator;
use Lockrot\Exception\ConfigException;

/**
 * Validates the shape of a baseline file against resources/lockrot-baseline.schema.json using
 * Composer's own bundled justinrainbow/json-schema validator — the same mechanism, and the same
 * draft-04 schema style, as {@see \Lockrot\Config\ConfigSchema} uses for extra.lockrot.
 *
 * A baseline lockrot cannot read is never treated as "no baseline": it is a configuration error, so
 * CI cannot silently start failing — or silently stop failing — on a file nobody noticed was damaged.
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

        $lines = ['baseline file is invalid:'];
        foreach ($validator->getErrors() as $error) {
            // Every error the validator produces is documented as {property, message, ...}, so this
            // narrows for PHPStan rather than guards against a real gap: skipping a malformed entry
            // here never hides the failure itself, since the method still throws below either way.
            if (!\is_array($error) || !\is_string($error['property'] ?? null) || !\is_string($error['message'] ?? null)) {
                continue;
            }
            $lines[] = \sprintf('  - %s: %s', $error['property'], $error['message']);
        }

        throw new ConfigException(implode("\n", $lines));
    }

    /**
     * json_decode(..., true) turns both an empty JSON object ({}) and an empty JSON array ([]) into
     * [], so a baseline with no findings at all arrives here with the "this was an object"
     * information already gone. A bare stdClass restores it for that one case; every other value is
     * left exactly as read, so a `findings` that really was a JSON array is still rejected below.
     *
     * @param array<string, mixed> $baseline
     */
    private static function payload(array $baseline): object
    {
        if (($baseline['findings'] ?? null) === []) {
            $baseline['findings'] = new \stdClass();
        }

        return BaseConstraint::arrayToObjectRecursive($baseline);
    }

    private static function schema(): object
    {
        if (self::$schema !== null) {
            return self::$schema;
        }

        $path = __DIR__.'/../../resources/lockrot-baseline.schema.json';
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

        self::$schema = $decoded;

        return $decoded;
    }
}
