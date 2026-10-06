<?php

declare(strict_types=1);

namespace Lockrot\Json;

use Composer\Json\JsonFile;
use Lockrot\Exception\ConfigException;
use Seld\JsonLint\ParsingException;

/** @internal */
final class JsonReader
{
    /**
     * @return array<string, mixed>
     * @throws ConfigException when the file is missing, unreadable or not valid JSON, or when its top
     *                         level is a scalar or a non-empty list. An empty top-level `[]` is
     *                         accepted, because `{}` and `[]` decode to the same array.
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
            // JsonFile::read() throws this RuntimeException subclass instead of ParsingException on
            // invalid UTF-8 bytes. Catch it before the plain RuntimeException.
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
     * Drops the keys that are not strings, such as the integer that PHP makes of the JSON key `"123"`.
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
     * An empty array is not a list here: `{}` and `[]` decode to the same array, and `[]` must stay
     * acceptable as an empty object.
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
