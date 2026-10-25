<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Security\Holder;
use Lockrot\Security\PhpCheck;
use Lockrot\Signal\PhpFloor;
use Lockrot\Solver\MoveCheck;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\GradedPackage;
use Lockrot\Verdict\Move;
use Lockrot\Verdict\MoveSubject;
use Lockrot\Verdict\MoveText;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return iterable<string, array{?string, string, bool}> */
    public static function platforms(): iterable
    {
        yield 'outside the target minor' => ['7.4.29', '8.4', true];
        yield 'a patch of the target minor' => ['8.4.1', '8.4', false];
        yield 'the target minor with no patch' => ['8.4', '8.4', false];
        yield 'a minor that starts like the target' => ['8.40.1', '8.4', true];
        yield 'no override' => [null, '8.4', false];
    }

    /**
     * @dataProvider platforms
     */
    #[DataProvider('platforms')]
    public function testAMoveWithCommandsFirstSetsAPlatformPhpOutsideTheTargetMinor(?string $override, string $target, bool $step): void
    {
        $subject = new MoveSubject('acme/lib', '1.2.0', true, [], $target, null, null, $override);
        $move = new Move(Move::UPDATE, FlagSet::VULNERABLE, [], null, null, null, null, '2.x', '2.0.0', '2.0.0', null, null, null, false, [], [], [], [], [], null, [], [['composer', 'update', 'acme/lib']], $subject);

        $text = MoveText::render($move);

        self::assertSame($step ? 'First set config.platform.php to your PHP '.$target.' release: the lock records '.$override.'. composer update acme/lib (gets 2.0.0)' : 'composer update acme/lib (gets 2.0.0)', $text);
    }

    /** @return iterable<string, array{bool, string}> */
    public static function refusedBaselines(): iterable
    {
        yield 'the target outside the window' => [false, 'the PHP line names the packages.'];
        yield 'the target inside the window' => [true, 'the Run tab names the cause.'];
    }

    /**
     * wallabag at 8.4: the platform step alone does not make the lock install while Composer refuses the lock itself.
     *
     * @dataProvider refusedBaselines
     */
    #[DataProvider('refusedBaselines')]
    public function testThePlatformStepOfARefusedBaselineSaysWhereTheCauseIsNamed(bool $inside, string $pointer): void
    {
        $subject = new MoveSubject('acme/lib', '1.2.0', true, [], '8.4', null, null, '7.4.29', $inside);
        $move = new Move(Move::UPDATE, FlagSet::VULNERABLE, [], null, null, null, null, '2.x', '2.0.0', '2.0.0', null, null, null, false, [], [], [], [], [], null, [], [['composer', 'update', 'acme/lib']], $subject);

        self::assertSame('First set config.platform.php to your PHP 8.4 release: the lock records 7.4.29. Composer still refuses the lock itself: '.$pointer.' composer update acme/lib (gets 2.0.0)', MoveText::render($move));
    }

    public function testAMoveWithoutCommandsTakesNoPlatformStep(): void
    {
        $subject = new MoveSubject('acme/lib', '1.2.0', true, [], '8.4', null, null, '7.4.29');
        $move = new Move(Move::FIND_ALTERNATIVE, FlagSet::ABANDONED, [], null, null, null, null, null, null, null, null, null, null, null, [], [], [], [], [], null, [], [], $subject);

        self::assertSame('find a replacement (none named)', MoveText::render($move));
    }

    /** The finding's own release holds the move: the hold takes no grade and no flags of the installed version, and follows the root holds. */
    public function testAHoldOfTheFindingsOwnPackageNamesItsOwnRelease(): void
    {
        $graded = new GradedPackage('high', 'left-behind', ['left-behind'], null, null, '1.0.0');
        $holds = Holder::filled([
            new Holder(Holder::ROOT, null, null, Holder::REQUIRE, '^1.2'),
            new Holder(Holder::PACKAGE, 'a/early', '1.0.0', Holder::REQUIRE, '^1.2', 'acme/lib'),
            new Holder(Holder::PACKAGE, 'acme/lib', '2.0.0', Holder::REQUIRE, '^1', 'psr/log'),
            new Holder(Holder::PACKAGE, 'acme/lib', '2.0.0', Holder::CONFLICT, '<2', 'psr/clock'),
        ], ['acme/lib' => $graded, 'a/early' => $graded]);
        $subject = new MoveSubject('acme/lib', '1.2.0', true, [], '7.4', null);
        $move = new Move(Move::REQUIRE, FlagSet::LEFT_BEHIND, [], null, null, null, null, '2.x', '2.0.0', '2.0.0', '^2.0', null, null, true, $holds, [], [], [], [], null, [], [], $subject);

        self::assertStringEndsWith('; held by composer.json (requires ^1.2) and acme/lib 2.0.0, its own release (requires psr/log ^1) and acme/lib 2.0.0, its own release (conflicts with psr/clock <2) and a/early 1.0.0 (requires acme/lib ^1.2; itself left-behind)', MoveText::render($move));
    }

    public function testARootHoldFromRequireDevSaysRequireDev(): void
    {
        $holds = [new Holder(Holder::ROOT, null, null, Holder::REQUIRE_DEV, '^1.2')];
        $subject = new MoveSubject('acme/lib', '1.2.0', true, [], '7.4', null);
        $move = new Move(Move::REQUIRE, FlagSet::LEFT_BEHIND, [], null, null, null, null, '2.x', '2.0.0', '2.0.0', '^2.0', null, null, true, $holds, [], [], [], [], null, [], [], $subject);

        self::assertStringEndsWith('; held by composer.json (requires ^1.2 (require-dev))', MoveText::render($move));
    }

    /**
     * The root holds, then the own releases, then the package holds on the finding's package, then
     * the other package holds. Within a group: by grade, then by name. The order does not rest on
     * the order of `held_by[]`.
     */
    public function testTheTextRanksTheHoldsByGroupThenByGradeThenByName(): void
    {
        $high = new GradedPackage('high', 'left-behind', ['left-behind'], null, null, '1.0.0');
        $critical = new GradedPackage('critical', null, ['vulnerable'], null, null, '1.0.0');
        $holds = Holder::filled([
            new Holder(Holder::PACKAGE, 'a/other', '1.0.0', Holder::REQUIRE, '^1', 'psr/log'),
            new Holder(Holder::PACKAGE, 'z/critical', '1.0.0', Holder::REQUIRE, '^1', 'psr/log'),
            new Holder(Holder::PACKAGE, 'a/plain', '1.0.0', Holder::REQUIRE, '^1.2'),
            new Holder(Holder::PACKAGE, 'c/high', '1.0.0', Holder::REQUIRE, '^1.2', 'acme/lib'),
            new Holder(Holder::PACKAGE, 'b/high', '1.0.0', Holder::REQUIRE, '^1.2'),
            new Holder(Holder::PACKAGE, 'acme/lib', '2.0.0', Holder::REQUIRE, '^1', 'psr/log'),
            new Holder(Holder::ROOT, null, null, Holder::REQUIRE_DEV, '^1.2'),
            new Holder(Holder::ROOT, null, null, Holder::REQUIRE, '^1.2'),
        ], ['z/critical' => $critical, 'c/high' => $high, 'b/high' => $high, 'a/plain' => new GradedPackage('finished', null, [], null, null, '1.0.0')]);

        $ordered = MoveText::inTextOrder($holds, 'acme/lib');

        self::assertSame([$holds[6], $holds[7], $holds[5], $holds[4], $holds[3], $holds[2], $holds[1], $holds[0]], $ordered);
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
