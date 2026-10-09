<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Score;

use Lockrot\Score\Accepted;
use Lockrot\Score\Modifier;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ScoreSweep;
use PHPUnit\Framework\TestCase;

final class AcceptedTest extends TestCase
{
    /** The worked row "an accepted flag after the line": stale counted would corroborate old-promise. */
    public function testAnAcceptedFlagWritesItsRerunInOrder(): void
    {
        $accepted = ScoreSweep::basis(['axis' => 'base', 'flags' => ['old-promise', 'stale'], 'advisories' => [], 'reach' => 'direct', 'dev' => false, 'under' => null, 'accepted' => 'stale'])->accepted();

        self::assertCount(1, $accepted);
        self::assertSame(['flag' => 'stale', 'weight' => 8, 'if_counted' => ['total' => 18, 'verdict' => 'high', 'role' => 'corroborating', 'at_least' => false, 'modifiers' => []]], JsonPath::decoded($accepted[0]));
        self::assertSame('stale', $accepted[0]->flag());
    }

    /** The worked row "score 0, an accepted flag, halved": the rerun keeps its halvings. */
    public function testTheRerunOfAHalvedScoreWritesItsModifiers(): void
    {
        $accepted = ScoreSweep::basis(['axis' => 'base', 'flags' => ['stale'], 'advisories' => [], 'reach' => 'transitive', 'dev' => true, 'under' => null, 'accepted' => 'stale'])->accepted();

        self::assertCount(1, $accepted);
        self::assertSame(['flag' => 'stale', 'weight' => 8, 'if_counted' => ['total' => 2, 'verdict' => 'low', 'role' => 'lead', 'at_least' => false, 'modifiers' => [
            ['reason' => 'transitive', 'applies_to' => 'maintenance', 'divide_by' => 2, 'before' => 8, 'after' => 4],
            ['reason' => 'dev', 'applies_to' => 'total', 'divide_by' => 2, 'before' => 4, 'after' => 2],
        ]]], JsonPath::decoded($accepted[0]));
        self::assertCount(2, $accepted[0]->modifiers());
    }

    public function testARoleOfNullIsWritten(): void
    {
        $accepted = new Accepted('silent', 32, 0, 'finished', null, true, [Modifier::dev(0, 0)]);

        self::assertSame(['flag' => 'silent', 'weight' => 32, 'if_counted' => ['total' => 0, 'verdict' => 'finished', 'role' => null, 'at_least' => true, 'modifiers' => [['reason' => 'dev', 'applies_to' => 'total', 'divide_by' => 2, 'before' => 0, 'after' => 0]]]], JsonPath::decoded($accepted));
    }
}
