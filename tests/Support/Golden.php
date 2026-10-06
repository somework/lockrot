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
    public static function assertMatches(string $file, array $actual, string $test): void
    {
        $path = self::DIRECTORY.$file;
        $json = json_encode($actual, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR)."\n";
        if (getenv('LOCKROT_REWRITE_GOLDEN') === '1') {
            file_put_contents($path, $json);
        }
        $command = 'rewrite it with LOCKROT_REWRITE_GOLDEN=1 vendor/bin/phpunit --filter '.$test.', then review the diff';

        Assert::assertFileExists($path, $command);
        Assert::assertSame(file_get_contents($path), $json, 'tests/fixtures/golden/'.$file.': '.$command);
    }
}
