<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Filesystem;

use Lockrot\Filesystem\Path;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempDirs) as $dir) {
            @rmdir($dir);
        }
        $this->tempDirs = [];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/lockrot-path-'.uniqid('', true);
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /** @return iterable<string, array{string, bool}> */
    public static function paths(): iterable
    {
        yield 'a Unix absolute path' => ['/projects/app/r.json', true];
        yield 'a drive letter with a backslash' => ['C:\\ci\\r.json', true];
        yield 'a drive letter with a slash' => ['c:/ci/r.json', true];
        yield 'a UNC share' => ['\\\\server\\share\\r.json', true];
        yield 'a relative path' => ['ci/r.json', false];
        yield 'a dot-relative path' => ['./r.json', false];
        yield 'a drive letter with no separator is relative to that drive' => ['C:r.json', false];
        yield 'a colon later in the name is not a drive' => ['ab:/r.json', false];
    }

    /**
     * @dataProvider paths
     */
    #[DataProvider('paths')]
    public function testIsAbsolute(string $path, bool $absolute): void
    {
        self::assertSame($absolute, Path::isAbsolute($path));
    }

    public function testARelativePathIsJoinedToTheBaseAndAnAbsoluteOneIsKept(): void
    {
        self::assertSame('/projects/app/ci/r.json', Path::resolve('/projects/app', 'ci/r.json'));
        self::assertSame('/projects/app/ci/r.json', Path::resolve('/projects/app/', 'ci/r.json'));
        self::assertSame('/projects/app/ci/r.json', Path::resolve('/projects/app\\', 'ci/r.json'));
        self::assertSame('/elsewhere/r.json', Path::resolve('/projects/app', '/elsewhere/r.json'));
        self::assertSame('C:\\ci\\r.json', Path::resolve('/projects/app', 'C:\\ci\\r.json'));
    }

    /**
     * Two spellings of one file compare equal once the directory is resolved on disk: dot segments,
     * a symlinked temp directory (macOS) and the letter case of the whole path.
     */
    public function testCanonicalResolvesTheDirectoryThroughTheFilesystemAndIgnoresCase(): void
    {
        $dir = $this->tempDir();
        mkdir($dir.'/ci');
        $this->tempDirs[] = $dir.'/ci';

        $expected = Path::canonical($dir.'/ci/base.json');

        self::assertSame($expected, Path::canonical($dir.'/ci/../ci/./base.json'));
        self::assertSame($expected, Path::canonical($dir.'/CI/BASE.json'), 'case-insensitive filesystems are the common case');
        self::assertSame(strtolower((string) realpath($dir).'/ci/base.json'), $expected);
        self::assertNotSame($expected, Path::canonical($dir.'/ci/other.json'));
    }

    /**
     * A directory that does not exist cannot be resolved through the filesystem, so the path is
     * compared as it is spelled, still case-insensitively.
     */
    public function testCanonicalFallsBackToTheSpellingWhenTheDirectoryDoesNotExist(): void
    {
        $dir = $this->tempDir();

        self::assertSame(strtolower($dir).'/nope/base.json', Path::canonical($dir.'/nope/Base.json'));
        self::assertSame(Path::canonical($dir.'/nope/base.json'), Path::canonical($dir.'/NOPE/base.json'));
        self::assertNotSame(Path::canonical($dir.'/nope/base.json'), Path::canonical($dir.'/other/base.json'));
    }

    /**
     * Windows folds `..` by spelling alone, before it asks the disk anything, so
     * `missing\..\lockrot-baseline.json` is the baseline there even though `missing` does not exist.
     * The key folds dot segments the same way, with either separator, so that spelling is caught on
     * every system — a directory that cannot be resolved no longer hides what the path names.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function dotSegmentSpellings(): iterable
    {
        yield 'through a missing directory' => ['/nope/../base.json', '/base.json'];
        yield 'through a missing directory, backslashes' => ['\\nope\\..\\base.json', '/base.json'];
        yield 'mixed separators' => ['/nope\\..//./base.json', '/base.json'];
        yield 'two levels up' => ['/a/b/../../base.json', '/base.json'];
        yield 'a dot segment' => ['/./base.json', '/base.json'];
    }

    /**
     * @dataProvider dotSegmentSpellings
     */
    #[DataProvider('dotSegmentSpellings')]
    public function testCanonicalFoldsDotSegmentsBySpellingWhateverTheSeparator(string $spelled, string $meant): void
    {
        $dir = $this->tempDir();

        self::assertSame(Path::canonical($dir.$meant), Path::canonical($dir.$spelled));
    }

    /** A file in the root directory keeps one separator: realpath('/') ends in the one it has. */
    public function testCanonicalOfAFileInTheRootHasOneSeparator(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('the root is a drive on Windows');
        }

        self::assertSame('/r.json', Path::canonical('/r.json'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function normalized(): iterable
    {
        yield 'a plain path' => ['/app/r.json', '/app/r.json'];
        yield 'a dot segment' => ['/app/./r.json', '/app/r.json'];
        yield 'a parent segment' => ['/app/sub/../r.json', '/app/r.json'];
        yield 'backslashes' => ['C:\\app\\sub\\..\\r.json', 'C:/app/r.json'];
        yield 'repeated separators' => ['/app//sub///r.json', '/app/sub/r.json'];
        yield 'no climbing above the root' => ['/../../r.json', '/r.json'];
        yield 'no climbing above a drive' => ['C:\\..\\r.json', 'C:/r.json'];
        yield 'a UNC share keeps its two leading separators' => ['\\\\server\\share\\..\\r.json', '//server/r.json'];
        yield 'a relative path keeps the parents it cannot fold' => ['../a/../../r.json', '../../r.json'];
        yield 'a name with dots is not a dot segment' => ['/app/..r/.x/r..json', '/app/..r/.x/r..json'];
    }

    /**
     * @dataProvider normalized
     */
    #[DataProvider('normalized')]
    public function testNormalizeFoldsDotSegmentsBySpellingAlone(string $path, string $expected): void
    {
        self::assertSame($expected, Path::normalize($path));
    }

    /**
     * Two names for one file on disk: a hard link here, and on a case-insensitive filesystem any
     * spelling it folds to the same entry. Nothing that does not exist is the same file as anything.
     */
    public function testSameFileComparesWhatIsOnDisk(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir.'/a.json', 'a');
        file_put_contents($dir.'/b.json', 'b');
        link($dir.'/a.json', $dir.'/hard.json');
        symlink($dir.'/a.json', $dir.'/soft.json');
        try {
            self::assertTrue(Path::sameFile($dir.'/a.json', $dir.'/a.json'));
            self::assertTrue(Path::sameFile($dir.'/a.json', $dir.'/hard.json'));
            self::assertTrue(Path::sameFile($dir.'/soft.json', $dir.'/a.json'), 'a symlink resolves to its target');
            self::assertFalse(Path::sameFile($dir.'/a.json', $dir.'/b.json'));
            self::assertFalse(Path::sameFile($dir.'/a.json', $dir.'/missing.json'));
            self::assertFalse(Path::sameFile($dir.'/missing.json', $dir.'/a.json'));
            self::assertFalse(Path::sameFile($dir.'/missing.json', $dir.'/missing.json'));
        } finally {
            foreach (['a', 'b', 'hard', 'soft'] as $name) {
                unlink($dir.'/'.$name.'.json');
            }
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function windowsAliases(): iterable
    {
        yield 'a trailing dot' => ['/app/composer.lock.', true];
        yield 'trailing dots' => ['/app/composer.lock..', true];
        yield 'a trailing space' => ['/app/composer.lock ', true];
        yield 'a dot then a space' => ['/app/composer.lock. ', true];
        yield 'the default data stream' => ['/app/composer.lock::$DATA', true];
        yield 'a named stream' => ['/app/r.json:stream', true];
        yield 'a backslash-separated Windows path' => ['C:\\app\\composer.lock.', true];
        yield 'a drive-relative name' => ['C:r.json', true];
        yield 'a plain name' => ['/app/r.json', false];
        yield 'a drive letter in an earlier component' => ['C:\\app\\r.json', false];
        yield 'a colon in an earlier component' => ['/a:b/r.json', false];
        yield 'a dot inside the name' => ['/app/r.v1.json', false];
        yield 'a space inside the name' => ['/app/my report.json', false];
        yield 'a leading dot' => ['/app/.lockrot.json', false];
        yield 'a trailing dot in the directory only' => ['/app./r.json', false];
    }

    /**
     * Win32 drops trailing dots and spaces from a name and reads `name::$DATA` as the file itself,
     * so such a last component is another spelling of a different name. The rule is on the string,
     * so it holds on every system.
     *
     * @dataProvider windowsAliases
     */
    #[DataProvider('windowsAliases')]
    public function testIsWindowsAliasLooksAtTheLastComponentOnly(string $path, bool $alias): void
    {
        self::assertSame($alias, Path::isWindowsAlias($path));
    }
}
