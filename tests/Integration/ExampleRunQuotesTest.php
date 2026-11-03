<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\JsonPath;
use Lockrot\Version;
use PHPUnit\Framework\TestCase;

/**
 * A docs page that quotes the example run quotes only lines that docs/example-run.md holds, so a
 * re-record of the example run fails here until the quotes follow it. `…` marks a cut.
 */
final class ExampleRunQuotesTest extends TestCase
{
    private const DOCS = __DIR__.'/../../docs/';

    public function testEveryQuoteFromTheExampleRunIsInTheRecording(): void
    {
        $recording = array_map('trim', explode("\n", (string) file_get_contents(self::DOCS.'example-run.md')));
        $quotes = self::quotes((string) file_get_contents(self::DOCS.'verdicts.md'));
        self::assertNotSame([], $quotes);

        $missing = [];
        foreach ($quotes as $line) {
            foreach (array_filter(array_map('trim', explode('…', $line)), static fn (string $part): bool => $part !== '') as $part) {
                if ([] === array_filter($recording, static fn (string $recorded): bool => strpos($recorded, $part) !== false)) {
                    $missing[] = $line;
                }
            }
        }

        self::assertSame([], $missing, 'verdicts.md quotes lines that docs/example-run.md does not hold');
    }

    public function testTheRecordingNamesOneVersionAndOneDay(): void
    {
        [$day, $version, $page] = self::recording();
        preg_match('/```json\n(.*?)\n```/s', $page, $json);
        $report = json_decode((string) preg_replace('/^(\s*)…$/mu', '$1"…"', $json[1] ?? ''), true);
        self::assertIsArray($report, 'the JSON block of docs/example-run.md decodes');

        self::assertSame($version, JsonPath::stringAt($report, ['lockrot', 'version']), 'the JSON block of docs/example-run.md is of another lockrot');
        self::assertStringStartsWith($day.'T', JsonPath::stringAt($report, ['generated_at']), 'the JSON block of docs/example-run.md is of another day');
        self::assertSame(2, substr_count($page, 'Data as of '.$day.' '), 'the table and the clean run of docs/example-run.md are of the day that the page names');
    }

    /**
     * A recording ahead of the release names a later version. The release pull request sets
     * Version::STRING and its dated CHANGELOG heading, and the page then needs that day.
     */
    public function testTheRecordingIsOfTheReleaseOnItsDay(): void
    {
        [$day, $version] = self::recording();

        if ($version !== Version::STRING) {
            self::assertTrue(version_compare($version, Version::STRING, '>'), 'docs/example-run.md is a recording of lockrot '.$version.', older than '.Version::STRING.': re-record it');

            return;
        }
        preg_match('/^## \['.preg_quote($version, '/').'\] - (\d{4}-\d{2}-\d{2})$/m', (string) file_get_contents(__DIR__.'/../../CHANGELOG.md'), $heading);
        self::assertSame($heading[1] ?? null, $day, 'record docs/example-run.md on the day of the CHANGELOG heading of '.$version.' (tools/release/example_run.py)');
    }

    /** @return array{string, string, string} the day, the version and the page */
    private static function recording(): array
    {
        $page = (string) file_get_contents(self::DOCS.'example-run.md');
        if (preg_match('/Recorded\s+on\s+(\d{4}-\d{2}-\d{2})\s+with\s+lockrot\s+(\d+\.\d+\.\d+)\s/', $page, $recorded) !== 1) {
            self::fail('docs/example-run.md has no "Recorded on <day> with lockrot <version>" sentence');
        }

        return [$recorded[1], $recorded[2], $page];
    }

    /** @return list<string> the lines of each code block that follows a sentence naming the example run */
    private static function quotes(string $page): array
    {
        preg_match_all('/\[example\s+run\]\(example-run\.md\):\n\n```text\n(.*?)\n```/s', $page, $blocks);
        $lines = [];
        foreach ($blocks[1] as $block) {
            foreach (explode("\n", $block) as $line) {
                if (trim($line) !== '') {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }
}
