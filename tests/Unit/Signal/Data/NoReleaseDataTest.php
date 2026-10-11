<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Data;

use Lockrot\Json\JsonWriter;
use Lockrot\Signal\Data\NoReleaseData;
use PHPUnit\Framework\TestCase;

/** The S2 data of socialiteproviders/discord in the BookStack fixture app, with `--dev`. */
final class NoReleaseDataTest extends TestCase
{
    public function testItWritesTheS2KeysInOrderWithTheirNulls(): void
    {
        $data = new NoReleaseData('2023-07-24T23:28:47+00:00', '4.2.0', 3.2, null);

        self::assertSame('{"last_release":"2023-07-24T23:28:47+00:00","last_version":"4.2.0","years":3.2,"dated_by":null}', JsonWriter::encode($data, \JSON_UNESCAPED_SLASHES));
        self::assertSame('2023-07-24T23:28:47+00:00', $data->lastRelease());
        self::assertSame(3.2, $data->years());
        self::assertNull($data->datedBy());
        self::assertSame('acme/monorepo', (new NoReleaseData('2023-07-24T23:28:47+00:00', null, 3.2, 'acme/monorepo'))->datedBy());
    }
}
