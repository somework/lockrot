<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Data;

use Lockrot\Json\JsonWriter;
use Lockrot\Signal\Data\LeftBehindData;
use PHPUnit\Framework\TestCase;

/** The S8 data of doctrine/collections 1.8.0 in the PrestaShop fixture app, with `--dev`. */
final class LeftBehindDataTest extends TestCase
{
    public function testItWritesTheS8KeysInOrder(): void
    {
        $data = self::recorded();

        self::assertSame(
            '{"branch":"1.x","branch_last_release":"2022-09-01T20:12:10+00:00","branch_last_version":"1.8.0","years":4.1,"newest_branch":"3.x","newest_version":"3.1.0","newest_release":"2026-04-29T20:41:02+00:00","newest_php":"^8.4","newest_within_reach":false,"floor_php":">=8.1","floor_source":"project","reachable_branch":"2.x","reachable_version":"2.6.0","reachable_release":"2026-01-15T10:01:58+00:00","suggested_constraint":"^2.6","dated_by":null,"reachable_php":"^8.1","reachable_admits":{"project":true,"target":true},"newest_years":0.4,"reachable_years":0.7}',
            JsonWriter::encode($data, \JSON_UNESCAPED_SLASHES)
        );
        self::assertSame('1.x', $data->branch());
        self::assertSame('2022-09-01T20:12:10+00:00', $data->branchLastRelease());
        self::assertSame(4.1, $data->years());
        self::assertNull($data->datedBy());
        self::assertSame('3.x', $data->newestBranch());
        self::assertSame('3.1.0', $data->newestVersion());
        self::assertSame('2026-04-29T20:41:02+00:00', $data->newestRelease());
        self::assertSame('^8.4', $data->newestPhp());
        self::assertFalse($data->newestWithinReach());
        self::assertSame('>=8.1', $data->floorPhp());
        self::assertSame('project', $data->floorSource());
        self::assertSame('2.x', $data->reachableBranch());
        self::assertSame('2.6.0', $data->reachableVersion());
        self::assertSame('2026-01-15T10:01:58+00:00', $data->reachableRelease());
        self::assertSame('^2.6', $data->suggestedConstraint());
        self::assertSame('^8.1', $data->reachablePhp());
        self::assertSame(['project' => true, 'target' => true], $data->reachableAdmits());
        self::assertSame(0.4, $data->newestYears());
        self::assertSame(0.7, $data->reachableYears());
    }

    public function testWithNoBranchWithinReachItsKeysAreNull(): void
    {
        $data = new LeftBehindData('1.x', '2019-03-02T00:00:00+00:00', '1.9.2', 7.6, '3.x', '3.4.1', '2026-06-01T00:00:00+00:00', '>=8.5', false, '8.4', 'target', null, null, null, null, null, null, null, 0.3, null);

        self::assertStringEndsWith('"reachable_branch":null,"reachable_version":null,"reachable_release":null,"suggested_constraint":null,"dated_by":null,"reachable_php":null,"reachable_admits":null,"newest_years":0.3,"reachable_years":null}', (string) JsonWriter::encode($data, \JSON_UNESCAPED_SLASHES));
    }

    private static function recorded(): LeftBehindData
    {
        return new LeftBehindData(
            '1.x',
            '2022-09-01T20:12:10+00:00',
            '1.8.0',
            4.1,
            '3.x',
            '3.1.0',
            '2026-04-29T20:41:02+00:00',
            '^8.4',
            false,
            '>=8.1',
            'project',
            '2.x',
            '2.6.0',
            '2026-01-15T10:01:58+00:00',
            '^2.6',
            null,
            '^8.1',
            ['project' => true, 'target' => true],
            0.4,
            0.7
        );
    }
}
