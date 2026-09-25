<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Filesystem;

use Lockrot\Exception\ConfigException;
use Lockrot\Filesystem\AtomicWriter;
use PHPUnit\Framework\TestCase;

final class AtomicWriterTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::removeTree($dir);
        }
        $this->tempDirs = [];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/lockrot-atomic-'.uniqid('', true);
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private static function removeTree(string $dir): void
    {
        $entries = is_dir($dir) ? scandir($dir) : false;
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) && !is_link($path) ? self::removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** @return list<string> */
    private static function entries(string $dir): array
    {
        $entries = scandir($dir);
        self::assertIsArray($entries);

        return array_values(array_diff($entries, ['.', '..']));
    }

    public function testWritesTheContentsAndLeavesOnlyTheTarget(): void
    {
        $dir = $this->tempDir();

        AtomicWriter::write($dir.'/r.json', "{\"a\": \"<b>\"}\n", 'r.json');

        self::assertSame("{\"a\": \"<b>\"}\n", file_get_contents($dir.'/r.json'));
        self::assertSame(['r.json'], self::entries($dir));
    }

    public function testReplacesAnExistingFile(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir.'/r.json', 'an older and much longer report than the one that replaces it');

        AtomicWriter::write($dir.'/r.json', 'new', 'r.json');

        self::assertSame('new', file_get_contents($dir.'/r.json'));
        self::assertSame(['r.json'], self::entries($dir));
    }

    public function testEmptyContentsAreWritten(): void
    {
        $dir = $this->tempDir();

        AtomicWriter::write($dir.'/r.txt', '', 'r.txt');

        self::assertSame('', file_get_contents($dir.'/r.txt'));
    }

    /**
     * The temp file is written and only the move fails: a non-empty directory sits at the target. The
     * message names the path as the caller spelled it, then PHP's own reason, and the temp file is
     * gone.
     */
    public function testAFailingRenameIsAConfigExceptionWithTheReasonAndLeavesNoTemporaryFile(): void
    {
        $dir = $this->tempDir();
        mkdir($dir.'/r.json');
        file_put_contents($dir.'/r.json/occupied', 'x');

        $thrown = null;
        try {
            AtomicWriter::write($dir.'/r.json', 'report', 'out/r.json');
        } catch (ConfigException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(ConfigException::class, $thrown);
        self::assertMatchesRegularExpression('/^Cannot write out\/r\.json: \S/', $thrown->getMessage());
        self::assertStringContainsString('rename', $thrown->getMessage());
        self::assertStringNotContainsString('unlink', $thrown->getMessage());
        self::assertSame(['r.json'], self::entries($dir), 'the temporary file must be cleaned up');
    }

    /** With no directory to write into, the reason is the write's own, not the cleanup's. */
    public function testAMissingDirectoryReportsTheFailedWrite(): void
    {
        $dir = $this->tempDir();

        $thrown = null;
        try {
            AtomicWriter::write($dir.'/no/r.json', 'report', 'no/r.json');
        } catch (ConfigException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(ConfigException::class, $thrown);
        self::assertMatchesRegularExpression('/^Cannot write no\/r\.json: \S/', $thrown->getMessage());
        self::assertStringContainsString('file_put_contents', $thrown->getMessage());
        self::assertStringNotContainsString('unlink', $thrown->getMessage());
        self::assertSame([], self::entries($dir));
    }

    /** Two runs in one directory must not be able to rename each other's half-written file. */
    public function testTheTemporaryFileNameIsUniquePerWriteAndSitsBesideTheTarget(): void
    {
        $dir = $this->tempDir();
        $messages = [];
        for ($i = 0; $i < 2; ++$i) {
            try {
                AtomicWriter::write($dir.'/missing/r.json', 'report', 'r.json');
                self::fail('writing into a missing directory must fail');
            } catch (ConfigException $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertStringContainsString($dir.'/missing/r.json.'.getmypid().'-', $messages[0]);
        self::assertStringContainsString('.tmp', $messages[0]);
        self::assertNotSame($messages[0], $messages[1]);
    }
}
