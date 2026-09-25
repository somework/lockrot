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
