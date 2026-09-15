<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Json;

use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;
use PHPUnit\Framework\TestCase;

final class JsonReaderTest extends TestCase
{
    /** @var list<string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            @chmod($path, 0644);
            @unlink($path);
        }
        $this->tempPaths = [];
    }

    public function testReadsValidObject(): void
    {
        $path = $this->tempJson('{"a": 1, "b": "two"}');

        self::assertSame(['a' => 1, 'b' => 'two'], JsonReader::readObject($path));
    }

    public function testMissingFileThrows(): void
    {
        $path = sys_get_temp_dir().'/lockrot-jsonreader-missing-'.uniqid().'.json';

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($path.' not found');
        JsonReader::readObject($path);
    }

    /**
     * Composer's JsonFile::read() throws \RuntimeException when the file cannot be read (as opposed
     * to not existing at all). A directory path is rejected earlier by the "not found" check
     * (is_file() is false for directories too), so this branch needs a path that IS a regular file
     * but is not readable. chmod(0000) does not stop root, hence the skip.
     *
     * Composer's own permission probe (Filesystem::isReadable(), via Silencer) triggers a
     * "Permission denied" PHP warning that its error_reporting-based suppression does not keep from
     * PHPUnit (failOnWarning is on for this project); the temporary no-op error handler keeps that
     * warning from ever reaching PHPUnit's handler.
     */
    public function testUnreadableFileThrows(): void
    {
        if (\function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('Cannot simulate an unreadable file while running as root.');
        }

        $path = $this->tempJson('{"a": 1}');
        chmod($path, 0000);

        set_error_handler(static function (): bool {
            return true;
        });
        try {
            JsonReader::readObject($path);
            self::fail('Expected a ConfigException.');
        } catch (ConfigException $e) {
            self::assertMatchesRegularExpression('{^Cannot read '.preg_quote($path, '{').': .+}', $e->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    public function testSyntaxErrorThrows(): void
    {
        $path = $this->tempJson('{not json');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('{is not valid JSON.*Parse error on line}s');
        JsonReader::readObject($path);
    }

    public function testInvalidUtf8ThrowsAsInvalidJson(): void
    {
        $path = $this->tempJson("{\"a\": \"\xB1\x31\"}");

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('{^'.preg_quote($path, '{').' is not valid JSON: .+}');
        JsonReader::readObject($path);
    }

    public function testTopLevelListIsRejected(): void
    {
        $path = $this->tempJson('[1,2]');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($path.' must contain a JSON object');
        JsonReader::readObject($path);
    }

    public function testIntegerKeysAreDropped(): void
    {
        $path = $this->tempJson('{"123": 1}');

        self::assertSame([], JsonReader::readObject($path));
    }

    private function tempJson(string $contents): string
    {
        $path = sys_get_temp_dir().'/lockrot-jsonreader-'.uniqid().'.json';
        file_put_contents($path, $contents);
        $this->tempPaths[] = $path;

        return $path;
    }
}
