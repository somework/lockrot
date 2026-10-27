<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * docs/baseline.md quotes an abridged run of the recorded wallabag-main check. Each quoted line is a
 * line of that recording, so a re-recorded fixture that moves a count fails here first.
 */
final class BaselineDocsExampleTest extends TestCase
{
    private const ROOT = __DIR__.'/../../';

    public function testEveryLineOfTheBaselineExampleIsALineOfTheRecordedRun(): void
    {
        $page = (string) file_get_contents(self::ROOT.'docs/baseline.md');
        self::assertSame(1, preg_match('/From the run of a recorded project with a baseline, abridged:\n\n```text\n(.*?)\n```/s', $page, $example), 'the example block of docs/baseline.md');
        $recording = (string) file_get_contents(self::ROOT.'tests/fixtures/baselined/wallabag-main/check/report.table.txt');
        $recorded = array_map('rtrim', explode("\n", (string) preg_replace('/<\/?fg(=[a-z]+)?>/', '', $recording)));

        $quoted = array_values(array_filter(explode("\n", $example[1] ?? ''), static fn (string $line): bool => $line !== '…'));
        self::assertNotSame([], $quoted);
        foreach ($quoted as $line) {
            self::assertContains($line, $recorded, 'docs/baseline.md quotes a line that tests/fixtures/baselined/wallabag-main/check/report.table.txt does not hold');
        }
    }
}
