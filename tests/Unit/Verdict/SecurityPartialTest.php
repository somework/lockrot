<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Score\IfApplied;
use Lockrot\Verdict\SecurityPartial;
use PHPUnit\Framework\TestCase;

final class SecurityPartialTest extends TestCase
{
    public function testThePartialKeepsItsIfAppliedAsABranchRow(): void
    {
        $partial = new SecurityPartial('1.2.3', [], [], new IfApplied(4, 'low', null, 'PKSA-1', false, ['clears_hold']));

        self::assertSame(['total' => 4, 'verdict' => 'low', 'at_most' => false, 'assumes' => ['clears_hold']], $partial->ifApplied()->jsonSerialize());
    }
}
