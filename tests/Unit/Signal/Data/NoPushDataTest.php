<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Data;

use Lockrot\Json\JsonWriter;
use Lockrot\Signal\Data\NoPushData;
use PHPUnit\Framework\TestCase;

/** The S4 data of willdurand/jsonp-callback-validator v1.1.0 in the PrestaShop fixture app, with `--dev`. */
final class NoPushDataTest extends TestCase
{
    public function testItWritesTheS4KeysInOrder(): void
    {
        $data = new NoPushData('2023-07-29T16:52:43+00:00', 'willdurand/JsonpCallbackValidator', 'github.com', 3.2, 'github', 'push');

        self::assertSame('{"last_push":"2023-07-29T16:52:43+00:00","repo":"willdurand/JsonpCallbackValidator","host":"github.com","years":3.2,"forge":"github","activity":"push"}', JsonWriter::encode($data, \JSON_UNESCAPED_SLASHES));
        self::assertSame('2023-07-29T16:52:43+00:00', $data->lastPush());
        self::assertSame(3.2, $data->years());
        self::assertSame('push', $data->activity());
    }
}
