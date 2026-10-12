<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Per-package results over the recorded fixtures, held in committed files under tests/fixtures/golden.
 * A re-record of the fixtures moves them without a defect: rewrite them with LOCKROT_REWRITE_GOLDEN=1,
 * then review the diff package by package.
 */
final class Golden
{
    private const DIRECTORY = __DIR__.'/../fixtures/golden/';

    /** @param array<mixed> $actual */
    public static function assertMatches(string $file, array $actual, string $test, string $directory = self::DIRECTORY): void
    {
        $path = $directory.$file;
        $json = json_encode($actual, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR)."\n";
        if (getenv('LOCKROT_REWRITE_GOLDEN') === '1') {
            Assert::assertNotFalse(file_put_contents($path, $json), 'cannot write '.$path);
            Assert::fail('rewrote '.$path.'; review the diff and run again without LOCKROT_REWRITE_GOLDEN');
        }
        $command = 'rewrite it with LOCKROT_REWRITE_GOLDEN=1 vendor/bin/phpunit --filter '.$test.', then review the diff';

        Assert::assertFileExists($path, $command);
        // Compare entry by entry first: a failing comparison of the whole file diffs every line, and on
        // a large file that diff outruns the time limit of a mutant's test (MutantTestTimeLimit).
        $expected = json_decode((string) file_get_contents($path), true);
        $written = json_decode($json, true);
        if (\is_array($expected) && \is_array($written)) {
            foreach ($written as $key => $entry) {
                Assert::assertSame($expected[$key] ?? null, $entry, $path.' '.$key.': '.$command);
            }
        }
        Assert::assertSame(file_get_contents($path), $json, $path.': '.$command);
    }
}
