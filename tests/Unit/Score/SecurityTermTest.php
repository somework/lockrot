<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Score;

use Lockrot\Score\SecurityTerm;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ScoreSweep;
use PHPUnit\Framework\TestCase;

final class SecurityTermTest extends TestCase
{
    /** The worked row "abandoned + a critical advisory nothing fixes": no reachable fix doubles the weight. */
    public function testADoubledAdvisoryWritesItsKeysInOrder(): void
    {
        $terms = ScoreSweep::basis(['axis' => 'base', 'flags' => ['abandoned'], 'advisories' => [['critical', 'none']], 'reach' => 'direct', 'dev' => false, 'under' => null, 'accepted' => null])->terms();

        self::assertCount(2, $terms);
        self::assertInstanceOf(SecurityTerm::class, $terms[1]);
        self::assertSame(['part' => 'security', 'flag' => 'vulnerable', 'role' => 'security', 'advisory' => 'A0', 'severity' => 'critical', 'fix_kind' => 'none', 'weight' => 32, 'multiplier' => 2, 'points' => 64, 'contribution' => 64], JsonPath::decoded($terms[1]));
    }

    /** The worked row "twig as packages-dev": dev halves the contribution, not the points. */
    public function testDevHalvesOnlyTheContribution(): void
    {
        $term = ScoreSweep::basis(['axis' => 'base', 'flags' => [], 'advisories' => [['critical', 'raise-php']], 'reach' => 'direct', 'dev' => true, 'under' => null, 'accepted' => null])->terms()[0];

        self::assertInstanceOf(SecurityTerm::class, $term);
        self::assertSame(['part' => 'security', 'flag' => 'vulnerable', 'role' => 'security', 'advisory' => 'A0', 'severity' => 'critical', 'fix_kind' => 'raise-php', 'weight' => 32, 'multiplier' => 1, 'points' => 32, 'contribution' => 16], JsonPath::decoded($term));
        self::assertSame('critical', $term->severity());
        self::assertSame(1, $term->multiplier());
        self::assertSame(32, $term->weight());
        self::assertSame(32, $term->points());
        self::assertSame('vulnerable', $term->flag());
        self::assertSame('security', $term->role());
    }
}
