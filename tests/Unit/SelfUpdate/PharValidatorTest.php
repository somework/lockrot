<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\SelfUpdate;

use Lockrot\SelfUpdate\PharValidator;
use PHPUnit\Framework\TestCase;

final class PharValidatorTest extends TestCase
{
    /**
     * A 254-byte archive built once with `php -d phar.readonly=0` and checked in, because
     * phar.readonly defaults to On and a test cannot create a phar at runtime.
     */
    private const MINIMAL_PHAR = __DIR__.'/../../fixtures/phar/minimal.phar';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    /** Phar only opens a file whose name ends in a recognised extension, so every path here is *.phar. */
    private function tempPhar(string $contents): string
    {
        $path = sys_get_temp_dir().'/lockrot-validator-'.uniqid('', true).'.phar';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function testAnArchiveTheRuntimeCanOpenPassesWithNoMessage(): void
    {
        $copy = $this->tempPhar((string) file_get_contents(self::MINIMAL_PHAR));

        self::assertNull((new PharValidator())->validate($copy));
    }

    /**
     * The downloaded file is opened under a fresh name while the running archive already holds its
     * own alias; that must not be mistaken for a damaged download.
     */
    public function testACopyOfAnArchiveWhoseAliasIsAlreadyMappedStillPasses(): void
    {
        $first = $this->tempPhar((string) file_get_contents(self::MINIMAL_PHAR));
        $second = $this->tempPhar((string) file_get_contents(self::MINIMAL_PHAR));

        self::assertNull((new PharValidator())->validate($first));
        self::assertNull((new PharValidator())->validate($second));
    }

    public function testAFileThatIsNotAnArchiveIsReportedWithTheRuntimesOwnMessage(): void
    {
        $path = $this->tempPhar('not an archive at all');

        $error = (new PharValidator())->validate($path);

        self::assertIsString($error);
        self::assertNotSame('', $error);
    }
}
