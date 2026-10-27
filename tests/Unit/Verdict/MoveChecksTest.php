<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveQuery;
use Lockrot\Tests\Support\RecordedMoveChecker;
use Lockrot\Verdict\MoveChecks;
use PHPUnit\Framework\TestCase;

final class MoveChecksTest extends TestCase
{
    public function testACopyThatAnswersOnlyWhatWasAskedKeepsTheAnswersAndAsksNothingNew(): void
    {
        $checks = new MoveChecks(new RecordedMoveChecker(['*' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]], []), true);
        $asked = MoveQuery::update('a/b', '^2.0', '2.0.0');
        $checks->check($asked);

        $answeredOnly = $checks->answeredOnly();

        self::assertSame(MoveCheck::RESOLVES, $answeredOnly->check($asked)->check()->result());
        self::assertSame(['result' => MoveCheck::NOT_CHECKED, 'form' => null, 'reason' => MoveCheck::PASS_CAP, 'installs' => null], $answeredOnly->check(MoveQuery::update('c/d', '^3.0', '3.0.0'))->check()->jsonSerialize());
        self::assertSame(MoveCheck::RESOLVES, $checks->check(MoveQuery::update('c/d', '^3.0', '3.0.0'))->check()->result(), 'the original still asks');
    }

    /** A package hold whose own link admits the move's release is no hold (ruling R2-2), so its package does not join the joint request. */
    public function testTheJointRequestLeavesOutAPackageWhoseLinkAdmitsTheRelease(): void
    {
        $query = MoveQuery::update('a/b', '^2.0', '2.0.0');
        $checks = new MoveChecks(new RecordedMoveChecker([
            $query->key() => ['result' => 'refused', 'holds' => [
                ['source' => 'package', 'package' => 'h/h', 'version' => '1.0.0', 'link' => 'require', 'constraint' => '^1.0'],
                ['source' => 'package', 'package' => 'q/q', 'version' => '1.0.0', 'link' => 'require', 'constraint' => '>=1.0'],
            ]],
            'joint '.rtrim($query->key()).' with h/h' => ['result' => 'resolves', 'form' => 'inline', 'installs' => ['a/b' => '2.0.0', 'h/h' => '2.0.0']],
            'joint '.rtrim($query->key()).' with h/h q/q' => ['result' => 'resolves', 'form' => 'inline', 'installs' => ['a/b' => '2.0.0', 'h/h' => '2.0.0', 'q/q' => '1.5.0']],
        ], [], true), true);

        $outcome = $checks->check($query);

        self::assertSame([MoveCheck::RESOLVES, [['package' => 'h/h', 'installed_version' => '1.0.0', 'version' => '2.0.0']]], [$outcome->check()->result(), $outcome->movesWith()]);
    }
}
