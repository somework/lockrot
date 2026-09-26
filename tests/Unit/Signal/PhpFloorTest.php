<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal;

use Lockrot\Signal\PhpFloor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhpFloorTest extends TestCase
{
    /** Matomo: `require.php >=7.2.5`, run against PHP 8.4. monolog 3.x needs php >=8.1. */
    public function testTheProjectsOwnRequirementIsTheFirstFloorABranchIsHeldAgainst(): void
    {
        $floor = new PhpFloor('8.4', '>=7.2.5');

        self::assertSame(PhpFloor::PROJECT, $floor->blocking('>=8.1'));
        self::assertNull($floor->blocking('>=7.2'), 'monolog 2.x admits 7.2.5 and 8.4 both');
        self::assertSame('the project\'s php >=7.2.5', $floor->describe(PhpFloor::PROJECT));
    }

    /** No composer.json, or one without `require.php`: the target PHP is the only floor. */
    public function testTheTargetPhpIsTheFloorWhenTheProjectDeclaresNone(): void
    {
        $floor = new PhpFloor('7.2', null);

        self::assertSame(PhpFloor::TARGET, $floor->blocking('>=8.1'));
        self::assertNull($floor->blocking('>=7.2'));
        self::assertSame('the target PHP 7.2', $floor->describe(PhpFloor::TARGET));
    }

    /** symfony 8.x requires `>=8.4.1`: a target of 8.4 is a minor, and 8.4.1 is inside it. */
    public function testTheTargetIsAWholeMinorNotItsFirstPatch(): void
    {
        self::assertNull((new PhpFloor('8.4'))->blocking('>=8.4.1'));
        self::assertSame(PhpFloor::TARGET, (new PhpFloor('8.3'))->blocking('>=8.4.1'));
        self::assertNull((new PhpFloor('8.4.7'))->blocking('~8.4.0'), 'a patch-level target is read as its minor');
    }

    /** Both hold the branch back: the project's requirement is the reason named, being the one the maintainer wrote. */
    public function testTheProjectIsNamedBeforeTheTargetWhenBothBlock(): void
    {
        self::assertSame(PhpFloor::PROJECT, (new PhpFloor('7.4', '>=7.2.5'))->blocking('>=8.1'));
    }

    /** `^7.2.5 || ^8.0` (phpmyadmin) has a lower bound of 7.2.5; `^8.2|^8.3|^8.4|^8.5` (invoiceninja) of 8.2.0. */
    public function testTheProjectsFloorIsTheLowerBoundOfItsWholeConstraint(): void
    {
        self::assertSame(PhpFloor::PROJECT, (new PhpFloor('8.4', '^7.2.5 || ^8.0'))->blocking('^8.1'));
        self::assertNull((new PhpFloor('8.4', '^8.2|^8.3|^8.4|^8.5'))->blocking('^8.1'));
        self::assertSame(PhpFloor::PROJECT, (new PhpFloor('8.4', '^8.2|^8.3|^8.4|^8.5'))->blocking('^8.3'));
        self::assertSame('the project\'s php ^7.2.5 || ^8.0', (new PhpFloor('8.4', '^7.2.5 || ^8.0'))->describe(PhpFloor::PROJECT));
    }

    /** A branch that requires no PHP at all, or one whose requirement cannot be parsed, is not held back by anything. */
    public function testNoRequirementOrAnUnparsableOneIsWithinReach(): void
    {
        $floor = new PhpFloor('7.2', '>=7.2.5');

        self::assertNull($floor->blocking(null));
        self::assertNull($floor->blocking('not a constraint'));
    }

    /** `*` and a project requirement that cannot be parsed give no floor: nothing is held against nothing. */
    public function testAnUnboundedOrUnparsableProjectRequirementIsNoFloor(): void
    {
        self::assertNull((new PhpFloor('8.4', '*'))->blocking('>=8.1'));
        self::assertNull((new PhpFloor('8.4', 'whatever'))->blocking('>=8.1'));
        self::assertSame(PhpFloor::TARGET, (new PhpFloor('7.4', '*'))->blocking('>=8.1'), 'the target still holds');
    }

    /** A target that is not a version — a misconfiguration the config layer rejects first — is no floor either. */
    public function testATargetThatIsNotAVersionIsNoFloor(): void
    {
        self::assertNull((new PhpFloor('latest'))->blocking('>=8.1'));
        self::assertNull((new PhpFloor(null))->blocking('>=8.1'));
    }

    /** A branch with an upper bound below the target is out of reach too: `<8.0` on a PHP 8.4 project. */
    public function testAnUpperBoundBelowTheTargetIsOutOfReach(): void
    {
        self::assertSame(PhpFloor::TARGET, (new PhpFloor('8.4'))->blocking('>=7.1 <8.0'));
        self::assertNull((new PhpFloor('7.4'))->blocking('>=7.1 <8.0'));
    }

    /** Matomo again, one floor at a time: monolog 3.x admits PHP 8.4 but not the 7.2.5 Matomo promises. */
    public function testEachFloorAnswersOnItsOwn(): void
    {
        $floor = new PhpFloor('8.4', '>=7.2.5');

        self::assertFalse($floor->admitsProject('>=8.1'));
        self::assertTrue($floor->admitsTarget('>=8.1'));
        self::assertTrue($floor->admitsProject('>=7.2'));
        self::assertTrue($floor->admitsTarget('>=7.2'));
        self::assertFalse((new PhpFloor('8.4', '^8.2|^8.3|^8.4|^8.5'))->admitsProject('^8.3'), 'the lowest of the whole constraint, 8.2.0');
    }

    /** The target is read as its whole minor here too, and a branch capped below it does not admit it. */
    public function testTheTargetAdmitsABranchWhenAnyVersionOfItsMinorDoes(): void
    {
        self::assertFalse((new PhpFloor('8.3'))->admitsTarget('>=8.4.1'));
        self::assertTrue((new PhpFloor('8.4'))->admitsTarget('>=8.4.1'));
        self::assertTrue((new PhpFloor('8.4.7'))->admitsTarget('~8.4.0'));
        self::assertFalse((new PhpFloor('8.4'))->admitsTarget('>=7.1 <8.0'));
    }

    /** A branch with no requirement, or one that cannot be read, gets no answer from either floor: null, never true. */
    public function testNoRequirementOrAnUnreadableOneGivesNoAnswer(): void
    {
        $floor = new PhpFloor('7.2', '>=7.2.5');

        self::assertNull($floor->admitsProject(null));
        self::assertNull($floor->admitsTarget(null));
        self::assertNull($floor->admitsProject('not a constraint'));
        self::assertNull($floor->admitsTarget('not a constraint'));
    }

    /** A floor that is not there (no require.php, `*`, one that cannot be read, no target) answers nothing either. */
    public function testAFloorThatIsNotThereGivesNoAnswer(): void
    {
        self::assertNull((new PhpFloor('8.4', null))->admitsProject('>=8.1'));
        self::assertNull((new PhpFloor('8.4', '*'))->admitsProject('>=8.1'));
        self::assertNull((new PhpFloor('8.4', 'whatever'))->admitsProject('>=8.1'));
        self::assertNull((new PhpFloor(null, '>=7.2.5'))->admitsTarget('>=8.1'));
        self::assertNull((new PhpFloor('latest'))->admitsTarget('>=8.1'));
        self::assertFalse((new PhpFloor(null, '>=7.2.5'))->admitsProject('>=8.1'), 'the project floor holds without a target');
    }

    /**
     * blocking() is the two answers read in S8's order: the project first, then the target, and
     * nothing when neither says no.
     *
     * @dataProvider floors
     */
    #[DataProvider('floors')]
    public function testBlockingIsTheTwoAnswersInOrder(?string $target, ?string $project, ?string $constraint, ?string $expected): void
    {
        $floor = new PhpFloor($target, $project);
        $composed = $floor->admitsProject($constraint) === false ? PhpFloor::PROJECT : ($floor->admitsTarget($constraint) === false ? PhpFloor::TARGET : null);

        self::assertSame($expected, $floor->blocking($constraint));
        self::assertSame($expected, $composed);
    }

    /** @return iterable<string, array{?string, ?string, ?string, ?string}> */
    public static function floors(): iterable
    {
        yield 'both block, the project is named' => ['7.4', '>=7.2.5', '>=8.1', PhpFloor::PROJECT];
        yield 'only the project blocks' => ['8.4', '>=7.2.5', '>=8.1', PhpFloor::PROJECT];
        yield 'only the target blocks' => ['7.4', '>=7.2.5', '>=7.2 <7.4', PhpFloor::TARGET];
        yield 'the target blocks with no project floor' => ['7.4', null, '>=8.1', PhpFloor::TARGET];
        yield 'within both' => ['8.4', '>=7.2.5', '>=7.2', null];
        yield 'no requirement' => ['7.4', '>=7.2.5', null, null];
        yield 'an unreadable requirement' => ['7.4', '>=7.2.5', 'not a constraint', null];
        yield 'no floor at all' => [null, null, '>=8.1', null];
    }
}
