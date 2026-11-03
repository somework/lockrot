<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Support;

use Lockrot\Signal\Data\LeftBehindData;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Verdict\ScoreModel;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * S8 data holds no miss side, because the run rebuilds it (SPEC "The miss words of S8"): the
     * project floor at `run.project_php_lowest`, the target floor at `run.php_window.target.php`,
     * else at X.Y.99 of `run.target_php`. Each case gives a different side at the other point.
     *
     * @param array<string, mixed> $s8
     * @param array<string, mixed> $run
     *
     * @dataProvider missPoints
     */
    #[DataProvider('missPoints')]
    public function testTheS8MissSideReadsTheFloorPointOfTheRun(array $s8, array $run, ?string $side): void
    {
        $data = SignalDataStub::of(Signal::S8, $s8, $run);

        self::assertInstanceOf(LeftBehindData::class, $data);
        self::assertSame($side, $data->newestMisses());
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, ?string}> */
    public static function missPoints(): iterable
    {
        $target = ['floor_source' => 'target', 'floor_php' => '8.4'];
        $project = ['floor_source' => 'project', 'floor_php' => '>=8.2'];
        yield 'the target floor at the solve PHP of the window' => [
            $target + ['newest_php' => '<8.4.1 || >=8.4.5'], ['target_php' => '8.4', 'project_php_lowest' => null, 'php_window' => ['target' => ['php' => '8.4.1']]], PhpFloor::SKIPS,
        ];
        yield 'the target floor at X.Y.99 with no window' => [
            $target + ['newest_php' => '8.4.0 - 8.4.2'], ['target_php' => '8.4', 'project_php_lowest' => null, 'php_window' => null], PhpFloor::STOPS_BEFORE,
        ];
        yield 'the project floor at the lowest PHP of the project' => [
            $project + ['newest_php' => '8.2.0 - 8.2.2 || >=8.3'], ['target_php' => '8.4', 'project_php_lowest' => '8.2.3', 'php_window' => null], PhpFloor::SKIPS,
        ];
        yield 'a newest branch within reach misses no floor' => [
            $target + ['newest_php' => '>=8.5', 'newest_within_reach' => true], ['target_php' => '8.4', 'project_php_lowest' => null, 'php_window' => null], null,
        ];
    }

    public function testS8DataWithoutTheRunKeepsNoMissSide(): void
    {
        $data = SignalDataStub::of(Signal::S8, ['floor_source' => 'target', 'floor_php' => '8.4', 'newest_php' => '8.4.0 - 8.4.2']);

        self::assertInstanceOf(LeftBehindData::class, $data);
        self::assertNull($data->newestMisses());
    }
}
