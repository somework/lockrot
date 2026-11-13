<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * A formatter's output for a model case, held byte for byte in tests/fixtures/golden/formats. A
 * re-record of the case fixtures moves it without a defect: rewrite it with
 * LOCKROT_REWRITE_GOLDEN=1, then review the diff line by line.
 */
final class TextGolden
{
    private const DIRECTORY = __DIR__.'/../fixtures/golden/formats/';

    private const CASE_HEADER = '== case ';

    private const SHOWN_LINES = 8;

    public static function assertMatches(string $file, string $actual, string $test, string $directory = self::DIRECTORY): void
    {
        $path = $directory.$file;
        if (getenv('LOCKROT_REWRITE_GOLDEN') === '1') {
            Assert::assertNotFalse(file_put_contents($path, $actual), 'cannot write '.$path);
            Assert::fail('rewrote '.$path.'; review the diff and run again without LOCKROT_REWRITE_GOLDEN');
        }

        Assert::assertFileExists($path, 'record it with LOCKROT_REWRITE_GOLDEN=1 vendor/bin/phpunit --filter '.$test);
        $expected = (string) file_get_contents($path);
        $command = 'rewrite it with LOCKROT_REWRITE_GOLDEN=1 vendor/bin/phpunit --filter '.$test.', then review the diff';
        // A failing assertSame() diffs the whole text. On a large golden that diff costs more than the
        // test, and mutation testing pays it once per killed mutant.
        if ($expected !== $actual) {
            self::assertFirstDifference($path, explode("\n", $expected), explode("\n", $actual), $command);
        }
        Assert::assertSame($expected, $actual, $path.': '.$command);
    }

    /**
     * @param list<string> $expected
     * @param list<string> $actual
     */
    private static function assertFirstDifference(string $path, array $expected, array $actual, string $command): void
    {
        $at = 0;
        while (isset($expected[$at], $actual[$at]) && $expected[$at] === $actual[$at]) {
            ++$at;
        }
        $case = '';
        for ($i = min($at, \count($expected) - 1); $i >= 0 && $case === ''; --$i) {
            $case = strncmp($expected[$i], self::CASE_HEADER, \strlen(self::CASE_HEADER)) === 0 ? ', in '.$expected[$i].',' : '';
        }

        Assert::assertSame(self::numbered($expected, $at), self::numbered($actual, $at), $path.': line '.($at + 1).$case.' differs; '.$command);
    }

    /** @param list<string> $lines */
    private static function numbered(array $lines, int $from): string
    {
        $shown = '';
        foreach (\array_slice($lines, $from, self::SHOWN_LINES, true) as $index => $line) {
            $shown .= ($index + 1).': '.$line."\n";
        }

        return $shown;
    }
}
