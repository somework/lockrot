<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Json;

use Lockrot\Json\JsonWriter;
use PHPUnit\Framework\TestCase;

final class JsonWriterTest extends TestCase
{
    private string $previous;

    protected function setUp(): void
    {
        $this->previous = (string) \ini_get('serialize_precision');
    }

    protected function tearDown(): void
    {
        ini_set('serialize_precision', $this->previous);
    }

    public function testItWritesTheShortestFloatWhateverTheInheritedPrecision(): void
    {
        ini_set('serialize_precision', '17');

        self::assertSame('{"years":6.9,"whole":5}', JsonWriter::encode(['years' => 69 / 10, 'whole' => 50 / 10], 0));
        self::assertSame('17', \ini_get('serialize_precision'), 'the caller\'s precision is restored');
    }

    public function testItKeepsTheFlagsItIsGiven(): void
    {
        self::assertSame("{\n    \"a\": \"b/c\",\n    \"r\": 5.0\n}", JsonWriter::encode(['a' => 'b/c', 'r' => 5.0], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION));
        self::assertSame('"b\/c"', JsonWriter::encode('b/c', 0));
    }

    public function testAFailureIsNullAndStillRestoresThePrecision(): void
    {
        ini_set('serialize_precision', '17');

        self::assertNull(JsonWriter::encode("\xB1\x31", 0));
        self::assertSame('Malformed UTF-8 characters, possibly incorrectly encoded', json_last_error_msg());
        self::assertSame('17', \ini_get('serialize_precision'));
    }

    public function testAThrownFailureStillRestoresThePrecision(): void
    {
        ini_set('serialize_precision', '17');

        try {
            JsonWriter::encode("\xB1\x31", \JSON_THROW_ON_ERROR);
            self::fail('JSON_THROW_ON_ERROR throws');
        } catch (\JsonException $e) {
            self::assertSame('17', \ini_get('serialize_precision'));
        }
    }

    public function testEveryJsonEncodeInSrcGoesThroughTheWriter(): void
    {
        $src = \dirname(__DIR__, 3).'/src';
        $calls = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            self::assertIsString($code);
            foreach (token_get_all($code) as $token) {
                // T_STRING, or PHP 8's one-token `\json_encode`: a comment's mention is a token of its own and never matches.
                if (\is_array($token) && strtolower(ltrim($token[1], '\\')) === 'json_encode') {
                    $calls[] = substr($file->getPathname(), \strlen($src) + 1).':'.$token[2];
                }
            }
        }

        self::assertCount(1, $calls, implode(', ', $calls));
        self::assertStringStartsWith('Json/JsonWriter.php:', $calls[0]);
    }
}
