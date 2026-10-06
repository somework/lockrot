<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use JsonSchema\Validator;
use Lockrot\Json\KnownValues;
use Lockrot\Json\Schemas;

/**
 * Validates a document against one of the published schemas under resources/, as published or
 * against its strict twin.
 *
 * The published schemas keep every object open, so a field the schema does not list is not an
 * error, and describe the sets that grow in minor releases as open strings. The strict twin closes
 * every object that declares its `properties`, so a field the schema does not list fails: a formatter
 * that gains a field the schema never learned, or an older document carrying a field the current
 * schema stopped listing. It also reads every `x-known-values` as the enum it lists
 * ({@see KnownValues::closed()}), so a value outside it fails too: a mistyped reason, or a signal id
 * the schema was never taught.
 *
 * A document is held to the file of the schema number it names (`resources/lockrot-<document>-<number>.schema.json`),
 * never to whichever number is current: {@see schemaFileFor()}.
 *
 * For a {@see \PHPUnit\Framework\TestCase}; shared by the tests that validate what the current
 * formatters write and what earlier releases wrote.
 */
trait ValidatesJsonSchemas
{
    /**
     * @param int|null $number the schema number, for a document that names none itself (a config
     *                         document, or a fragment): {@see schemaFileFor()}
     */
    private function assertValid(string $document, string $json, string $what, bool $strict = false, ?int $number = null): void
    {
        $this->assertValidAgainst(self::schema($document, $json, $number), $json, $what, $strict);
    }

    /** As {@see assertValid()}, against a schema already loaded: an older release's copy, or a part of one. */
    private function assertValidAgainst(object $schema, string $json, string $what, bool $strict = false): void
    {
        $errors = $this->errorsAgainst($schema, $json, $strict);

        self::assertSame([], $errors, $what.($strict ? ' (strict twin)' : '').': '.json_encode($errors, \JSON_PRETTY_PRINT));
    }

    /** @return list<string> */
    private function errors(string $document, string $json, bool $strict, ?int $number = null): array
    {
        return $this->errorsAgainst(self::schema($document, $json, $number), $json, $strict);
    }

    /** @return list<string> each as `<property>: <message>`, `(root)` for the document itself */
    private function errorsAgainst(object $schema, string $json, bool $strict): array
    {
        $data = json_decode($json);
        self::assertNotNull($data, 'valid JSON');
        if ($strict) {
            self::assertInstanceOf(\stdClass::class, $schema);
            $schema = self::strictTwin(KnownValues::closed($schema));
        }

        $validator = new Validator();
        $validator->validate($data, $schema);
        $errors = [];
        foreach ($validator->getErrors() as $error) {
            self::assertIsArray($error);
            $property = $error['property'] ?? null;
            $message = $error['message'] ?? null;
            $errors[] = (\is_string($property) ? ($property === '' ? '(root)' : $property) : '?').': '.(\is_string($message) ? $message : '?');
        }

        return $errors;
    }

    /**
     * Whether a negative fixture is rejected as it expects ({@see NegativeFixtures}): validated
     * against the published schema of its directory's document and number, or against the strict
     * twin when its expectation is strict.
     *
     * @return array{bool, bool, list<string>} rejected as expected, read against the strict twin, the errors
     */
    private function negativeOutcome(string $path): array
    {
        [$document, $number] = NegativeFixtures::documentOf(basename(\dirname($path)));
        [$json, $strict, $error] = NegativeFixtures::read($path);
        $errors = $this->errorsAgainst(self::schemaAt(Schemas::path($document, $number)), $json, $strict);

        return [NegativeFixtures::rejects($errors, $error), $strict, $errors];
    }

    private static function schema(string $document, string $json, ?int $number = null): object
    {
        return self::schemaAt(self::schemaFileFor($document, $json, $number));
    }

    /**
     * The schema file a document follows: the number its `$schema` URL names, else its
     * `lockrot.schema`, else the number given, and never "the current file" — so a document lockrot
     * wrote under one number is held to that number's file while a later number sits beside it. A
     * number given that the document contradicts, or none at all, fails.
     *
     * @param string|null $directory where the numbered files are, resources/ unless given
     */
    private static function schemaFileFor(string $document, string $json, ?int $number = null, ?string $directory = null): string
    {
        $named = self::numberNamedBy($document, json_decode($json));
        if ($named !== null && $number !== null) {
            self::assertSame($number, $named, 'the '.$document.' document names another schema number than the one given');
        }
        $number = $named ?? $number;
        if ($number === null) {
            self::fail('the '.$document.' document names no schema number, and none was given');
        }

        return $directory === null ? Schemas::path($document, $number) : $directory.'/'.Schemas::fileName($document, $number);
    }

    /** @param mixed $decoded */
    private static function numberNamedBy(string $document, $decoded): ?int
    {
        if (!$decoded instanceof \stdClass) {
            return null;
        }
        $vars = get_object_vars($decoded);
        $url = $vars['$schema'] ?? null;
        if (\is_string($url) && preg_match('{^'.preg_quote(Schemas::BASE_URL.$document.'-', '{').'([1-9]\d*)\.json$}', $url, $match) === 1) {
            return (int) $match[1];
        }
        $envelope = $vars['lockrot'] ?? null;
        $schema = $envelope instanceof \stdClass ? (get_object_vars($envelope)['schema'] ?? null) : null;

        return \is_int($schema) ? $schema : null;
    }

    /** A schema file, decoded afresh on every call: the validator is free to annotate what it is given. */
    private static function schemaAt(string $path): object
    {
        $decoded = json_decode((string) file_get_contents($path));
        self::assertIsObject($decoded, $path);

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
