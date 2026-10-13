<?php

declare(strict_types=1);

namespace Lockrot\Json;

use JsonSchema\Validator;
use Lockrot\Exception\ConfigException;

/**
 * The error of a document that a schema refuses. A described `oneOf`, `anyOf` or `not` is a rule
 * group, and the validator reports a broken group as the errors of each branch. The message names
 * each broken group by its property path and its `description`. The branch errors are details,
 * which only `-v` prints. A refusal that breaks no group gives the validator's errors as the message.
 *
 * @internal
 */
final class SchemaErrors
{
    private const MARKER = 'lockrot-rule-group-';

    /** @var array<int, array{0: object, 1: list<string>}> by the schema's object id: the marked schema and each marker's description */
    private static array $marked = [];

    /** @param mixed $payload the document as the validator reads it */
    public static function exception(string $title, object $schema, $payload, Validator $validator): ConfigException
    {
        [$marked, $descriptions] = self::marked($schema);
        $groups = [];
        $again = new Validator();
        $again->validate($payload, $marked);
        foreach (self::errors($again) as [$property, $message]) {
            if (preg_match('{'.self::MARKER.'(\d+)}', $message, $match) === 1) {
                $groups[$property] = \sprintf('  - %s: %s', $property, $descriptions[(int) $match[1]]);
            }
        }
        $lines = [];
        $details = [];
        foreach (self::errors($validator) as [$property, $message]) {
            $line = \sprintf('  - %s: %s', $property, $message);
            if (self::inGroup($property, array_keys($groups))) {
                $details[] = $line;
            } else {
                $lines[] = $line;
            }
        }

        return ConfigException::withDetails(implode("\n", array_merge([$title], array_values($groups), $lines)), $details);
    }

    /** @return list<array{0: string, 1: string}> each error's property path and message */
    private static function errors(Validator $validator): array
    {
        $out = [];
        foreach ($validator->getErrors() as $error) {
            // The validator documents every error as {property, message, ...}, so this narrows the
            // type for PHPStan. Skipping a malformed entry hides nothing: the caller still throws.
            if (\is_array($error) && \is_string($error['property'] ?? null) && \is_string($error['message'] ?? null)) {
                $out[] = [$error['property'], $error['message']];
            }
        }

        return $out;
    }

    /**
     * Whether the error's path is a broken group's path or below it: a branch error of that group.
     *
     * @param list<string> $groups
     */
    private static function inGroup(string $property, array $groups): bool
    {
        foreach ($groups as $group) {
            if ($group === '' || $property === $group || strpos($property, $group.'.') === 0 || strpos($property, $group.'[') === 0) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: object, 1: list<string>} */
    private static function marked(object $schema): array
    {
        $id = spl_object_id($schema);
        if (!isset(self::$marked[$id])) {
            $descriptions = [];
            $marked = self::mark($schema, $descriptions);
            self::$marked[$id] = [\is_object($marked) ? $marked : new \stdClass(), $descriptions];
        }

        return self::$marked[$id];
    }

    /**
     * The node with each described rule group beside a marker that the validator reports exactly
     * when the group fails.
     *
     * @param mixed        $node
     * @param list<string> $descriptions
     *
     * @return mixed
     */
    private static function mark($node, array &$descriptions)
    {
        if (\is_array($node)) {
            return array_map(static fn ($child) => self::mark($child, $descriptions), $node);
        }
        if (!$node instanceof \stdClass) {
            return $node;
        }
        // A copy: the schema object stays as the validator of the first pass reads it.
        $copy = new \stdClass();
        foreach (get_object_vars($node) as $key => $child) {
            $copy->{$key} = self::mark($child, $descriptions);
        }
        $node = $copy;
        if (!\is_string($node->description ?? null) || (!isset($node->oneOf) && !isset($node->anyOf) && !isset($node->not))) {
            return $node;
        }
        // Only the group moves beside the marker: an error of a property next to it is no break of the group.
        $group = new \stdClass();
        foreach (['oneOf', 'anyOf', 'not'] as $keyword) {
            if (isset($node->{$keyword})) {
                $group->{$keyword} = $node->{$keyword};
                unset($node->{$keyword});
            }
        }
        $descriptions[] = $node->description;
        $node->allOf = array_merge(\is_array($node->allOf ?? null) ? $node->allOf : [], [(object) ['anyOf' => [$group, (object) ['enum' => [self::MARKER.(\count($descriptions) - 1)]]]]]);

        return $node;
    }
}
