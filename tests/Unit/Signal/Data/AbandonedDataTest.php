<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Data;

use Lockrot\Json\JsonWriter;
use Lockrot\Signal\Data\AbandonedData;
use PHPUnit\Framework\TestCase;

/** The S1 data of doctrine/cache 2.2.0 in the PrestaShop fixture app. */
final class AbandonedDataTest extends TestCase
{
    public function testItWritesTheS1KeysInOrderWithTheirNulls(): void
    {
        $data = new AbandonedData('repository', null, null);

        self::assertSame('{"marked_by":"repository","replacement":null,"replacement_url":null}', JsonWriter::encode($data, \JSON_UNESCAPED_SLASHES));
        self::assertSame('repository', $data->markedBy());
        self::assertNull($data->replacement());
    }

    public function testItKeepsTheReplacementAndItsPage(): void
    {
        $data = new AbandonedData('lock', 'acme/next', 'https://packagist.org/packages/acme/next');

        self::assertSame('{"marked_by":"lock","replacement":"acme/next","replacement_url":"https://packagist.org/packages/acme/next"}', JsonWriter::encode($data, \JSON_UNESCAPED_SLASHES));
        self::assertSame('acme/next', $data->replacement());
    }
}
