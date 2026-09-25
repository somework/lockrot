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
        self::assertStringContainsString('fopen', $thrown->getMessage());
        self::assertStringNotContainsString('unlink', $thrown->getMessage());
        self::assertSame([], self::entries($dir));
    }

    /**
     * A report kept private stays private: the file that replaces it gets its permission bits, not
     * the umask's default. Owner and ACLs are not carried over.
     */
    public function testReplacingAFileKeepsItsPermissions(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Windows has no Unix permission bits');
        }
        $dir = $this->tempDir();
        file_put_contents($dir.'/r.json', 'old');
        chmod($dir.'/r.json', 0600);
        file_put_contents($dir.'/wide.json', 'old');
        chmod($dir.'/wide.json', 0664);
        file_put_contents($dir.'/run.sh', 'old');
        chmod($dir.'/run.sh', 0755);

        AtomicWriter::write($dir.'/r.json', 'new', 'r.json');
        AtomicWriter::write($dir.'/wide.json', 'new', 'wide.json');
        AtomicWriter::write($dir.'/run.sh', 'new', 'run.sh');
        clearstatcache();

        self::assertSame(0600, fileperms($dir.'/r.json') & 0777);
        self::assertSame(0664, fileperms($dir.'/wide.json') & 0777);
        self::assertSame(0755, fileperms($dir.'/run.sh') & 0777, 'every bit, execute ones included');
        self::assertSame('new', file_get_contents($dir.'/r.json'));
    }

    /** A new file is created as any other: 0666 less the umask. */
    public function testANewFileGetsTheUmaskDefault(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Windows has no Unix permission bits');
        }
        $dir = $this->tempDir();
        $previous = umask(0027);
        try {
            AtomicWriter::write($dir.'/r.json', 'new', 'r.json');
        } finally {
            umask($previous);
        }
        clearstatcache();

        self::assertSame(0640, fileperms($dir.'/r.json') & 0777);
    }

    /**
     * A write that stores fewer bytes than it was given — a full disk — fails, even though the
     * file was created and closed and the rename would go through: a truncated report is never
     * moved into place. A stream wrapper stands in for the full disk.
     */
    public function testAShortWriteFailsAndIsNeverRenamedIntoPlace(): void
    {
        ShortWriteStream::register();
        try {
            $thrown = null;
            try {
                AtomicWriter::write(ShortWriteStream::SCHEME.'://disk/r.json', 'report', 'r.json');
            } catch (ConfigException $e) {
                $thrown = $e;
            }

            self::assertInstanceOf(ConfigException::class, $thrown);
            self::assertStringStartsWith('Cannot write r.json: ', $thrown->getMessage());
            self::assertSame([], ShortWriteStream::$renamed, 'nothing is moved into place');
            self::assertCount(1, ShortWriteStream::$unlinked, 'the temporary file is removed');
        } finally {
            ShortWriteStream::unregister();
        }
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

/**
 * A stream that accepts every file and stores none of its bytes: the full disk of
 * {@see AtomicWriterTest::testAShortWriteFailsAndIsNeverRenamedIntoPlace()}.
 */
final class ShortWriteStream
{
    public const SCHEME = 'lockrot-short-write';

    /** @var list<string> */
    public static array $renamed = [];

    /** @var list<string> */
    public static array $unlinked = [];

    /** @var resource|null set by PHP for every wrapper instance */
    public $context;

    public static function register(): void
    {
        self::$renamed = [];
        self::$unlinked = [];
        stream_wrapper_register(self::SCHEME, self::class);
    }

    public static function unregister(): void
    {
        stream_wrapper_unregister(self::SCHEME);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        return 0;
    }

    public function stream_close(): void
    {
    }

    /** Nothing is there beforehand, so there are no permissions to carry over. */
    public function url_stat(string $path, int $flags): bool
    {
        return false;
    }

    public function rename(string $from, string $to): bool
    {
        self::$renamed[] = $to;

        return true;
    }

    public function unlink(string $path): bool
    {
        self::$unlinked[] = $path;

        return true;
    }
}
