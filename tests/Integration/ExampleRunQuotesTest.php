<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

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
