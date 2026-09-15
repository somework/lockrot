<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Baseline;

use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineEntry;
use Lockrot\Baseline\BaselineFile;
use Lockrot\Exception\ConfigException;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class BaselineFileTest extends TestCase
{
    private const AT = '2026-09-14T00:00:00+00:00';
    private const FIXTURES = __DIR__.'/../../fixtures/baseline';

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
        $dir = sys_get_temp_dir().'/lockrot-baseline-file-'.uniqid('', true);
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        @chmod($dir, 0777);
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function baseline(): Baseline
    {
        return Baseline::of([
            new BaselineEntry('acme/abandoned', '1.0.0', Verdict::ABANDONED, '2026-01-15'),
            new BaselineEntry('acme/silent', '2.0.8', Verdict::SILENT, '2026-02-20'),
        ], self::AT);
    }

    public function testTheDefaultPathSitsNextToComposerJson(): void
    {
        $file = BaselineFile::resolve('/projects/app', null);

        self::assertSame('/projects/app/lockrot-baseline.json', $file->path());
        self::assertSame('lockrot-baseline.json', $file->displayPath());
    }

    public function testAConfiguredRelativePathIsResolvedAgainstTheProjectDirectory(): void
    {
        $file = BaselineFile::resolve('/projects/app', 'ci/rot.json');

        self::assertSame('/projects/app/ci/rot.json', $file->path());
        self::assertSame('ci/rot.json', $file->displayPath(), 'the display path stays relative so CI diffs are machine-independent');
    }

    public function testAConfiguredAbsolutePathIsUsedAsIs(): void
    {
        $file = BaselineFile::resolve('/projects/app', '/elsewhere/rot.json');

        self::assertSame('/elsewhere/rot.json', $file->path());
        self::assertSame('/elsewhere/rot.json', $file->displayPath());
    }

    public function testWriteThenReadRoundTrip(): void
    {
        $file = BaselineFile::resolve($this->tempDir(), null);
        self::assertFalse($file->exists());

        $file->write($this->baseline());

        self::assertTrue($file->exists());
        self::assertSame($this->baseline()->toArray(), $file->read()->toArray());
    }

    public function testWrittenFileLooksLikeComposersOwnJson(): void
    {
        $file = BaselineFile::resolve($this->tempDir(), null);
        $file->write($this->baseline());
        $contents = (string) file_get_contents($file->path());

        self::assertStringStartsWith("{\n    \"lockrot\": {\n", $contents);
        self::assertStringEndsWith("}\n", $contents);
        self::assertStringNotContainsString('\\/', $contents, 'slashes stay unescaped, as in composer.json');
    }

    public function testWritingTwiceIsByteIdentical(): void
    {
        $file = BaselineFile::resolve($this->tempDir(), null);
        $file->write($this->baseline());
        $first = (string) file_get_contents($file->path());
        $file->write($this->baseline());

        self::assertSame($first, (string) file_get_contents($file->path()));
    }

    public function testWriteLeavesNoTemporaryFileBehind(): void
    {
        $dir = $this->tempDir();
        $file = BaselineFile::resolve($dir, null);
        $file->write($this->baseline());

        $found = array_values(array_diff((array) scandir($dir), ['.', '..']));
        self::assertSame(['lockrot-baseline.json'], $found);
    }

    public function testAnUnwritableTargetIsAConfigException(): void
    {
        $dir = $this->tempDir();
        $file = BaselineFile::resolve($dir, null);
        chmod($dir, 0555);
        clearstatcache(true, $dir);
        if (is_writable($dir)) {
            chmod($dir, 0777);
            self::markTestSkipped('this process can write to a read-only directory (running as root)');
        }

        try {
            $this->expectException(ConfigException::class);
            $this->expectExceptionMessageMatches('/Cannot write /');
            $file->write($this->baseline());
        } finally {
            chmod($dir, 0777);
        }
    }

    /**
     * The reported reason must be the write that actually failed, not the cleanup that follows it:
     * with no directory to write into there is no temp file to remove either, so an unlink() run
     * before the reason is read would overwrite the real error with its own.
     */
    public function testAMissingDirectoryReportsTheFailedWriteAndNotTheCleanup(): void
    {
        $file = BaselineFile::resolve($this->tempDir().'/no/such/dir', null);

        $thrown = null;
        try {
            $file->write($this->baseline());
        } catch (ConfigException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(ConfigException::class, $thrown);
        self::assertStringContainsString('Cannot write ', $thrown->getMessage());
        self::assertStringContainsString('file_put_contents', $thrown->getMessage());
        self::assertStringNotContainsString('unlink', $thrown->getMessage());
    }

    /**
     * A baseline with nothing in it must still satisfy resources/lockrot-baseline.schema.json, which
     * requires `findings` to be an object. PHP encodes an empty array as `[]`, so the empty map is
     * written as an explicit `{}` instead.
     */
    public function testAnEmptyBaselineWritesFindingsAsAnObject(): void
    {
        $file = BaselineFile::resolve($this->tempDir(), null);
        $file->write(Baseline::of([], self::AT));
        $contents = (string) file_get_contents($file->path());

        self::assertStringContainsString('"findings": {}', $contents);
        self::assertStringNotContainsString('"findings": []', $contents);
        self::assertSame(0, $file->read()->count());
    }

    /**
     * rename() fails when the target is an existing non-empty directory, which exercises the second
     * failure branch of the only write lockrot performs: the temp file is written successfully and
     * only the move fails.
     */
    public function testAFailingRenameIsAConfigExceptionAndLeavesNoTemporaryFile(): void
    {
        $dir = $this->tempDir();
        mkdir($dir.'/lockrot-baseline.json');
        file_put_contents($dir.'/lockrot-baseline.json/occupied', 'x');
        $file = BaselineFile::resolve($dir, null);

        $thrown = null;
        try {
            $file->write($this->baseline());
        } catch (ConfigException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(ConfigException::class, $thrown);
        self::assertStringContainsString('Cannot write lockrot-baseline.json', $thrown->getMessage());
        self::assertStringNotContainsString('unlink', $thrown->getMessage());
        self::assertSame(
            ['lockrot-baseline.json'],
            array_values(array_diff((array) scandir($dir), ['.', '..'])),
            'the temporary file must be cleaned up'
        );
    }

    /** Two runs in the same directory must not be able to rename each other's half-written file. */
    public function testTheTemporaryFileNameIsUniquePerRun(): void
    {
        // The temp file is gone by the time a successful write() returns, so the name is observed
        // through the failure message of a write that cannot complete instead.
        $file = BaselineFile::resolve($this->tempDir().'/missing', null);

        $messages = [];
        for ($i = 0; $i < 2; ++$i) {
            try {
                $file->write($this->baseline());
                self::fail('writing into a missing directory must fail');
            } catch (ConfigException $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertStringContainsString('.tmp', $messages[0]);
        self::assertNotSame($messages[0], $messages[1], 'the temporary file name must differ between runs');
    }

    public function testReadingAMalformedFileIsAConfigException(): void
    {
        $file = BaselineFile::resolve(self::FIXTURES, 'malformed.json');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/is not valid JSON/');
        $file->read();
    }

    public function testReadingAMissingFileIsAConfigException(): void
    {
        $file = BaselineFile::resolve($this->tempDir(), null);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/not found/');
        $file->read();
    }
}
