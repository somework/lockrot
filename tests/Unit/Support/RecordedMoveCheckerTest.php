<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Support;

use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveQuery;
use Lockrot\Tests\Support\RecordedMoveChecker;
use PHPUnit\Framework\TestCase;

/**
 * A train 7d case records Composer's answer to every request that the model asked. The notes of
 * the move check quote the problem text, so the recorded text must reach the report, and a request
 * that the case does not record must not pass as `not_checked`.
 */
final class RecordedMoveCheckerTest extends TestCase
{
    private const PROBLEM = "  Problem 1\n    - acme/a 1.0.0 conflicts with acme/b >=2.0.";

    public function testARefusalReturnsTheRecordedProblemText(): void
    {
        $checker = new RecordedMoveChecker(['update acme/b ^2.0 ' => ['result' => 'refused', 'form' => null, 'holds' => [], 'problem' => self::PROBLEM]], [], true);

        $outcome = $checker->check(MoveQuery::update('acme/b', '^2.0', '2.0.0'));

        self::assertTrue($outcome->check()->isRefused());
        self::assertSame(self::PROBLEM, $outcome->problem());
    }

    /** SPEC §5.6 (ruling Q2), case `mc-lookup-incomplete‡`: the world's answer is Composer's, and lockrot reads it as SolverChecker does. */
    public function testAResolvingSolveThatInstallsANameOutsideTheLookupIsNotCheckedWhileComposerBlocksAdvisories(): void
    {
        $answer = ['result' => 'resolves', 'form' => MoveCheck::INLINE, 'installs' => ['acme/b' => '2.0.0', 'acme/new' => '1.0.0'], 'holds' => [], 'problem' => null];
        $checks = ['update acme/b ^2.0 ' => $answer, 'combined acme/b' => $answer, 'joint update acme/b ^2.0 with acme/h' => $answer];
        $blocking = (new RecordedMoveChecker($checks, ['blocks_advisories' => true], true))->withAdvisories([], ['acme/b', 'acme/h']);
        $open = (new RecordedMoveChecker($checks, ['blocks_advisories' => false], true))->withAdvisories([], ['acme/b', 'acme/h']);
        $query = MoveQuery::update('acme/b', '^2.0', '2.0.0');

        $read = static fn (MoveCheck $check): array => [$check->result(), $check->reason()];

        self::assertSame(
            array_fill(0, 3, [MoveCheck::NOT_CHECKED, MoveCheck::ADVISORY_LOOKUP_INCOMPLETE]),
            [$read($blocking->check($query)->check()), $read($blocking->combined(['acme/b' => '^2.0'])->check()), $read($blocking->joint($query, ['acme/h'])->check())],
        );
        self::assertSame([MoveCheck::RESOLVES, null], $read($open->check($query)->check()), 'a Composer that blocks no advisory leaves the solve resolved');
    }

    public function testARefusedBaselineReturnsTheRecordedProblemText(): void
    {
        $checker = new RecordedMoveChecker(['baseline' => ['result' => 'refused', 'form' => null, 'holds' => [], 'problem' => self::PROBLEM]], [], true);

        self::assertSame(self::PROBLEM, $checker->baseline()->problem());
    }

    public function testARefusedBaselineLeavesEachMoveAndTheCombinedUpdateNotChecked(): void
    {
        $checker = new RecordedMoveChecker(['baseline' => ['result' => 'refused', 'form' => null, 'holds' => [], 'problem' => self::PROBLEM]], [], true);

        $move = $checker->check(MoveQuery::update('acme/b', '^2.0', '2.0.0'))->check();
        $combined = $checker->combined(['acme/b' => '^2.0'])->check();

        self::assertSame([MoveCheck::NOT_CHECKED, MoveCheck::BASELINE_REFUSED], [$move->result(), $move->reason()]);
        self::assertSame([MoveCheck::NOT_CHECKED, MoveCheck::BASELINE_REFUSED], [$combined->result(), $combined->reason()]);
    }

    public function testAStrictCheckerRefusesARequestThatTheCaseDoesNotRecord(): void
    {
        $checker = new RecordedMoveChecker(['baseline' => ['result' => 'resolves', 'form' => MoveCheck::INLINE, 'holds' => [], 'problem' => null]], [], true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('update acme/b ^2.0 ');

        $checker->check(MoveQuery::update('acme/b', '^2.0', '2.0.0'));
    }

    public function testAStrictCheckerRefusesAnUnrecordedCombinedUpdate(): void
    {
        $checker = new RecordedMoveChecker([], [], true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('combined acme/a acme/b');

        $checker->combined(['acme/a' => '^1.1', 'acme/b' => '^2.0']);
    }

    public function testAStrictCheckerRefusesAnUnrecordedBaseline(): void
    {
        $checker = new RecordedMoveChecker([], [], true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('baseline');

        $checker->baseline();
    }

    public function testALenientCheckerReadsAnUnrecordedRequestAsAnUnloadedName(): void
    {
        $checker = new RecordedMoveChecker([], []);

        $check = $checker->check(MoveQuery::update('acme/b', '^2.0', '2.0.0'))->check();

        self::assertSame([MoveCheck::NOT_CHECKED, MoveCheck::UNLOADED_NAME], [$check->result(), $check->reason()]);
    }
}
