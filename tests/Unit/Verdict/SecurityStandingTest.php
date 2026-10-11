<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Verdict\SecurityStanding;
use PHPUnit\Framework\TestCase;

final class SecurityStandingTest extends TestCase
{
    public function testAVulnerableStandingHasAFixKind(): void
    {
        $standing = SecurityStanding::vulnerable('complete', 1, ['high' => 2], 'update');

        self::assertSame(['vulnerable', true, 'update', 'complete', 1, ['high' => 2]], [$standing->status(), $standing->isVulnerable(), $standing->fixKind(), $standing->check(), $standing->ignoredCount(), $standing->counts()]);
    }

    public function testAStandingWithoutACountedAdvisoryIsClearOnlyAfterACompleteCheck(): void
    {
        $clear = SecurityStanding::notVulnerable('complete', 0, ['high' => 0]);
        $partial = SecurityStanding::notVulnerable('partial', 2, ['high' => 0]);
        $notRun = SecurityStanding::notVulnerable('not_run', 0, ['high' => 0]);

        self::assertSame(['clear', false, null], [$clear->status(), $clear->isVulnerable(), $clear->fixKind()]);
        self::assertSame(['unchecked', false, null, 2], [$partial->status(), $partial->isVulnerable(), $partial->fixKind(), $partial->ignoredCount()]);
        self::assertSame(['unchecked', 'not_run'], [$notRun->status(), $notRun->check()]);
    }
}
