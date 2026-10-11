<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal;

use Lockrot\Json\JsonWriter;
use Lockrot\Signal\Data\ArchivedData;
use Lockrot\Signal\Signal;
use PHPUnit\Framework\TestCase;

/** `findings[].signals[]`: the id, the level, the summary and the data that the rule built. */
final class SignalTest extends TestCase
{
    public function testItWritesItsKeysInOrderWithTheData(): void
    {
        $data = new ArchivedData('lox/xhprof', 'github.com', 'github');
        $signal = new Signal(Signal::S3, Signal::LEVEL_HIGH, 'repository archived on GitHub', $data);

        self::assertSame('{"id":"S3","level":"high","summary":"repository archived on GitHub","data":{"repo":"lox/xhprof","host":"github.com","forge":"github"}}', JsonWriter::encode($signal, \JSON_UNESCAPED_SLASHES));
        self::assertSame($data, $signal->data());
    }

    public function testASignalWithoutDataWritesAnEmptyList(): void
    {
        $signal = new Signal(Signal::S2, Signal::LEVEL_WARN, 'last release 2022-05-20');

        self::assertSame('{"id":"S2","level":"warn","summary":"last release 2022-05-20","data":[]}', JsonWriter::encode($signal, 0));
        self::assertNull($signal->data());
    }
}
