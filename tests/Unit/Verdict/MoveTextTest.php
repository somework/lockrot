<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Security\PhpCheck;
use Lockrot\Signal\PhpFloor;
use Lockrot\Solver\MoveCheck;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Move;
use Lockrot\Verdict\MoveSubject;
use Lockrot\Verdict\MoveText;
use PHPUnit\Framework\TestCase;

/** Each move is built here with only the fields that its sentence reads, for acme/lib 1.2.0 at target php 7.4. */
final class MoveTextTest extends TestCase
{
    public function testAMoveThatComposerDidNotCheckSaysWhyAndWhatItAssumes(): void
    {
        $move = self::move(Move::UPDATE, null, [])->withCheck(MoveCheck::notChecked(MoveCheck::INSTALL_TIME_BUDGET));

        self::assertStringEndsWith("; not checked with Composer's solver (the install-time budget ran out), so it holds only if Composer resolves it", MoveText::render($move));
    }

    public function testACheckedMoveToANewMajorDoesNotSayThatLockrotLeavesItsRequirementsUnsolved(): void
    {
        $move = self::move(Move::REQUIRE, null, [Move::CROSSES_MAJOR]);

        self::assertStringContainsString('a new major brings its own requirements;', MoveText::render($move->withCheck(MoveCheck::resolves(MoveCheck::INLINE))));
        self::assertStringContainsString('a new major brings its own requirements, which lockrot does not solve', MoveText::render($move->withCheck(MoveCheck::notChecked(MoveCheck::OFFLINE))));
    }

    public function testARefusedBlockedMoveWithoutAPhpRequirementSaysThatComposerNamesNoHold(): void
    {
        $move = self::move(Move::BLOCKED, null, [])->withCheck(MoveCheck::refused());

        self::assertStringStartsWith('no move installs 2.x: Composer names no hold', MoveText::render($move));
    }

    /** The php of the release, not Composer's refusal, keeps the move off the target. */
    public function testARefusedBlockedMoveWhosePhpTheTargetDoesNotRunNamesThePhp(): void
    {
        $move = self::move(Move::BLOCKED, PhpCheck::of('>=8.2', new PhpFloor('7.4')), [])->withCheck(MoveCheck::refused());

        self::assertStringStartsWith('no move runs on the target: 2.x needs php >=8.2 and the target php 7.4 does not run it', MoveText::render($move));
    }

    public function testABlockedMoveThatComposerDidNotRefuseNamesThePhp(): void
    {
        $move = self::move(Move::BLOCKED, PhpCheck::of('>=8.2', new PhpFloor('7.4')), [])->withCheck(MoveCheck::notChecked(MoveCheck::OFFLINE));

        self::assertStringStartsWith('no move runs on the target: 2.x needs php >=8.2', MoveText::render($move));
    }

    public function testWithCheckLeavesTheOriginalMoveUnchecked(): void
    {
        $move = self::move(Move::UPDATE, null, []);

        $checked = $move->withCheck(MoveCheck::refused());

        self::assertNull($move->check());
        self::assertNotNull($checked->check());
    }

    /** @param list<string> $because */
    private static function move(string $kind, ?PhpCheck $php, array $because): Move
    {
        $subject = new MoveSubject('acme/lib', '1.2.0', true, [], '7.4', null);
        $commands = $kind === Move::BLOCKED ? [] : [['composer', 'update', 'acme/lib']];

        return new Move($kind, FlagSet::VULNERABLE, [], null, null, null, null, '2.x', '2.0.0', '2.0.0', '^2.0', null, $php, true, [], [], [], [], [], null, $because, $commands, $subject);
    }
}
