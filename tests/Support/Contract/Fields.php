<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support\Contract;

/**
 * Typed reads of a decoded document for {@see ReferenceText}. A key that is not there throws and
 * names it, so a renderer that reads a field outside its inputs fails on that field.
 */
final class Fields
{
    /**
     * The document with only the listed paths: dotted keys, `[]` for each item of a list.
     *
     * @param array<mixed, mixed> $document
     * @param list<string>        $paths
     *
     * @return array<mixed, mixed>
     */
    public static function only(array $document, array $paths): array
    {
        $kept = [];
        foreach ($paths as $path) {
            $kept = self::merge($kept, self::path($document, explode('.', $path)));
        }

        return $kept;
    }

    /**
     * @param mixed $data
     *
     * @return array<mixed, mixed>
     */
    public static function object($data, string $key): array
    {
        $value = self::value($data, $key);
        if (!\is_array($value)) {
            throw new \UnexpectedValueException($key.' is not an object');
        }

        return $value;
    }

    /**
     * @param mixed $data
     *
     * @return array<mixed, mixed>|null
     */
    public static function nullableObject($data, string $key): ?array
    {
        $value = self::value($data, $key);

        return $value === null ? null : self::object($data, $key);
    }

    /**
     * @param mixed $data
     *
     * @return list<mixed>
     */
    public static function list($data, string $key): array
    {
        $value = self::value($data, $key);
        if (!\is_array($value) || ($value !== [] && array_keys($value) !== range(0, \count($value) - 1))) {
            throw new \UnexpectedValueException($key.' is not a list');
        }

        return $value;
    }

    /**
     * @param mixed $data
     *
     * @return list<mixed>|null
     */
    public static function nullableList($data, string $key): ?array
    {
        return self::value($data, $key) === null ? null : self::list($data, $key);
    }

    /** @param mixed $data */
    public static function string($data, string $key): string
    {
        $value = self::value($data, $key);
        if (!\is_string($value)) {
            throw new \UnexpectedValueException($key.' is not a string');
        }

        return $value;
    }

    /** @param mixed $data */
    public static function nullableString($data, string $key): ?string
    {
        return self::value($data, $key) === null ? null : self::string($data, $key);
    }

    /** @param mixed $data */
    public static function int($data, string $key): int
    {
        $value = self::value($data, $key);
        if (!\is_int($value)) {
            throw new \UnexpectedValueException($key.' is not an integer');
        }

        return $value;
    }

    /** @param mixed $data */
    public static function number($data, string $key): float
    {
        $value = self::value($data, $key);
        if (!\is_int($value) && !\is_float($value)) {
            throw new \UnexpectedValueException($key.' is not a number');
        }

        return (float) $value;
    }

    /** @param mixed $data */
    public static function bool($data, string $key): bool
    {
        $value = self::value($data, $key);
        if (!\is_bool($value)) {
            throw new \UnexpectedValueException($key.' is not a boolean');
        }

        return $value;
    }

    /**
     * @param mixed $data
     *
     * @return mixed
     */
    private static function value($data, string $key)
    {
        if (!\is_array($data) || !\array_key_exists($key, $data)) {
            throw new MissingField($key);
        }

        return $data[$key];
    }

    /**
     * @param mixed        $node
     * @param list<string> $keys
     *
     * @return mixed the node with only the path below it, or a list of such nodes for `[]`
     */
    private static function path($node, array $keys)
    {
        if ($keys === []) {
            return $node;
        }
        $key = $keys[0];
        $rest = \array_slice($keys, 1);
        $list = substr($key, -2) === '[]';
        $name = $list ? substr($key, 0, -2) : $key;
        if (!\is_array($node) || !\array_key_exists($name, $node)) {
            return [];
        }
        $child = $node[$name];
        if ($list && \is_array($child)) {
            return [$name => array_map(static fn ($item) => self::path($item, $rest), $child)];
        }

        return [$name => self::path($child, $rest)];
    }

    /**
     * @param mixed $kept
     * @param mixed $more
     *
     * @return mixed
     */
    private static function merge($kept, $more)
    {
        if (!\is_array($kept) || !\is_array($more)) {
            return $more;
        }
        foreach ($more as $key => $value) {
            $kept[$key] = \array_key_exists($key, $kept) ? self::merge($kept[$key], $value) : $value;
        }

        return $kept;
    }
}
