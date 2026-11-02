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
        $run = new MoveCheckRun('2.10.3', true, [], MoveCheck::notChecked(MoveCheck::OFFLINE));

        self::assertSame(['composer' => '2.10.3', 'blocks_advisories' => true, 'blocking_disabled_by' => [], 'ignored_platform' => ['ext-*', 'lib-*', 'php-debug', 'php-ipv6', 'php-zts'], 'baseline' => 'not_checked', 'reason' => 'offline'], $run->jsonSerialize());
    }

    public function testTheRunBlockListsTheVariablesThatTurnedTheBlockingOff(): void
    {
        $run = new MoveCheckRun('2.10.3', false, ['COMPOSER_NO_BLOCKING', 'COMPOSER_NO_SECURITY_BLOCKING'], MoveCheck::notChecked(MoveCheck::OFFLINE));

        self::assertSame(['COMPOSER_NO_BLOCKING', 'COMPOSER_NO_SECURITY_BLOCKING'], $run->jsonSerialize()['blocking_disabled_by']);
        self::assertFalse($run->jsonSerialize()['blocks_advisories']);
    }
}
