<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Json;

use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;
use Lockrot\Tests\Support\UnreadableFiles;
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
        $path = sys_get_temp_dir().'/lockrot-jsonreader-missing-'.bin2hex(random_bytes(8)).'.json';

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($path.' not found');
        JsonReader::readObject($path);
    }

    /**
     * A directory path never reaches this branch: the "not found" check rejects it (is_file() is
     * false for directories), so the test needs a regular file that is not readable. chmod(0000)
     * does not stop root, hence the skip. Composer's permission probe raises a "Permission denied"
     * warning that PHPUnit reports (failOnWarning is on), so a no-op error handler hides it.
     */
    public function testUnreadableFileThrows(): void
    {
        // Infection's include interceptor reads a mode-0000 file as missing: testAFileThatExistsAndCannotBeReadIsRefused covers this branch.
        if (getenv('INFECTION') === '1') {
            self::markTestSkipped('a mode-0000 file reads as missing under Infection');
        }
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

    public function testAFileThatExistsAndCannotBeReadIsRefused(): void
    {
        $path = UnreadableFiles::path('a.json');

        set_error_handler(static function (): bool {
            return true;
        });
        try {
            JsonReader::readObject($path);
            self::fail('Expected a ConfigException.');
        } catch (ConfigException $e) {
            self::assertMatchesRegularExpression('{^Cannot read '.preg_quote($path, '{').': .+}', $e->getMessage());
            self::assertSame(0, $e->getCode());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        } finally {
            restore_error_handler();
        }
    }

    public function testSyntaxErrorThrows(): void
    {
        $path = $this->tempJson('{not json');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('{is not valid JSON.*Parse error on line}s');
        // The code is the parser's (none), not one of its own: ProjectConfig passes it on.
        $this->expectExceptionCode(0);
        JsonReader::readObject($path);
    }

    public function testInvalidUtf8ThrowsAsInvalidJson(): void
    {
        $path = $this->tempJson("{\"a\": \"\xB1\x31\"}");

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('{^'.preg_quote($path, '{').' is not valid JSON: .+}');
        $this->expectExceptionCode(0);
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
        $path = tempnam(sys_get_temp_dir(), 'lockrot-jsonreader-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->tempPaths[] = $path;

        return $path;
    }
}
