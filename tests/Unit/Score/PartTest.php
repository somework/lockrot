<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Score;

use Lockrot\Score\Part;
use Lockrot\Score\SecurityPart;
use Lockrot\Tests\Support\ScoreSweep;
use PHPUnit\Framework\TestCase;

final class PartTest extends TestCase
{
    /** The worked row "a tie at 32: severity order decides": two advisories count, no maintenance flag. */
    public function testBothPartsWriteTheirKeysInOrder(): void
    {
        $basis = ScoreSweep::basis(['axis' => 'base', 'flags' => [], 'advisories' => [['high', 'none'], ['critical', 'update']], 'reach' => 'direct', 'dev' => false, 'under' => null, 'accepted' => null]);

        self::assertSame(['status' => 'none', 'contribution' => 0, 'alone' => ['total' => 0, 'verdict' => null]], $basis->maintenancePart()->toArray());
        self::assertSame(['status' => 'counted', 'contribution' => 32, 'alone' => ['total' => 32, 'verdict' => 'critical'], 'of' => 2, 'tied' => ['A0']], $basis->securityPart()->toArray());
        self::assertFalse($basis->maintenancePart()->isCounted());
        self::assertTrue($basis->securityPart()->isCounted());
        self::assertSame(2, $basis->securityPart()->of());
    }

    public function testAHalfContributionIsWrittenAsAHalf(): void
    {
        self::assertSame(['status' => 'counted', 'contribution' => 4.5, 'alone' => ['total' => 4, 'verdict' => 'low']], (new Part('counted', 9))->toArray());
        self::assertSame(['status' => 'clear', 'contribution' => 0, 'alone' => ['total' => 0, 'verdict' => null], 'of' => 0, 'tied' => []], (new SecurityPart(new Part('clear', 0), 0, []))->toArray());
        self::assertFalse((new SecurityPart(new Part('clear', 0), 0, []))->isCounted());
    }
}
