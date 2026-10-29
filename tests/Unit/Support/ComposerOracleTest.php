<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Support;

use Lockrot\Tests\Support\ComposerOracle;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase;

final class ComposerOracleTest extends TestCase
{
    public function testRecordedVersionsAreTheVersionsOfTheSolverWorldCasesInVersionOrder(): void
    {
        $cases = ['cases' => [self::world('a', '2.10.3'), self::world('b', '2.3.10'), self::world('c', '2.10.3'), ['id' => 'd', 'run' => ['move_check' => ['composer' => '2.2.25']]]]];

        self::assertSame(['2.3.10', '2.10.3'], ComposerOracle::recordedVersions($cases));
    }

    public function testRecordedVersionsRefuseASolverWorldCaseWithoutAVersion(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        ComposerOracle::recordedVersions(['cases' => [self::world('a', '2.10.3'), ['id' => 'b', 'solver_world' => ['world_id' => 'b'], 'run' => ['move_check' => []]]]]);
    }

    public function testRecordedVersionsRefuseCasesWithoutASolverWorld(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        ComposerOracle::recordedVersions(['cases' => [['id' => 'c', 'run' => ['move_check' => ['composer' => '2.10.3']]]]]);
    }

    public function testGuardLetsTheRecordedVersionRun(): void
    {
        $this->expectNotToPerformAssertions();

        ComposerOracle::guard('2.10.3', '2.10.3', true, ['2.10.3']);
    }

    public function testGuardSkipsAnotherVersionWhereTheRunDoesNotRequireTheOracle(): void
    {
        $thrown = self::thrownBy('2.10.3', '2.12.0', false, ['2.10.3']);

        self::assertInstanceOf(SkippedTest::class, $thrown);
    }

    /** One run per recorded version: each run skips the worlds of the other versions, and says which. */
    public function testGuardSkipsTheWorldOfAnotherRecordedVersionAndNamesItsVersion(): void
    {
        $thrown = self::thrownBy('2.3.10', '2.10.3', true, ['2.3.10', '2.10.3']);

        self::assertInstanceOf(SkippedTest::class, $thrown);
        self::assertStringContainsString('2.3.10', $thrown->getMessage());
    }

    public function testGuardFailsAVersionThatNoWorldRecordsWhereTheRunRequiresTheOracle(): void
    {
        $thrown = self::thrownBy('2.10.3', '2.12.0', true, ['2.3.10', '2.10.3']);

        self::assertNotInstanceOf(SkippedTest::class, $thrown);
        self::assertStringContainsString('2.10.3', $thrown->getMessage());
        self::assertStringContainsString('2.12.0', $thrown->getMessage());
    }

    /** The 2.11.0 CLI gave every answer that the 2.10.3 CLI recorded, so 2.11.0 runs the 2.10.3 worlds. */
    public function testGuardLetsAVersionThatGaveTheSameAnswersRunTheRecordedWorlds(): void
    {
        $this->expectNotToPerformAssertions();

        ComposerOracle::guard('2.10.3', '2.11.0', true, ['2.10.3']);
    }

    public function testGuardSkipsAVersionThatGaveTheSameAnswersAsAnotherRecordedVersion(): void
    {
        $thrown = self::thrownBy('2.3.10', '2.11.0', true, ['2.3.10', '2.10.3']);

        self::assertInstanceOf(SkippedTest::class, $thrown);
    }

    public function testTheVersionsToRunAreTheRecordedOnesAndTheVersionsThatGaveTheirAnswers(): void
    {
        self::assertSame(['2.10.3', '2.11.0'], ComposerOracle::versionsToRun(['2.10.3']));
        self::assertSame(['2.3.10'], ComposerOracle::versionsToRun(['2.3.10']));
    }

    public function testAVersionThatIsRecordedAndGaveTheAnswersOfAnotherRecordedVersionRunsOnce(): void
    {
        self::assertSame(['2.10.3', '2.11.0'], ComposerOracle::versionsToRun(['2.10.3', '2.11.0']));
    }

    /** A new install of the same tag at another reference is no change (case mc-joint-retag‡), and a branch moves with its commit. */
    public function testChangesDropANameThatTheSolveInstallsAgainAtItsLockedTag(): void
    {
        $lock = ['packages' => [['name' => 'acme/h', 'version' => '1.0.0'], ['name' => 'acme/b', 'version' => 'dev-main']], 'packages-dev' => [['name' => 'acme/d', 'version' => '2.0.0']]];

        $changes = ComposerOracle::changes(['acme/h' => '1.0.0', 'acme/b' => 'dev-main', 'acme/d' => '2.1.0', 'guzzlehttp/guzzle' => '8.0.1'], $lock);

        self::assertSame(['acme/b' => 'dev-main', 'acme/d' => '2.1.0', 'guzzlehttp/guzzle' => '8.0.1'], $changes);
    }

    /** A skip is an AssertionFailedError subclass in PHPUnit 9 to 11: the caller tells the two apart. */
    /** @param list<string> $recordedVersions */
    private static function thrownBy(string $recorded, string $running, bool $required, array $recordedVersions): AssertionFailedError
    {
        try {
            ComposerOracle::guard($recorded, $running, $required, $recordedVersions);
        } catch (AssertionFailedError $thrown) {
            return $thrown;
        }
        self::fail('the guard let '.$running.' run the answers of '.$recorded);
    }

    /** @return array<string, mixed> */
    private static function world(string $id, string $composer): array
    {
        return ['id' => $id, 'run' => ['move_check' => ['composer' => $composer]], 'solver_world' => ['world_id' => $id]];
    }
}
