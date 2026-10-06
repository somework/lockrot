<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** .claude/rules/tests.md, "Hermetic by default": a test reads the clock through `Clock::fixed()` or `LOCKROT_TODAY`. */
final class HermeticTestsTest extends TestCase
{
    private const REAL_CLOCK = '{(?<![\w>:$])time\(\)|(?<![\w>:$])date\(\s*\'[^\']*\'\s*\)|new \\\\?DateTime(?:Immutable)?\(\s*(?:\'now\')?\s*\)}';

    /** Tests of the real clock and of a file modification time. */
    private const ALLOWED = ['ClockTest.php', 'PharUpdaterTest.php'];

    public function testNoTestReadsTheRealClock(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__), \FilesystemIterator::SKIP_DOTS));
        $found = [];
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php' || $file->getPathname() === __FILE__ || \in_array($file->getFilename(), self::ALLOWED, true)) {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $n => $line) {
                if (preg_match(self::REAL_CLOCK, $line) === 1) {
                    $found[] = $file->getFilename().':'.($n + 1).': '.trim($line);
                }
            }
        }

        self::assertSame([], $found, 'pass a fixed instant instead');
    }
}
