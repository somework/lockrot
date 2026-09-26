<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

/**
 * Typed reads into a `json_decode(..., true)` result.
 *
 * A decoded document is `mixed` all the way down, so asserting on a nested member either means a
 * chain of `assertIsArray()` calls at every level or a static-analysis waiver. These helpers narrow
 * instead: each one walks the path, and a member that is missing or of the wrong type raises a
 * \RuntimeException naming the path, which surfaces as an ordinary test failure.
 */
final class JsonPath
{
    /** @var array<string, array<mixed, mixed>> path => decoded contents */
    private static array $files = [];

    /**
     * @param array<mixed, mixed> $data
     * @param list<int|string>    $path
     *
     * @return array<mixed, mixed>
     */
    public static function arrayAt(array $data, array $path): array
    {
        $value = self::at($data, $path);
        if (!\is_array($value)) {
            throw new \RuntimeException(self::describe($path).' is not an array but '.\gettype($value));
        }

        return $value;
    }

    /**
     * @param array<mixed, mixed> $data
     * @param list<int|string>    $path
     */
    public static function stringAt(array $data, array $path): string
    {
        $value = self::at($data, $path);
        if (!\is_string($value)) {
            throw new \RuntimeException(self::describe($path).' is not a string but '.\gettype($value));
        }

        return $value;
    }

    /**
     * @param array<mixed, mixed> $data
     * @param list<int|string>    $path
     */
    public static function intAt(array $data, array $path): int
    {
        $value = self::at($data, $path);
        if (!\is_int($value)) {
            throw new \RuntimeException(self::describe($path).' is not an integer but '.\gettype($value));
        }

        return $value;
    }

    /**
     * @param array<mixed, mixed> $data
     * @param list<int|string>    $path
     */
    public static function boolAt(array $data, array $path): bool
    {
        $value = self::at($data, $path);
        if (!\is_bool($value)) {
            throw new \RuntimeException(self::describe($path).' is not a boolean but '.\gettype($value));
        }

        return $value;
    }

    /**
     * A JSON file decoded to arrays, read once per process: the schemas under resources/ are read
     * by several tests, and a file that is missing or not a JSON object fails the test that asked.
     *
     * @return array<mixed, mixed>
     */
    public static function decodeFile(string $path): array
    {
        if (!isset(self::$files[$path])) {
            $contents = @file_get_contents($path);
            if ($contents === false) {
                throw new \RuntimeException($path.' cannot be read');
            }
            $value = json_decode($contents, true);
            if (!\is_array($value)) {
                throw new \RuntimeException($path.' is not a JSON object or array');
            }
            self::$files[$path] = $value;
        }

        return self::$files[$path];
    }

    /**
     * True when every segment of $path exists; used to assert a member was deliberately omitted.
     *
     * @param array<mixed, mixed> $data
     * @param list<int|string>    $path
     */
    public static function has(array $data, array $path): bool
    {
        $value = $data;
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return false;
            }
            $value = $value[$key];
        }

        return true;
    }

    /**
     * The $key member of every row of the list at $path, like array_column() but type-checked.
     *
     * @param array<mixed, mixed> $data
     * @param list<int|string>    $path
     *
     * @return list<mixed>
     */
    public static function column(array $data, array $path, string $key): array
    {
        $out = [];
        foreach (self::arrayAt($data, $path) as $index => $row) {
            if (!\is_array($row) || !\array_key_exists($key, $row)) {
                throw new \RuntimeException(self::describe($path).'['.$index.'] has no '.$key);
            }
            $out[] = $row[$key];
        }

        return $out;
    }

    /**
     * @param array<mixed, mixed> $data
     * @param list<int|string>    $path
     *
     * @return mixed
     */
    private static function at(array $data, array $path)
    {
        $value = $data;
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                throw new \RuntimeException(self::describe($path).' is missing');
            }
            $value = $value[$key];
        }

        return $value;
    }

    /** @param list<int|string> $path */
    private static function describe(array $path): string
    {
        return '$.'.implode('.', array_map(static fn ($key): string => (string) $key, $path));
    }
}
