<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit;

use Lockrot\Version;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    /**
     * A release pull request sets both (CONTRIBUTING, "Cutting a release"). phar.yml checks the
     * archive's version only after the tag is public, and Packagist reads the tag at once.
     */
    public function testTheVersionIsTheNewestReleaseOfTheChangelog(): void
    {
        preg_match('/^## \[(\d+\.\d+\.\d+)\] - \d{4}-\d{2}-\d{2}$/m', (string) file_get_contents(__DIR__.'/../../CHANGELOG.md'), $newest);

        self::assertSame($newest[1] ?? null, Version::STRING, 'set Version::STRING and the newest dated section of CHANGELOG.md together');
    }
}
