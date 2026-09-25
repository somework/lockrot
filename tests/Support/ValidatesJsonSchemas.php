<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use JsonSchema\Validator;
use Lockrot\Json\Schemas;

/**
 * Validates a document against one of the published schemas under resources/, as published or
 * against its strict twin.
 *
 * The published schemas keep every object open, so a field the schema does not list is not an
 * error. The strict twin closes every object that declares its `properties`, so a field the schema
 * does not list fails: a formatter that gains a field the schema never learned, or an older document
 * carrying a field the current schema stopped listing.
 *
 * For a {@see \PHPUnit\Framework\TestCase}; shared by the tests that validate what the current
 * formatters write and what earlier releases wrote.
 */
trait ValidatesJsonSchemas
{
    private function assertValid(string $document, string $json, string $what, bool $strict = false): void
    {
        $errors = $this->errors($document, $json, $strict);

        self::assertSame([], $errors, $what.($strict ? ' (strict twin)' : '').': '.json_encode($errors, \JSON_PRETTY_PRINT));
    }

    /** @return list<string> */
    private function errors(string $document, string $json, bool $strict): array
    {
        $data = json_decode($json);
        self::assertNotNull($data, 'valid JSON');
        $schema = self::schema($document);
        if ($strict) {
            self::assertInstanceOf(\stdClass::class, $schema);
            $schema = self::strictTwin($schema);
        }

        $validator = new Validator();
        $validator->validate($data, $schema);
        $errors = [];
        foreach ($validator->getErrors() as $error) {
            self::assertIsArray($error);
            $property = $error['property'] ?? null;
            $message = $error['message'] ?? null;
            $errors[] = (\is_string($property) ? $property : '?').': '.(\is_string($message) ? $message : '?');
        }

        return $errors;
    }

    private static function schema(string $document): object
    {
        $decoded = json_decode((string) file_get_contents(Schemas::path($document)));
        self::assertIsObject($decoded, $document);

        return $decoded;
    }

    /**
     * The same schema with `additionalProperties: false` on every node that declares `properties`
     * and leaves the question open. Nodes that only say `type: object` (a signal's generic `data`)
     * and maps that already say what their members are (a baseline's `findings`) are left alone;
     * so are the `anyOf` branches, which have no `type` of their own.
     */
    private static function strictTwin(\stdClass $node): \stdClass
    {
        $copy = clone $node;
        foreach (get_object_vars($copy) as $key => $value) {
            if ($value instanceof \stdClass) {
                $copy->{$key} = self::strictTwin($value);
            } elseif (\is_array($value)) {
                $copy->{$key} = array_map(static fn ($item) => $item instanceof \stdClass ? self::strictTwin($item) : $item, $value);
            }
        }
        $vars = get_object_vars($copy);
        if (($vars['type'] ?? null) === 'object' && isset($vars['properties']) && !\array_key_exists('additionalProperties', $vars)) {
            $copy->additionalProperties = false;
        }

        return $copy;
    }
}
