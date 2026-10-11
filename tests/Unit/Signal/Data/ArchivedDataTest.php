<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Data;

use Lockrot\Json\JsonWriter;
use Lockrot\Signal\Data\ArchivedData;
use PHPUnit\Framework\TestCase;

/** The S3 data of lox/xhprof in the matomo fixture app, with `--dev`. */
final class ArchivedDataTest extends TestCase
{
    public function testItWritesTheS3KeysInOrder(): void
    {
        $data = new ArchivedData('lox/xhprof', 'github.com', 'github');

        self::assertSame('{"repo":"lox/xhprof","host":"github.com","forge":"github"}', JsonWriter::encode($data, \JSON_UNESCAPED_SLASHES));
        self::assertSame('github', $data->forge());
    }
}
