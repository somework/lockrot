<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Json\Schemas;

/**
 * The negative schema fixtures: documents a published schema must reject, each with the error it
 * must be rejected for, so a fixture rejected for another reason fails.
 *
 * A fixture lives in `<root>/<document>-<number>/`, and is validated against that document's schema
 * under that number. It names its expectation in one of two forms, as the generators write them:
 *
 * - embedded, as a root `$expect` object `{"strict": <bool>, "error": <string or null>}`, removed
 *   before the document is validated (report-2 and the SARIF property bags);
 * - in the directory's `EXPECT.json`, which maps each file name to its error, the expectation strict
 *   when the name ends in `.strict.json` (explain-2, baseline-2 and config-2).
 *
 * A strict expectation is validated against the strict twin
 * ({@see ValidatesJsonSchemas::strictTwin()}), any other against the schema as published. A fixture
 * passes when it has at least one error and, when its expectation names one, at least one error
 * (`<property>: <message>`) contains it, case-insensitive. A file with no expectation is an error of
 * the fixture set, never a fixture that silently loses its check.
 */
final class NegativeFixtures
{
    public const EXPECT = 'EXPECT.json';

    /** What holds an otherwise empty directory under git: no fixture. */
    private const KEEP = '.gitkeep';

    private const STRICT_SUFFIX = '.strict.json';

    /**
     * One row per fixture under a root, named `<document>-<number>/<file>`, in name order.
     * `EXPECT.json` and `.gitkeep` are no fixtures.
     *
     * @return array<string, array{string}> the fixture's path
     */
    public static function rows(string $root): array
    {
        $rows = [];
        foreach (self::entries($root) as $directory) {
            $dir = $root.'/'.$directory;
            if (!is_dir($dir)) {
                if ($directory !== self::KEEP) {
                    throw new \UnexpectedValueException($dir.' is not a <document>-<number> directory');
                }
                continue;
            }
            self::documentOf($directory);
            $expected = self::expectFile($dir);
            foreach (self::entries($dir) as $file) {
                if ($file === self::EXPECT || $file === self::KEEP) {
                    continue;
                }
                $rows[$directory.'/'.$file] = [$dir.'/'.$file];
                unset($expected[$file]);
            }
            if ($expected !== []) {
                throw new \UnexpectedValueException($dir.'/'.self::EXPECT.' names files that are not there: '.implode(', ', array_keys($expected)));
            }
        }

        return $rows;
    }

    /**
     * The document and number a fixture directory's name gives, such as `report-1`.
     *
     * @return array{string, int}
     */
    public static function documentOf(string $directory): array
    {
        $documents = Schemas::REPORT.'|'.Schemas::EXPLAIN.'|'.Schemas::BASELINE.'|'.Schemas::CONFIG;
        if (preg_match('{^('.$documents.')-([1-9]\d*)$}', $directory, $match) !== 1) {
            throw new \UnexpectedValueException($directory.' is not a <document>-<number> directory');
        }

        return [$match[1], (int) $match[2]];
    }

    /**
     * A fixture as it is validated, and its expectation.
     *
     * @return array{string, bool, ?string} the document as JSON without its `$expect`, whether the
     *                                      expectation is strict, the error it names
     */
    public static function read(string $path): array
    {
        $document = json_decode(self::contents($path));
        if (!$document instanceof \stdClass) {
            throw new \UnexpectedValueException($path.' is not a JSON object');
        }

        $vars = get_object_vars($document);
        if (\array_key_exists('$expect', $vars)) {
            [$strict, $error] = self::embedded($vars['$expect'], $path);
            unset($document->{'$expect'});
        } else {
            $entries = self::expectFile(\dirname($path));
            $file = basename($path);
            if (!\array_key_exists($file, $entries)) {
                throw new \UnexpectedValueException($path.' has no $expect and no '.self::EXPECT.' entry');
            }
            $strict = substr($file, -\strlen(self::STRICT_SUFFIX)) === self::STRICT_SUFFIX;
            $error = $entries[$file];
        }

        return [(string) json_encode($document, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), $strict, $error];
    }

    /**
     * Whether the errors a fixture produced are a rejection for the reason it names.
     *
     * @param list<string> $errors
     */
    public static function rejects(array $errors, ?string $error): bool
    {
        if ($errors === []) {
            return false;
        }
        if ($error === null || $error === '') {
            return true;
        }
        foreach ($errors as $message) {
            if (stripos($message, $error) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $expect
     *
     * @return array{bool, ?string}
     */
    private static function embedded($expect, string $path): array
    {
        $fields = $expect instanceof \stdClass ? get_object_vars($expect) : null;
        $strict = $fields['strict'] ?? null;
        $error = $fields['error'] ?? null;
        if (!\is_bool($strict) || ($error !== null && !\is_string($error))) {
            throw new \UnexpectedValueException($path.': $expect must be {"strict": <bool>, "error": <string or null>}');
        }

        return [$strict, $error];
    }

    /** @return array<string, string> file name => error; empty when the directory has no EXPECT.json */
    private static function expectFile(string $dir): array
    {
        $path = $dir.'/'.self::EXPECT;
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode(self::contents($path), true);
        if (!\is_array($decoded)) {
            throw new \UnexpectedValueException($path.' is not a JSON object');
        }
        $entries = [];
        foreach ($decoded as $file => $error) {
            if (!\is_string($file) || !\is_string($error)) {
                throw new \UnexpectedValueException($path.' must map file names to error paths');
            }
            $entries[$file] = $error;
        }

        return $entries;
    }

    /** @return list<string> the names in a directory, sorted; scandir() rather than glob(), so a path holding `[` is no pattern */
    private static function entries(string $dir): array
    {
        $names = scandir($dir);
        if ($names === false) {
            throw new \UnexpectedValueException('cannot list '.$dir);
        }
        $names = array_values(array_diff($names, ['.', '..']));
        sort($names, \SORT_STRING);

        return $names;
    }

    private static function contents(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \UnexpectedValueException('cannot read '.$path);
        }

        return $contents;
    }
}
