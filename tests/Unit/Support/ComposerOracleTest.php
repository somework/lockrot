<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Support;

use Lockrot\Tests\Support\ComposerOracle;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase;

final class ComposerOracleTest extends TestCase
{
    public function testRecordedVersionIsTheOneVersionOfTheSolverWorldCases(): void
    {
        $cases = ['cases' => [self::world('a', '2.10.3'), self::world('b', '2.10.3'), ['id' => 'c', 'inputs' => ['move_check' => ['composer' => '2.2.25']]]]];

        self::assertSame('2.10.3', ComposerOracle::recordedVersion($cases));
    }

    public function testRecordedVersionRefusesCasesThatRecordTwoVersionsAndNamesBoth(): void
    {
        $cases = ['cases' => [self::world('a', '2.10.3'), self::world('b', '2.11.0'), self::world('c', '2.10.3')]];

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('2.10.3, 2.11.0');

        ComposerOracle::recordedVersion($cases);
    }

    public function testRecordedVersionRefusesCasesWithoutASolverWorld(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        ComposerOracle::recordedVersion(['cases' => [['id' => 'c', 'inputs' => ['move_check' => ['composer' => '2.10.3']]]]]);
    }

    public function testGuardLetsTheRecordedVersionRun(): void
    {
        $this->expectNotToPerformAssertions();

        ComposerOracle::guard('2.10.3', '2.10.3', true);
    }

    public function testGuardSkipsAnotherVersionWhereTheRunDoesNotRequireTheOracle(): void
    {
        $thrown = self::thrownBy('2.10.3', '2.11.0', false);

        self::assertInstanceOf(SkippedTest::class, $thrown);
    }

    public function testGuardFailsAnotherVersionWhereTheRunRequiresTheOracle(): void
    {
        $thrown = self::thrownBy('2.10.3', '2.11.0', true);

        self::assertNotInstanceOf(SkippedTest::class, $thrown);
        self::assertStringContainsString('2.10.3', $thrown->getMessage());
        self::assertStringContainsString('2.11.0', $thrown->getMessage());
    }

    /** A new install of the same tag at another reference is no change (case mc-joint-retag‡), and a branch moves with its commit. */
    public function testChangesDropANameThatTheSolveInstallsAgainAtItsLockedTag(): void
    {
        $lock = ['packages' => [['name' => 'acme/h', 'version' => '1.0.0'], ['name' => 'acme/b', 'version' => 'dev-main']], 'packages-dev' => [['name' => 'acme/d', 'version' => '2.0.0']]];

        $changes = ComposerOracle::changes(['acme/h' => '1.0.0', 'acme/b' => 'dev-main', 'acme/d' => '2.1.0', 'guzzlehttp/guzzle' => '8.0.1'], $lock);

        self::assertSame(['acme/b' => 'dev-main', 'acme/d' => '2.1.0', 'guzzlehttp/guzzle' => '8.0.1'], $changes);
    }

    /** A skip is an AssertionFailedError subclass in PHPUnit 9 to 11: the caller tells the two apart. */
    private static function thrownBy(string $recorded, string $running, bool $required): AssertionFailedError
    {
        try {
            ComposerOracle::guard($recorded, $running, $required);
        } catch (AssertionFailedError $thrown) {
            return $thrown;
        }
        self::fail('the guard let '.$running.' run the answers of '.$recorded);
    }

    /** @return array<string, mixed> */
    private static function world(string $id, string $composer): array
    {
        return ['id' => $id, 'inputs' => ['move_check' => ['composer' => $composer], 'solver_world' => ['world_id' => $id]]];
    }
}
