<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use Lockrot\Signal\PhpFloor;
use Lockrot\Tests\Support\Golden;
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

    public function testTheProjectsFloorIsTheLowerBoundOfItsWholeConstraint(): void
    {
        self::assertSame(PhpFloor::PROJECT, (new PhpFloor('8.4', '^7.2.5 || ^8.0'))->blocking('^8.1'));
        self::assertNull((new PhpFloor('8.4', '^8.2|^8.3|^8.4|^8.5'))->blocking('^8.1'));
        self::assertSame(PhpFloor::PROJECT, (new PhpFloor('8.4', '^8.2|^8.3|^8.4|^8.5'))->blocking('^8.3'));
        self::assertSame('the project\'s php ^7.2.5 || ^8.0', (new PhpFloor('8.4', '^7.2.5 || ^8.0'))->describe(PhpFloor::PROJECT));
    }

    public function testNoRequirementOrAnUnparsableOneIsWithinReach(): void
    {
        $floor = new PhpFloor('7.2', '>=7.2.5');

        self::assertNull($floor->blocking(null));
        self::assertNull($floor->blocking('not a constraint'));
    }

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

    public function testAnUpperBoundBelowTheTargetIsOutOfReach(): void
    {
        self::assertSame(PhpFloor::TARGET, (new PhpFloor('8.4'))->blocking('>=7.1 <8.0'));
        self::assertNull((new PhpFloor('7.4'))->blocking('>=7.1 <8.0'));
    }

    public function testEachFloorAnswersOnItsOwn(): void
    {
        $floor = new PhpFloor('8.4', '>=7.2.5');

        self::assertFalse($floor->admitsProject('>=8.1'));
        self::assertTrue($floor->admitsTarget('>=8.1'));
        self::assertTrue($floor->admitsProject('>=7.2'));
        self::assertTrue($floor->admitsTarget('>=7.2'));
        self::assertFalse((new PhpFloor('8.4', '^8.2|^8.3|^8.4|^8.5'))->admitsProject('^8.3'), 'the lowest of the whole constraint, 8.2.0');
    }

    public function testTheTargetAdmitsABranchWhenAnyVersionOfItsMinorDoes(): void
    {
        self::assertFalse((new PhpFloor('8.3'))->admitsTarget('>=8.4.1'));
        self::assertTrue((new PhpFloor('8.4'))->admitsTarget('>=8.4.1'));
        self::assertTrue((new PhpFloor('8.4.7'))->admitsTarget('~8.4.0'));
        self::assertFalse((new PhpFloor('8.4'))->admitsTarget('>=7.1 <8.0'));
    }

    public function testNoRequirementOrAnUnreadableOneGivesNoAnswer(): void
    {
        $floor = new PhpFloor('7.2', '>=7.2.5');

        self::assertNull($floor->admitsProject(null));
        self::assertNull($floor->admitsTarget(null));
        self::assertNull($floor->admitsProject('not a constraint'));
        self::assertNull($floor->admitsTarget('not a constraint'));
    }

    public function testAFloorThatIsNotThereGivesNoAnswer(): void
    {
        self::assertNull((new PhpFloor('8.4', null))->admitsProject('>=8.1'));
        self::assertNull((new PhpFloor('8.4', '*'))->admitsProject('>=8.1'));
        self::assertNull((new PhpFloor('8.4', 'whatever'))->admitsProject('>=8.1'));
        self::assertNull((new PhpFloor(null, '>=7.2.5'))->admitsTarget('>=8.1'));
        self::assertNull((new PhpFloor('latest'))->admitsTarget('>=8.1'));
        self::assertFalse((new PhpFloor(null, '>=7.2.5'))->admitsProject('>=8.1'), 'the project floor holds without a target');
    }

    /** @dataProvider floors */
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

    /**
     * Which side of a floor a branch's php lies on when it does not admit the floor, one answer per
     * floor: wallabag's `>=8.2` against symfony 8.x's `>=8.4.1` (a newer PHP is needed), `^7.0`
     * against 8.4 (support stops before it), `^7.4 || ~8.2.0` against 8.1 (it skips the floor).
     *
     * @dataProvider sides
     */
    #[DataProvider('sides')]
    public function testEachFloorSaysWhichSideOfItTheBranchIsOn(?string $target, ?string $project, ?string $constraint, ?string $missesTarget, ?string $missesProject): void
    {
        $floor = new PhpFloor($target, $project);

        self::assertSame($missesTarget, $floor->missesTarget($constraint), 'target');
        self::assertSame($missesProject, $floor->missesProject($constraint), 'project');
    }

    /** @return iterable<string, array{?string, ?string, ?string, ?string, ?string}> */
    public static function sides(): iterable
    {
        yield 'needs a newer PHP than the project promises' => ['8.4', '>=8.2', '>=8.4.1', null, PhpFloor::NEEDS_NEWER];
        yield 'needs a newer PHP than the target' => ['8.3', null, '>=8.4.1', PhpFloor::NEEDS_NEWER, null];
        yield 'stops before both' => ['8.4', '>=8.3', '^7.0', PhpFloor::STOPS_BEFORE, PhpFloor::STOPS_BEFORE];
        yield 'stops before the target, admits the project' => ['8.4', '>=8.2', '>=7.2 <8.4', PhpFloor::STOPS_BEFORE, null];
        yield 'misses both floors, each its own way' => ['8.4', '>=8.2', '~8.3.0', PhpFloor::STOPS_BEFORE, PhpFloor::NEEDS_NEWER];
        yield 'skips the target' => ['8.1', null, '^7.4 || ~8.2.0', PhpFloor::SKIPS, null];
        yield 'skips the project' => ['8.4', '>=8.2', '<=8.1.0 || >=8.4', null, PhpFloor::SKIPS];
        yield 'skips both' => ['8.4', '>=8.2', '^7.4 || ^8.5', PhpFloor::SKIPS, PhpFloor::SKIPS];
        yield 'the lowest of the project\'s alternatives is the floor' => ['8.4', '^7.2.5 || ^8.0', '^8.1', null, PhpFloor::NEEDS_NEWER];
        yield 'the first build past the target minor, where the minor ends' => ['8.4', null, '8.5.0-dev', PhpFloor::NEEDS_NEWER, null];
        yield 'a patch-level target is its minor' => ['8.4.7', null, '~8.3.0', PhpFloor::STOPS_BEFORE, null];
        yield 'admits no PHP at all' => ['8.4', '>=8.2', '>=9 <8', PhpFloor::UNSATISFIABLE, PhpFloor::UNSATISFIABLE];
        yield 'only a branch name, which no PHP is' => ['8.4', '>=8.2', 'dev-master', PhpFloor::UNSATISFIABLE, PhpFloor::UNSATISFIABLE];
        yield 'admits both' => ['8.4', '>=8.2', '^8.2 || ^8.4', null, null];
        yield 'no requirement' => ['8.4', '>=8.2', null, null, null];
        yield 'an unreadable requirement' => ['8.4', '>=8.2', 'not a constraint', null, null];
        yield 'no project floor' => ['8.4', null, '>=8.5', PhpFloor::NEEDS_NEWER, null];
        yield 'an unbounded project requirement is no floor' => ['8.4', '*', '>=8.5', PhpFloor::NEEDS_NEWER, null];
        yield 'no target' => [null, '>=8.2', '^7.0', null, PhpFloor::STOPS_BEFORE];
    }

    public function testASideIsGivenExactlyWhereTheFloorIsNotAdmitted(): void
    {
        $sides = [PhpFloor::NEEDS_NEWER, PhpFloor::STOPS_BEFORE, PhpFloor::SKIPS, PhpFloor::UNSATISFIABLE];
        $seen = [];
        foreach (['8.4', '8.4.7', '8.1', '7.4', null, 'latest'] as $target) {
            foreach (['>=8.2', '^7.2.5 || ^8.0', '~8.3.0', '^8.1', '*', 'whatever', null] as $project) {
                $floor = new PhpFloor($target, $project);
                foreach (['>=8.4.1', '^7.0', '>=7.2 <8.4', '^7.4 || ~8.2.0', '~8.3.0', '>=9 <8', 'dev-master', '^8.4@dev', '8.4.*', '>=8.4.0-dev', '^8.2 || ^8.4', '^7.4 || ^8.5', '<=8.1.0 || >=8.4', '^7.4 || dev-master', '*', 'not a constraint', null] as $php) {
                    $what = var_export([$target, $project, $php], true);
                    foreach ([[$floor->admitsTarget($php), $floor->missesTarget($php)], [$floor->admitsProject($php), $floor->missesProject($php)]] as [$admits, $misses]) {
                        if ($admits === false) {
                            self::assertContains($misses, $sides, $what);
                            self::assertIsString($misses);
                            $seen[$misses] = true;
                        } else {
                            self::assertNull($misses, $what);
                        }
                    }
                }
            }
        }
        ksort($seen);
        self::assertSame([PhpFloor::NEEDS_NEWER, PhpFloor::SKIPS, PhpFloor::STOPS_BEFORE, PhpFloor::UNSATISFIABLE], array_keys($seen), 'every side, or the matrix proves little');
    }

    /**
     * The stable point that every project comparison reads: an
     * inclusive bound gives its `X.Y.Z`, an exclusive bound or a non-zero fourth segment the next
     * patch, an exclusive pre-release bound its `X.Y.Z`. No lower bound, or no readable constraint,
     * gives no point.
     *
     * @dataProvider floorPoints
     */
    #[DataProvider('floorPoints')]
    public function testTheProjectsLowestPhpIsAStablePoint(?string $requirePhp, ?string $point): void
    {
        $floor = new PhpFloor('8.4', $requirePhp);

        self::assertSame($point, $floor->lowestAsString());
        if ($point !== null) {
            self::assertTrue($floor->admitsProject($point), 'the published point is the point admitsProject() checks');
            self::assertNull($floor->missesProject($point));
        }
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function floorPoints(): iterable
    {
        yield 'a caret' => ['^7.2', '7.2.0'];
        yield 'an inclusive bound with a patch' => ['>=7.2.5', '7.2.5'];
        yield 'an exclusive bound' => ['>7.1', '7.1.1'];
        yield 'a tilde on a patch' => ['~8.0.0', '8.0.0'];
        yield 'a pre-release bound' => ['>=7.4.0-RC1', '7.4.0'];
        yield 'an exclusive pre-release bound' => ['>7.4.0-RC1', '7.4.0'];
        yield 'a wildcard' => ['7.*', '7.0.0'];
        yield 'a caret on a patch' => ['^7.2.5', '7.2.5'];
        yield 'an inclusive bound' => ['>=8.2', '8.2.0'];
        yield 'alternatives, the lowest' => ['^7.4 || ^8.0', '7.4.0'];
        yield 'a non-zero fourth segment' => ['>=7.2.5.1', '7.2.6'];
        yield 'an exclusive bound on a patch' => ['>7.1.3', '7.1.4'];
        yield 'an exact version' => ['8.1.2', '8.1.2'];
        yield 'a hyphen range' => ['7.3 - 8.1', '7.3.0'];
        yield 'any version' => ['*', null];
        yield 'an upper bound only' => ['<8', null];
        yield 'an unreadable constraint' => ['whatever', null];
        yield 'a date version' => ['>=201001', null];
        yield 'no require.php' => [null, null];
    }

    /**
     * The point moves what a branch row admits only at the edge. The last column is the answer of
     * the lower bound as Composer writes it, suffix kept: it shows which rows the point changes.
     *
     * @dataProvider floorPointEdges
     */
    #[DataProvider('floorPointEdges')]
    public function testTheFloorPointDecidesTheEdgeOfABranchsPhp(string $requirePhp, string $branchPhp, bool $admits, bool $before): void
    {
        $floor = new PhpFloor('8.4', $requirePhp);

        self::assertSame($admits, $floor->admitsProject($branchPhp));
        self::assertSame($admits, $floor->missesProject($branchPhp) === null, 'missesProject() reads the same point');
        $parser = new VersionParser();
        $bound = $parser->parseConstraints($requirePhp)->getLowerBound()->getVersion();
        self::assertSame($before, $parser->parseConstraints($branchPhp)->matches(new Constraint('==', $bound)), 'the lower bound as written, suffix kept');
    }

    /** @return iterable<string, array{string, string, bool, bool}> */
    public static function floorPointEdges(): iterable
    {
        yield '>=8.2 against 8.2.0' => ['>=8.2', '8.2.0', true, false];
        yield '>=8.2 against >=8.2.0-alpha1' => ['>=8.2', '>=8.2.0-alpha1', true, false];
        yield '>=8.2 against >8.2.0' => ['>=8.2', '>8.2.0', false, false];
        yield '>=8.2 against >=8.2.1' => ['>=8.2', '>=8.2.1', false, false];
        yield '^7.2.5 against 7.2.5' => ['^7.2.5', '7.2.5', true, false];
        yield '>=7.4.0-RC1 against 7.4.0' => ['>=7.4.0-RC1', '7.4.0', true, false];
        yield '>7.1 against >7.1' => ['>7.1', '>7.1', true, false];
        yield '>7.1 against >=7.1.1' => ['>7.1', '>=7.1.1', true, false];
        yield '>7.1 against 7.1.0' => ['>7.1', '7.1.0', false, true];
        yield '>=8.2 against ^8.2' => ['>=8.2', '^8.2', true, true];
        yield '>=8.2 against 8.2.*' => ['>=8.2', '8.2.*', true, true];
        yield '>=8.2 against <8.2' => ['>=8.2', '<8.2', false, false];
    }

    /**
     * The display rows of the floor point, which lockrot-report's display rule reads: it drops a
     * trailing `.0` patch when it prints the point and keeps any other patch.
     */
    public function testTheDisplayRowsOfTheFloorPointAreRecorded(): void
    {
        $rows = [];
        foreach (['^7.2', '>=7.2.5', '>7.1', '~8.0.0', '>=7.4.0-RC1', '7.*', '*', '<8', 'whatever', null] as $requirePhp) {
            $point = (new PhpFloor(null, $requirePhp))->lowestAsString();
            $rows[] = [
                'require_php' => $requirePhp,
                'project_php_lowest' => $point,
                'printed' => $point === null ? null : preg_replace('/\\.0$/', '', $point),
            ];
        }

        Golden::assertMatches('php-versions.json', ['rows' => $rows], 'testTheDisplayRowsOfTheFloorPointAreRecorded', \dirname(__DIR__, 2).'/fixtures/contract/en/');
    }
}
