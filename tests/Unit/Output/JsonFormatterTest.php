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

    public function testJsonCarriesThePriorityOnEveryFindingAndTheReportTotals(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T00:00:00+00:00');
        $report = new Report(
            [
                new Finding('a/direct', '1.0.0', Verdict::ABANDONED, [], ['a/direct'], null, $at),
                new Finding('a/dev', '1.0.0', Verdict::ABANDONED, [], ['a/root', 'a/dev'], null, $at, null, true),
            ],
            [],
            $at,
            2,
            0,
            false
        );
        $json = json_decode((new JsonFormatter())->format($report), true);
        self::assertIsArray($json);
        self::assertIsArray($json['findings']);
        self::assertIsArray($json['findings'][0]);
        self::assertIsArray($json['findings'][1]);
        self::assertSame('critical', $json['findings'][0]['priority']);
        self::assertTrue($json['findings'][0]['direct']);
        self::assertFalse($json['findings'][0]['dev']);
        self::assertSame('medium', $json['findings'][1]['priority']);
        self::assertFalse($json['findings'][1]['direct']);
        self::assertTrue($json['findings'][1]['dev']);
        self::assertIsArray($json['priorities']);
        self::assertSame(['critical' => 1, 'high' => 0, 'medium' => 1, 'low' => 0, 'none' => 0], $json['priorities']);
        // Both fields are additive, so the schema number does not move.
        self::assertIsArray($json['lockrot']);
        self::assertSame(1, $json['lockrot']['schema']);
    }

    public function testFactory(): void
    {
        self::assertInstanceOf(TableFormatter::class, Formatters::for('table'));
        self::assertInstanceOf(JsonFormatter::class, Formatters::for('json'));
    }

    public function testJsonCarriesTheDirectDependentsAndTheExposureList(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T00:00:00+00:00');
        $report = new Report(
            [
                new Finding('a/leaf', '1.0.0', Verdict::STALE, [], ['a/root', 'a/leaf'], null, $at, null, false, ['a/root', 'b/root']),
                new Finding('a/root', '1.0.0', Verdict::OK, [], ['a/root'], null, $at, null, false, ['a/root']),
            ],
            [],
            $at,
            2,
            0,
            false
        );
        $json = json_decode((new JsonFormatter())->format($report), true);
        self::assertIsArray($json);
        self::assertSame(['a/root', 'b/root'], $json['findings'][0]['direct_dependents']);
        self::assertSame([['package' => 'a/root', 'flagged' => 1], ['package' => 'b/root', 'flagged' => 1]], $json['exposure']);

        $empty = new Report([new Finding('a/root', '1.0.0', Verdict::OK, [], ['a/root'], null, $at, null, false, ['a/root'])], [], $at, 1, 0, false);
        self::assertStringContainsString('"exposure": []', (new JsonFormatter())->format($empty));
    }
}
