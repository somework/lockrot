<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Output\Formatters;
use Lockrot\Output\JsonFormatter;
use Lockrot\Output\TableFormatter;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class JsonFormatterTest extends TestCase
{
    public function testJsonShape(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T00:00:00+00:00');
        $report = new Report([new Finding('a/b', '1.0.0', Verdict::STALE, [], ['a/b'], null, $at)], ['n'], $at, 1, 0, false);
        $json = json_decode((new JsonFormatter())->format($report), true);
        self::assertIsArray($json);
        self::assertIsArray($json['lockrot']);
        self::assertIsArray($json['findings']);
        self::assertIsArray($json['findings'][0]);
        self::assertIsArray($json['counts']);
        self::assertSame(1, $json['lockrot']['schema']);
        self::assertSame('stale', $json['findings'][0]['verdict']);
        self::assertSame(['n'], $json['notes']);
        self::assertSame(1, $json['counts']['stale']);
    }

    public function testFactory(): void
    {
        self::assertInstanceOf(TableFormatter::class, Formatters::for('table'));
        self::assertInstanceOf(JsonFormatter::class, Formatters::for('json'));
    }
}
