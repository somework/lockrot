<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Json\KnownValues;
use Lockrot\Json\Schemas;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\OpenSets;
use PHPUnit\Framework\TestCase;

/**
 * Every open set a published schema carries is named where a consumer reads which sets grow: the
 * bullet list of docs/compatibility.md's "Open sets", the paragraph there that says which of them
 * the schemas describe, and docs/schema.md's "Open sets" paragraph. A field that gains
 * `x-known-values` without a line on those pages fails here, and so does a registry entry for a
 * node that no longer carries it.
 */
final class OpenSetsRegisteredTest extends TestCase
{
    private const DOCS = __DIR__.'/../../docs/';

    public function testTheRegistryListsEveryNodeWithKnownValuesAndNothingElse(): void
    {
        $found = [];
        foreach ([Schemas::REPORT, Schemas::EXPLAIN, Schemas::CONFIG, Schemas::BASELINE] as $document) {
            foreach (self::withKnownValues(JsonPath::decodeFile(Schemas::path($document)), '#') as $pointer) {
                $found[] = $document.' '.$pointer;
            }
        }
        sort($found);
        $registered = array_keys(OpenSets::PHRASES);
        sort($registered);

        self::assertSame($registered, $found);
    }

    public function testEveryOpenSetIsNamedOnBothPages(): void
    {
        $compatibility = self::section(self::read('compatibility.md'), '### Open sets');
        $bullets = self::flat(implode(' ', array_filter(explode("\n", $compatibility), static fn (string $line): bool => strpos($line, '- ') === 0)));
        $schemas = self::paragraphStartingWith($compatibility, 'The schemas describe');
        $schemaPage = self::paragraphStartingWith(self::section(self::read('schema.md'), '## Open sets'), 'Objects are open');
        self::assertNotSame('', $bullets, 'the bullet list');

        foreach (OpenSets::PHRASES as $pointer => $phrase) {
            self::assertStringContainsString($phrase, $bullets, $pointer.': docs/compatibility.md\'s list of open sets');
            self::assertStringContainsString($phrase, $schemas, $pointer.': docs/compatibility.md\'s "The schemas describe" paragraph');
            self::assertStringContainsString($phrase, $schemaPage, $pointer.': docs/schema.md\'s "Open sets" paragraph');
        }
    }

    /**
     * @param array<mixed, mixed> $node
     *
     * @return list<string>
     */
    private static function withKnownValues(array $node, string $path): array
    {
        $found = \array_key_exists(KnownValues::KEYWORD, $node) ? [$path] : [];
        foreach ($node as $key => $value) {
            if (\is_array($value) && $key !== KnownValues::KEYWORD) {
                $found = array_merge($found, self::withKnownValues($value, $path.'/'.$key));
            }
        }

        return $found;
    }

    /** From the heading to the next heading of the same or a higher level. */
    private static function section(string $page, string $heading): string
    {
        $start = strpos($page, "\n".$heading."\n");
        self::assertNotFalse($start, $heading);
        $level = \strlen((string) strstr($heading, ' ', true));
        $rest = substr($page, $start + \strlen($heading) + 2);
        $end = preg_match('/^#{1,'.$level.'} /m', $rest, $match, \PREG_OFFSET_CAPTURE) === 1 ? $match[0][1] : \strlen($rest);

        return substr($rest, 0, $end);
    }

    /** The paragraph that opens with these words, its lines joined. */
    private static function paragraphStartingWith(string $text, string $opening): string
    {
        foreach (preg_split('/\n\s*\n/', $text) ?: [] as $paragraph) {
            if (strpos(ltrim($paragraph), $opening) === 0) {
                return self::flat($paragraph);
            }
        }
        self::fail('no paragraph opens with "'.$opening.'"');
    }

    private static function flat(string $text): string
    {
        return (string) preg_replace('/\s+/', ' ', $text);
    }

    private static function read(string $page): string
    {
        $contents = file_get_contents(self::DOCS.$page);
        self::assertIsString($contents, $page);

        return $contents;
    }
}
