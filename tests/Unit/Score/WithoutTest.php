<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Score;

use Lockrot\Score\Without;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ScoreSweep;
use PHPUnit\Framework\TestCase;

final class WithoutTest extends TestCase
{
    /** The worked row "left-behind + stale, transitive, dev: rounded down": one row per counted flag. */
    public function testEachCountedFlagHasARowInOrder(): void
    {
        $without = ScoreSweep::basis(['axis' => 'base', 'flags' => ['left-behind', 'stale'], 'advisories' => [], 'reach' => 'transitive', 'dev' => true, 'under' => null, 'accepted' => null])->without();

        self::assertSame([
            ['remove' => ['kind' => 'flag', 'id' => 'left-behind'], 'revealed' => [], 'total' => 2, 'verdict' => 'low', 'lead' => 'stale', 'deciding_advisory' => null, 'at_least' => false],
            ['remove' => ['kind' => 'flag', 'id' => 'stale'], 'revealed' => [], 'total' => 4, 'verdict' => 'low', 'lead' => 'left-behind', 'deciding_advisory' => null, 'at_least' => false],
        ], array_map(static fn (Without $row): array => JsonPath::decoded($row), $without));
    }

    /** The worked row "a tie at 32: severity order decides": the deciding advisory has a row, the lead is null. */
    public function testTheDecidingAdvisoryHasARowWithNoLead(): void
    {
        $without = ScoreSweep::basis(['axis' => 'base', 'flags' => [], 'advisories' => [['high', 'none'], ['critical', 'update']], 'reach' => 'direct', 'dev' => false, 'under' => null, 'accepted' => null])->without();

        self::assertCount(1, $without);
        self::assertSame(['remove' => ['kind' => 'advisory', 'id' => 'A1'], 'revealed' => [], 'total' => 32, 'verdict' => 'critical', 'lead' => null, 'deciding_advisory' => 'A0', 'at_least' => false], JsonPath::decoded($without[0]));
    }

    /** Removing `abandoned` reveals the liveness word that it hid. */
    public function testRemovingAbandonedRevealsTheHiddenWord(): void
    {
        $without = ScoreSweep::basis(['axis' => 'base', 'flags' => ['abandoned', 'old-promise'], 'advisories' => [], 'reach' => 'direct', 'dev' => false, 'under' => 'silent', 'accepted' => null])->without();

        self::assertSame(['kind' => 'flag', 'id' => 'abandoned'], JsonPath::decoded($without[0])['remove']);
        self::assertSame([['flag' => 'silent', 'role' => 'lead']], JsonPath::decoded($without[0])['revealed']);
    }
}
