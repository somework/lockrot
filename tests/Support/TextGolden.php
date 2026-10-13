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

    public static function assertMatches(string $file, string $actual, string $test): void
    {
        $path = self::DIRECTORY.$file;
        if (getenv('LOCKROT_REWRITE_GOLDEN') === '1') {
            Assert::assertNotFalse(file_put_contents($path, $actual), 'cannot write '.$path);
            Assert::fail('rewrote '.$path.'; review the diff and run again without LOCKROT_REWRITE_GOLDEN');
        }

        Assert::assertFileExists($path, 'record it with LOCKROT_REWRITE_GOLDEN=1 vendor/bin/phpunit --filter '.$test);
        Assert::assertSame(file_get_contents($path), $actual, $path.': rewrite it with LOCKROT_REWRITE_GOLDEN=1 vendor/bin/phpunit --filter '.$test.', then review the diff');
    }
}
