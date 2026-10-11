<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Support;

use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Verdict\ScoreModel;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/** The stub builds only data that a rule can build, from keys that the data writes. */
final class SignalDataStubTest extends TestCase
{
    /** A misspelled key would take the neutral value and pass unseen. */
    public function testAKeyThatTheDataDoesNotWriteFails(): void
    {
        $this->expectException(AssertionFailedError::class);

        SignalDataStub::of(Signal::S6, ['verison' => 'dev-main']);
    }

    public function testAKeyThatTheRowDoesNotWriteFails(): void
    {
        $this->expectException(AssertionFailedError::class);

        SignalDataStub::row(['id' => 'PKSA-a', 'severty' => 'high']);
    }

    public function testARowWithoutPointsTakesThePointsOfItsSeverityAndFix(): void
    {
        $row = SignalDataStub::row(['severity' => 'high', 'fix' => ['kind' => 'blocked']]);

        self::assertSame(ScoreModel::advisoryPoints('high', 'blocked'), $row->points());
    }
}
