<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Security;

use Lockrot\Data\Repository\StableRelease;
use Lockrot\Security\Candidate;
use Lockrot\Security\Fix;
use Lockrot\Security\Holder;
use PHPUnit\Framework\TestCase;

final class CandidateTest extends TestCase
{
    /** A refused release sits between its own ease step and the next one: a refused `upgrade` still beats a free `raise-php`. */
    public function testARefusedReleaseRanksAfterItsEaseStepAndBeforeTheNextOne(): void
    {
        $release = new StableRelease('2.0.0.0', '2.0.0', null, null, false);
        $held = [new Holder(Holder::ROOT, null, null, Holder::REQUIRE, '^1.0')];

        self::assertSame(
            [0, 2, 3, 4],
            [
                (new Candidate($release, '2', Fix::UPDATE, []))->easeRank(),
                (new Candidate($release, '2', Fix::UPGRADE, $held, false))->easeRank(),
                (new Candidate($release, '2', Fix::UPGRADE, $held, true))->easeRank(),
                (new Candidate($release, '2', Fix::RAISE_PHP, []))->easeRank(),
            ],
            'update, upgrade, refused upgrade, raise-php'
        );
    }
}
