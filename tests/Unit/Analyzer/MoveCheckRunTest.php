<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Analyzer\MoveCheckRun;
use Lockrot\Solver\MoveCheck;
use PHPUnit\Framework\TestCase;

final class MoveCheckRunTest extends TestCase
{
    public function testTheRunBlockNamesTheComposerItsBlockingAndTheBaselineWithItsReason(): void
    {
        $run = new MoveCheckRun('2.10.3', true, MoveCheck::notChecked(MoveCheck::OFFLINE));

        self::assertSame(['composer' => '2.10.3', 'blocks_advisories' => true, 'ignores_extensions' => true, 'baseline' => 'not_checked', 'reason' => 'offline'], $run->jsonSerialize());
    }
}
