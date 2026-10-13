<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The 0.13 vocabulary stays in src/Legacy (DECISIONS 2.31): live code reads the grade, the lead and
 * the flags, never 0.13's verdict predicate, its verdict counts or its order.
 */
final class LegacyVocabularyTest extends TestCase
{
    private const TOKENS = ['Verdict::flagged(', 'Verdict::severity(', 'Verdict::isValid(', 'Verdict::all(', 'report->flagged()', '->byVerdict()', 'Compare013', 'levelOf('];

    public function testNoLiveCodeCallsTheLegacyVocabulary(): void
    {
        $hits = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $path = str_replace('\\', '/', $file->getPathname());
            if (substr($path, -4) !== '.php' || strpos($path, '/src/Legacy/') !== false) {
                continue;
            }
            $source = (string) file_get_contents($path);
            foreach (self::TOKENS as $token) {
                if (strpos($source, $token) !== false) {
                    $hits[] = substr($path, strpos($path, '/src/') + 1).': '.$token;
                }
            }
        }
        sort($hits);

        self::assertSame([], $hits);
    }
}
