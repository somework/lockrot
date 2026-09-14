<?php

declare(strict_types=1);

namespace Lockrot\Json;

use Composer\Json\JsonFile;
use Lockrot\Exception\ConfigException;
use Seld\JsonLint\ParsingException;

/**
 * Reads a JSON file through Composer's own JsonFile, translating its exceptions into the
 * single ConfigException contract every lockrot caller relies on.
 */
final class JsonReader
{
    /**
     * Reads a JSON file whose top level is an object and returns it as a string-keyed array.
     *
     * @return array<string, mixed>
     * @throws ConfigException  "<path> not found" when the file is missing;
     *                          "Cannot read <path>: <reason>" when unreadable;
     *                          "<path> is not valid JSON: <jsonlint message>" on syntax errors;
     *                          "<path> must contain a JSON object" when the top level is a scalar or a
     *                          non-empty list (an empty top-level [] is accepted: {} and [] decode to
     *                          the same PHP value via json_decode(..., true), so they are indistinguishable).
     */
    public static function readObject(string $path): array
    {
        if (!is_file($path)) {
            throw new ConfigException($path.' not found');
        }

        try {
            $data = (new JsonFile($path))->read();
        } catch (ParsingException $e) {
            throw new ConfigException($path.' is not valid JSON: '.$e->getMessage(), 0, $e);
        } catch (\UnexpectedValueException $e) {
            // JsonFile::read() -> parseJson() -> validateSyntax() throws this (a \RuntimeException
            // subclass) instead of ParsingException when the content contains invalid UTF-8 bytes;
            // it must be caught ahead of the plain \RuntimeException case below.
            throw new ConfigException($path.' is not valid JSON: '.$e->getMessage(), 0, $e);
        } catch (\RuntimeException $e) {
            throw new ConfigException('Cannot read '.$path.': '.$e->getMessage(), 0, $e);
        }

        if (!\is_array($data) || self::isList($data)) {
            throw new ConfigException($path.' must contain a JSON object');
        }

        return self::stringKeyed($data);
    }

    /**
     * Filters an array down to its string keys, e.g. dropping the PHP-integer-cast key that
     * a JSON object like {"123": 1} decodes to. Shared by readObject() and by callers that
     * need the same guarantee for a nested object pulled out of an already-read document.
     *
     * @param array<mixed, mixed> $data
     * @return array<string, mixed>
     */
    public static function stringKeyed(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (\is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * True when $data's keys are exactly 0..count-1 in order — i.e. json_decode(..., true) turned
     * a JSON array into it. An empty array is deliberately NOT a list here, since {} and [] are
     * indistinguishable after that decode and callers need [] to stay acceptable as "empty object".
     *
     * @param array<mixed, mixed> $data
     */
    public static function isList(array $data): bool
    {
        if ($data === []) {
            return false;
        }

        return array_keys($data) === range(0, \count($data) - 1);
    }
}
