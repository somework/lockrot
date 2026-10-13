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

    /** @param mixed $payload the document as the validator reads it */
    public static function exception(string $title, object $schema, $payload, Validator $validator): ConfigException
    {
        $descriptions = [];
        $marked = self::mark($schema, $descriptions);
        $groups = [];
        $again = new Validator();
        $again->validate($payload, \is_object($marked) ? $marked : new \stdClass());
        foreach (self::errors($again) as [$property, $message]) {
            if (preg_match('{'.self::MARKER.'\d+}', $message, $match) === 1) {
                $groups[$property] = \sprintf('  - %s: %s', $property, $descriptions[$match[0]]);
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

        return ConfigException::withDetails(implode("\n", array_merge([$title], $groups, $lines)), $details);
    }

    /** @return list<array{0: string, 1: string}> each error's property path and message */
    private static function errors(Validator $validator): array
    {
        $out = [];
        foreach ($validator->getErrors() as $error) {
            $out[] = [self::text($error, 'property'), self::text($error, 'message')];
        }

        return $out;
    }

    /**
     * The validator documents every error as {property, message, ...}: this narrows the type for
     * PHPStan.
     *
     * @param mixed $error
     */
    private static function text($error, string $key): string
    {
        $value = \is_array($error) ? ($error[$key] ?? null) : null;

        return \is_string($value) ? $value : '';
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

    /**
     * The node with each described rule group beside a marker that the validator reports exactly
     * when the group fails.
     *
     * @param mixed                 $node
     * @param array<string, string> $descriptions by marker
     *
     * @return mixed
     */
    private static function mark($node, array &$descriptions)
    {
        if (\is_array($node)) {
            // A loop, not array_map(): an arrow function would add to a copy of $descriptions.
            $out = [];
            foreach ($node as $key => $child) {
                $out[$key] = self::mark($child, $descriptions);
            }

            return $out;
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
        $marker = self::MARKER.\count($descriptions);
        $descriptions[$marker] = $node->description;
        $node->allOf = array_merge(\is_array($node->allOf ?? null) ? $node->allOf : [], [(object) ['anyOf' => [$group, (object) ['enum' => [$marker]]]]]);

        return $node;
    }
}
