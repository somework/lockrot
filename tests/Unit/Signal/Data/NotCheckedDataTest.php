<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Data;

use Lockrot\Json\JsonWriter;
use Lockrot\Signal\Data\Check;
use Lockrot\Signal\Data\NotCheckedData;
use PHPUnit\Framework\TestCase;

/** The S10 data of fakerphp/faker v1.24.1 in the BookStack fixture app, with `--dev`. */
final class NotCheckedDataTest extends TestCase
{
    public function testItWritesTheChecksAndTheSignalsTheyBlock(): void
    {
        $check = new Check('repository_activity', 'fetch_failed', ['S3', 'S4']);
        $data = new NotCheckedData([$check]);

        self::assertSame('{"unchecked":[{"check":"repository_activity","reason":"fetch_failed","blocks":["S3","S4"]}],"blocks":["S3","S4"]}', JsonWriter::encode($data, \JSON_UNESCAPED_SLASHES));
        self::assertSame([$check], $data->checks());
    }

    public function testTheBlocksListEachCheckInOrder(): void
    {
        $data = new NotCheckedData([new Check('repository_activity', 'no_token', ['S3', 'S4']), new Check('release_dates', 'undated_releases', ['S2', 'S8']), new Check('releases', 'releases_unknown', ['S9'])]);

        self::assertStringEndsWith('"blocks":["S3","S4","S2","S8","S9"]}', (string) JsonWriter::encode($data, 0));
    }

    public function testACheckWritesItsKeysInOrder(): void
    {
        $check = new Check('release_dates', 'undated_releases', ['S2']);

        self::assertSame('{"check":"release_dates","reason":"undated_releases","blocks":["S2"]}', JsonWriter::encode($check, 0));
        self::assertSame('release_dates', $check->check());
        self::assertSame('undated_releases', $check->reason());
        self::assertSame(['S2'], $check->blocks());
    }
}
