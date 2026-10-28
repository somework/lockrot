<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Config;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackage;
use Composer\Package\RootPackageInterface;
use Composer\Repository\PlatformRepository;
use Composer\Semver\VersionParser;
use Lockrot\Lock\LockFile;
use Lockrot\Security\Holder;
use Lockrot\Solver\AdvisoryBlocking;
use Lockrot\Solver\ComposerSolver;
use Lockrot\Solver\PoolAdvisories;
use Lockrot\Solver\SolveRequest;
use Lockrot\Tests\Support\ArrayVersionSource;
use PHPUnit\Framework\TestCase;

/**
 * Each solve runs on every Composer of the CI matrix. A case that differs between the inline
 * constraint of Composer 2.4 and later and the joined root requirement of 2.2 says so.
 */
final class ComposerSolverTest extends TestCase
{
    public function testAnUpdateInstallsTheNewestReleaseThatTheConstraintAdmitsAndItsNewDependencyByName(): void
    {
        $solver = self::solver(
            ['require' => ['acme/lib' => '^1.0']],
            [['name' => 'acme/lib', 'version' => '1.0.0']],
            ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0'], ['version' => '1.2.0', 'require' => ['acme/zeta' => '^1.0']]], 'acme/zeta' => [['version' => '1.0.0']]]
        );

        self::assertSame(['acme/lib' => '1.2.0', 'acme/zeta' => '1.0.0'], $solver->solve(SolveRequest::update(['acme/lib' => '^1.1']))->installs());
    }

    public function testAnUpdateNamesTheLockedReleaseOfEachNameThatItMoves(): void
    {
        $solver = self::solver(
            ['require' => ['acme/lib' => '^1.0']],
            [['name' => 'acme/lib', 'version' => 'v1.0.0']],
            ['acme/lib' => [['version' => 'v1.0.0'], ['version' => '1.2.0', 'require' => ['acme/zeta' => '^1.0']]], 'acme/zeta' => [['version' => '1.0.0']]]
        );

        self::assertSame(['acme/lib' => 'v1.0.0'], $solver->solve(SolveRequest::update(['acme/lib' => '^1.1']))->updatedFrom());
    }

    /** Composer 2.7 and later keep every name off the allow list at its locked release where they can (`update --minimal-changes`). */
    public function testAMinimalChangesSolveKeepsADependencyThatTheMoveDoesNotNeedToChange(): void
    {
        $solver = self::solver(
            ['require' => ['acme/lib' => '^1.0']],
            [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/zeta' => '^1.0']], ['name' => 'acme/zeta', 'version' => '1.0.0']],
            ['acme/lib' => [['version' => '1.0.0', 'require' => ['acme/zeta' => '^1.0']], ['version' => '1.1.0', 'require' => ['acme/zeta' => '^1.0']]], 'acme/zeta' => [['version' => '1.0.0'], ['version' => '1.1.0']]]
        );
        $request = SolveRequest::update(['acme/lib' => '^1.1'])->withTransitive(SolveRequest::WITH_DEPENDENCIES);

        self::assertSame(['acme/lib' => '1.1.0', 'acme/zeta' => '1.1.0'], $solver->solve($request)->installs());
        self::assertSame(['acme/lib' => '1.1.0'], $solver->withMinimalChanges()->solve($request)->installs());
        self::assertSame(['acme/lib' => '1.1.0', 'acme/zeta' => '1.1.0'], $solver->solve($request)->installs(), 'the solver itself keeps its policy');
        self::assertSame(['acme/lib' => '1.1.0'], $solver->withMinimalChanges()->solve(SolveRequest::update(['acme/lib' => '']))->installs(), 'the name that the update allows is no preferred one');
    }

    /** monica's socialiteproviders/facebook: the solve takes another reference of the locked tag, which moves no version. */
    public function testAnUpdateOfATagToAnotherReferenceIsNoChange(): void
    {
        $solver = self::solver(
            ['require' => ['acme/lib' => '^1.0', 'acme/other' => '^1.0']],
            [['name' => 'acme/lib', 'version' => '1.0.0'], ['name' => 'acme/other', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://example.org/a.zip', 'reference' => 'aaaa']]],
            ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0']], 'acme/other' => [['version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://example.org/b.zip', 'reference' => 'bbbb']]]]
        );

        $solution = $solver->solve(SolveRequest::update(['acme/lib' => '^1.1'])->withJoined(['acme/other']));

        self::assertSame([['acme/lib' => '1.1.0'], ['acme/lib' => '1.0.0']], [$solution->installs(), $solution->updatedFrom()]);
    }

    public function testAnUpdateOfABranchToAnotherCommitIsAChange(): void
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => ['acme/lib' => '^1.0', 'acme/other' => 'dev-main']], RootPackage::class);
        self::assertInstanceOf(RootPackage::class, $root);
        $root->setMinimumStability('dev');
        $locked = [['name' => 'acme/lib', 'version' => '1.0.0'], ['name' => 'acme/other', 'version' => 'dev-main', 'source' => ['type' => 'git', 'url' => 'https://example.org/other.git', 'reference' => 'aaaa']]];
        $remote = ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0']], 'acme/other' => [['version' => 'dev-main', 'source' => ['type' => 'git', 'url' => 'https://example.org/other.git', 'reference' => 'bbbb']]]];
        $solver = new ComposerSolver($root, LockFile::fromArray(['packages' => $locked])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), new ArrayVersionSource($remote));

        $solution = $solver->solve(SolveRequest::update(['acme/lib' => '^1.1'])->withJoined(['acme/other']));

        self::assertSame([['acme/lib' => '1.1.0', 'acme/other' => 'dev-main'], ['acme/lib' => '1.0.0', 'acme/other' => 'dev-main']], [$solution->installs(), $solution->updatedFrom()]);
    }

    /** `composer require` reloads composer.json, so a `#commit` pin of the old requirement no longer holds the branch. */
    public function testARequireThatRewritesAPinnedBranchRequirementDropsItsCommitPin(): void
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => ['acme/other' => 'dev-main#aaaa']], RootPackage::class);
        self::assertInstanceOf(RootPackage::class, $root);
        $root->setMinimumStability('dev');
        $root->setReferences(['acme/other' => 'aaaa']);
        $locked = [['name' => 'acme/other', 'version' => 'dev-main', 'source' => ['type' => 'git', 'url' => 'https://example.org/other.git', 'reference' => 'aaaa']]];
        $remote = ['acme/other' => [['version' => 'dev-main', 'source' => ['type' => 'git', 'url' => 'https://example.org/other.git', 'reference' => 'bbbb']]]];
        $solver = new ComposerSolver($root, LockFile::fromArray(['packages' => $locked])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), new ArrayVersionSource($remote));

        $solution = $solver->solve(SolveRequest::require('acme/other', ['acme/other' => 'dev-main'], false));

        self::assertSame(['acme/other' => 'dev-main'], $solution->installs(), 'the branch moves from aaaa to bbbb');
    }

    /** Composer updates a branch at the same commit when only its metadata differs, here `abandoned`. */
    public function testAnUpdateOfABranchAtTheSameCommitIsNoChange(): void
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => ['acme/lib' => '^1.0', 'acme/other' => 'dev-main']], RootPackage::class);
        self::assertInstanceOf(RootPackage::class, $root);
        $root->setMinimumStability('dev');
        $locked = [['name' => 'acme/lib', 'version' => '1.0.0'], ['name' => 'acme/other', 'version' => 'dev-main', 'source' => ['type' => 'git', 'url' => 'https://example.org/other.git', 'reference' => 'aaaa']]];
        $remote = ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0']], 'acme/other' => [['version' => 'dev-main', 'abandoned' => true, 'source' => ['type' => 'git', 'url' => 'https://example.org/other.git', 'reference' => 'aaaa']]]];
        $solver = new ComposerSolver($root, LockFile::fromArray(['packages' => $locked])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), new ArrayVersionSource($remote));

        $solution = $solver->solve(SolveRequest::update(['acme/lib' => '^1.1'])->withJoined(['acme/other']));

        self::assertSame([['acme/lib' => '1.1.0'], ['acme/lib' => '1.0.0']], [$solution->installs(), $solution->updatedFrom()]);
    }

    /** The commit is the source reference: a dist reference that names an archive differs from it. */
    public function testABranchAtTheSameCommitWithAnotherDistReferenceIsNoChange(): void
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => ['acme/lib' => '^1.0', 'acme/other' => 'dev-main']], RootPackage::class);
        self::assertInstanceOf(RootPackage::class, $root);
        $root->setMinimumStability('dev');
        $other = ['source' => ['type' => 'git', 'url' => 'https://example.org/other.git', 'reference' => 'aaaa'], 'dist' => ['type' => 'zip', 'url' => 'https://example.org/other.zip', 'reference' => 'dddd']];
        $locked = [['name' => 'acme/lib', 'version' => '1.0.0'], ['name' => 'acme/other', 'version' => 'dev-main'] + $other];
        $remote = ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0']], 'acme/other' => [['version' => 'dev-main', 'abandoned' => true] + $other]];
        $solver = new ComposerSolver($root, LockFile::fromArray(['packages' => $locked])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), new ArrayVersionSource($remote));

        $solution = $solver->solve(SolveRequest::update(['acme/lib' => '^1.1'])->withJoined(['acme/other']));

        self::assertSame([['acme/lib' => '1.1.0'], ['acme/lib' => '1.0.0']], [$solution->installs(), $solution->updatedFrom()]);
    }

    public function testARequireOfARequireDevPackageSolvesWithTheNewConstraint(): void
    {
        $solver = self::solver(['require-dev' => ['acme/dev' => '^1.0']], [], ['acme/dev' => [['version' => '1.0.0'], ['version' => '2.0.0']]], [['name' => 'acme/dev', 'version' => '1.0.0']]);

        self::assertSame(['acme/dev' => '2.0.0'], $solver->solve(SolveRequest::require('acme/dev', ['acme/dev' => '^2.0'], false))->installs());
    }

    /** `composer require` without `--dev` moves a name out of require-dev, so the move's constraint is the only one left. */
    public function testARequireOfANameInRequireAndRequireDevSolvesWithTheNewConstraint(): void
    {
        $solver = self::solver(['require' => ['acme/lib' => '^1.0'], 'require-dev' => ['acme/lib' => '^1.0']], [['name' => 'acme/lib', 'version' => '1.0.0']], ['acme/lib' => [['version' => '1.0.0'], ['version' => '2.0.0']]]);

        self::assertSame(['acme/lib' => '2.0.0'], $solver->solve(SolveRequest::require('acme/lib', ['acme/lib' => '^2.0'], false))->installs());
        self::assertSame(['acme/lib'], array_keys($solver->root()->getDevRequires()), 'the solve changes a copy of the root');
    }

    public function testAnUpdatePastARequireDevConstraintIsRefusedByThatRootRequirement(): void
    {
        $solver = self::solver(['require-dev' => ['acme/dev' => '^1.0']], [], ['acme/dev' => [['version' => '1.0.0'], ['version' => '2.0.0']]], [['name' => 'acme/dev', 'version' => '1.0.0']]);

        $solution = $solver->solve(SolveRequest::update(['acme/dev' => '^2.0']));

        self::assertSame([['source' => 'root', 'package' => null, 'version' => null, 'link' => 'require-dev', 'target' => null, 'constraint' => '^1.0']], self::rows($solution->holds()));
    }

    /**
     * @return iterable<string, array{string, string, string, string}> the constraints of require and require-dev, the constraint of the hold and its section
     */
    public static function bothSections(): iterable
    {
        yield 'the same constraint' => ['^1.0', '^1.0', '^1.0', 'require'];
        yield 'a narrower require-dev' => ['^1.0', '^1.5', '^1.5', 'require-dev'];
    }

    /**
     * The hold names the section whose constraint the solve read.
     *
     * @dataProvider bothSections
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothSections')]
    public function testAnUpdatePastAConstraintInRequireAndRequireDevNamesTheSectionOfTheSolvedConstraint(string $require, string $dev, string $held, string $section): void
    {
        $solver = self::solver(['require' => ['acme/lib' => $require], 'require-dev' => ['acme/lib' => $dev]], [['name' => 'acme/lib', 'version' => '1.5.0']], ['acme/lib' => [['version' => '1.5.0'], ['version' => '2.0.0']]]);

        $solution = $solver->solve(SolveRequest::update(['acme/lib' => '^2.0']));

        self::assertSame([[$section, $held]], array_map(static fn (array $row): array => [$row['link'], $row['constraint']], self::rows($solution->holds())));
    }

    /**
     * @dataProvider rootSections
     *
     * @param array<string, mixed> $root
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rootSections')]
    public function testARefusalNamesTheRootRequirementThatKeepsADependencyOfTheTargetBehind(array $root): void
    {
        $solver = self::solver(
            $root,
            [
                ['name' => 'acme/bundle', 'version' => '1.0.0', 'require' => ['acme/bridge' => '^5.0']],
                ['name' => 'acme/bridge', 'version' => '5.2.0', 'require' => ['acme/kernel' => '^4.4']],
                ['name' => 'acme/kernel', 'version' => '4.4.0'],
            ],
            ['acme/bridge' => [['version' => '5.2.0', 'require' => ['acme/kernel' => '^4.4']], ['version' => '5.4.0', 'require' => ['acme/kernel' => '^5.3']]]]
        );

        $holds = self::rows($solver->solve(SolveRequest::update(['acme/bridge' => '^5.4']))->holds());

        self::assertContains(['source' => 'package', 'package' => 'acme/bridge', 'version' => '5.4.0', 'link' => 'require', 'target' => 'acme/kernel', 'constraint' => '^5.3'], $holds);
        self::assertContains(['source' => 'root', 'package' => null, 'version' => null, 'link' => 'root-require', 'target' => 'acme/kernel', 'constraint' => '^4.4'], $holds);
    }

    /** W6: of two holds that differ only in the version, the hold names the holder's locked release, the one that holds the move now. */
    public function testARefusalMarksTheHoldOfAPackageAtItsLockedRelease(): void
    {
        $solver = self::solver(
            ['require' => ['acme/lib' => '^1.0', 'acme/h' => '^1.0']],
            [['name' => 'acme/lib', 'version' => '1.0.0'], ['name' => 'acme/h', 'version' => '1.4.1', 'require' => ['acme/lib' => '^1.0']]],
            [
                'acme/lib' => [['version' => '1.0.0'], ['version' => '2.0.0']],
                'acme/h' => [['version' => '1.3.4', 'require' => ['acme/lib' => '^1.0']], ['version' => '1.4.1', 'require' => ['acme/lib' => '^1.0']]],
            ]
        );

        $holds = $solver->solve(SolveRequest::update(['acme/lib' => '^2.0'])->withTransitive(SolveRequest::WITH_ALL_DEPENDENCIES))->holds();
        $marked = [];
        foreach ($holds as $hold) {
            if ($hold->package() === 'acme/h') {
                $marked[(string) $hold->version()] = $hold->isLocked();
            }
        }

        self::assertSame(['1.4.1' => true], $marked);
    }

    /** A root requirement holds a move only through a requirement that it leaves no candidate. */
    public function testARefusalNamesNoRootRequirementOfARequirementThatKeepsACandidate(): void
    {
        $solver = self::solver(
            ['require' => ['acme/lib' => '^1.0', 'acme/dep' => '^1.0', 'acme/y' => '^1.0']],
            [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/dep' => '^1.0']], ['name' => 'acme/dep', 'version' => '1.0.0'], ['name' => 'acme/y', 'version' => '1.0.0']],
            [
                'acme/lib' => [['version' => '1.0.0', 'require' => ['acme/dep' => '^1.0']], ['version' => '1.1.0', 'require' => ['acme/dep' => '^1.1']]],
                'acme/dep' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/y' => '^2.0']]],
                'acme/y' => [['version' => '1.0.0']],
            ]
        );

        $holds = self::rows($solver->solve(SolveRequest::update(['acme/lib' => '^1.1'])->withTransitive(SolveRequest::WITH_ALL_DEPENDENCIES))->holds());

        self::assertContains(['source' => 'root', 'package' => null, 'version' => null, 'link' => 'root-require', 'target' => 'acme/y', 'constraint' => '^1.0'], $holds);
        self::assertNotContains(['source' => 'root', 'package' => null, 'version' => null, 'link' => 'root-require', 'target' => 'acme/dep', 'constraint' => '^1.0'], $holds, 'the root requirement admits acme/dep 1.1.0');
    }

    public function testARefusedPlatformRequirementNamesNoRootRequirementOfThePlatformPackage(): void
    {
        $solver = self::solver(['require' => ['php' => '>=7.4', 'acme/lib' => '^1.0']], [['name' => 'acme/lib', 'version' => '1.0.0']], ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['php' => '>=9.0']]]]);

        $holds = self::rows($solver->solve(SolveRequest::update(['acme/lib' => '^1.1']))->holds());

        self::assertContains(['source' => 'package', 'package' => 'acme/lib', 'version' => '1.1.0', 'link' => 'platform', 'target' => 'php', 'constraint' => '>=9.0'], $holds);
        self::assertNotContains('>=7.4', array_column($holds, 'constraint'), 'the target php meets the root requirement');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function rootSections(): iterable
    {
        yield 'require' => [['require' => ['acme/bundle' => '^1.0', 'acme/kernel' => '^4.4']]];
        yield 'require-dev' => [['require' => ['acme/bundle' => '^1.0'], 'require-dev' => ['acme/kernel' => '^4.4']]];
    }

    /** `composer update` prints the problem without `-v`: a run of versions shows as its first and last. */
    public function testTheProblemTextIsComposersShortTextWithNoLeadingSpace(): void
    {
        $conflicting = array_map(static fn (string $version): array => ['version' => $version, 'conflict' => ['acme/b' => '<2.0']], ['1.1.0', '1.2.0', '1.3.0', '1.4.0']);
        $solver = self::solver(
            ['require' => ['acme/a' => '^1.0', 'acme/b' => '^1.0']],
            [['name' => 'acme/a', 'version' => '1.0.0'], ['name' => 'acme/b', 'version' => '1.0.0']],
            ['acme/a' => array_merge([['version' => '1.0.0']], $conflicting), 'acme/b' => [['version' => '1.0.0']]]
        );

        $problem = (string) $solver->solve(SolveRequest::update(['acme/a' => '^1.1']))->problem();

        self::assertStringStartsWith('Problem 1', $problem);
        self::assertStringContainsString('acme/a[1.1.0, ..., 1.4.0]', $problem);
    }

    public function testTheCopyThatDropsTheConfigPlatformWordsLeavesTheSolverItComesFromAsItWas(): void
    {
        $solver = self::solver(['require' => ['php' => '~8.1.0']], [], []);

        $copy = $solver->withPhpSetByLockrot();

        self::assertStringContainsString('(8.4.99; overridden via config.platform)', (string) $solver->solve(SolveRequest::baseline())->problem());
        self::assertStringContainsString('your php version (8.4.99) does not satisfy', (string) $copy->solve(SolveRequest::baseline())->problem());
    }

    /** The forms of a move of a package with no dependency build one pool, and the solves after them build their own. */
    public function testASolveThatBuildsThePoolOfTheLastSolveGetsTheAnswerOfAFreshSolver(): void
    {
        $root = ['require' => ['acme/a' => '^1.0', 'acme/b' => '^1.0']];
        $locked = [['name' => 'acme/a', 'version' => '1.0.0'], ['name' => 'acme/b', 'version' => '1.0.0']];
        $remote = ['acme/a' => [['version' => '1.0.0'], ['version' => '1.1.0', 'conflict' => ['acme/b' => '<2.0']]], 'acme/b' => [['version' => '1.0.0'], ['version' => '1.1.0']]];
        $inline = SolveRequest::update(['acme/a' => '^1.1']);
        $withDependencies = $inline->withTransitive(SolveRequest::WITH_DEPENDENCIES);
        $solver = self::solver($root, $locked, $remote);

        $solver->solve($inline);
        $again = $solver->solve($withDependencies);
        $after = $solver->solve(SolveRequest::update(['acme/b' => '^1.1']));

        $fresh = self::solver($root, $locked, $remote)->solve($withDependencies);
        self::assertSame(self::rows($fresh->holds()), self::rows($again->holds()));
        self::assertNotSame([], $again->holds());
        self::assertSame($fresh->problem(), $again->problem());
        self::assertSame(['acme/b' => '1.1.0'], $after->installs());
    }

    /** Composer reads the lock as a repository too, so its problem text names the locked version that no remote repository serves. */
    public function testTheProblemTextNamesALockedVersionThatNoRemoteRepositoryServes(): void
    {
        $solver = self::solver(
            ['require' => ['acme/lib' => '^1.0', 'acme/own' => '^1.0']],
            [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/own' => '^1.0']], ['name' => 'acme/own', 'version' => '1.0.0']],
            ['acme/lib' => [['version' => '1.0.0', 'require' => ['acme/own' => '^1.0']], ['version' => '1.1.0', 'require' => ['acme/own' => '^1.0']]]]
        );

        $solution = $solver->solve(SolveRequest::update(['acme/lib' => '^1.1'])->withTransitive(SolveRequest::WITH_ALL_DEPENDENCIES));

        self::assertFalse($solution->resolves());
        self::assertStringContainsString('acme/own[1.0.0]', (string) $solution->problem());
    }

    public function testTheProblemTextNamesTheRootVersionThatARequirementOfTheTargetMisses(): void
    {
        $solver = self::solver(['require' => ['acme/user' => '^1.0']], [['name' => 'acme/user', 'version' => '1.0.0']], ['acme/user' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/app' => '^2.0']]]]);

        self::assertStringContainsString('acme/app[1.0.0]', (string) $solver->solve(SolveRequest::update(['acme/user' => '^1.1']))->problem());
    }

    /** As `composer update` does, the platform's own version stays fixed unless the root provides that same version. */
    public function testAPlatformPackageThatTheRootProvidesAtAnotherVersionStaysFixed(): void
    {
        $solver = self::solver(['require' => ['acme/user' => '^1.0'], 'provide' => ['php' => '7.0.0']], [['name' => 'acme/user', 'version' => '1.0.0']], ['acme/user' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['php' => '^8.4']]]]);

        self::assertSame(['acme/user' => '1.1.0'], $solver->solve(SolveRequest::update(['acme/user' => '^1.1']))->installs());
    }

    public function testARootRequirementThatARequireWritesHoldsOnlyForThatSolve(): void
    {
        $solver = self::solver(['require' => ['acme/lib' => '^1.0']], [['name' => 'acme/lib', 'version' => '1.0.0']], ['acme/lib' => [['version' => '1.0.0'], ['version' => '2.0.0']]]);

        $required = $solver->solve(SolveRequest::require('acme/lib', ['acme/lib' => '^2.0'], false));
        $updated = $solver->solve(SolveRequest::update(['acme/lib' => '^2.0']));

        self::assertSame([true, false], [$required->resolves(), $updated->resolves()]);
        self::assertSame('^1.0', $solver->root()->getRequires()['acme/lib']->getPrettyConstraint());
    }

    /**
     * @dataProvider rootLinks
     *
     * @param array<string, mixed> $links
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rootLinks')]
    public function testARequirementOfTheTargetThatTheRootProvidesOrReplacesIsMet(array $links): void
    {
        $solver = self::solver(['require' => ['acme/user' => '^1.0']] + $links, [['name' => 'acme/user', 'version' => '1.0.0']], ['acme/user' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/virtual' => '^1.0']]]]);

        self::assertSame(['acme/user' => '1.1.0'], $solver->solve(SolveRequest::update(['acme/user' => '^1.1']))->installs());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function rootLinks(): iterable
    {
        yield 'provide' => [['provide' => ['acme/virtual' => '1.0.0']]];
        yield 'replace' => [['replace' => ['acme/virtual' => '1.0.0']]];
    }

    /**
     * Ruling R6-37: Composer's filter removes an abandoned root too, so the root no longer provides
     * acme/virtual. `composer update acme/user:^1.1` of Composer 2.10.3 refuses this project.
     */
    public function testAnAbandonedRootProvidesNothingWhenTheConfigBlocksAbandonedPackages(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['audit' => ['block-abandoned' => true]]]);
        $solver = self::solver(['require' => ['acme/user' => '^1.0'], 'provide' => ['acme/virtual' => '1.0.0'], 'abandoned' => true], [['name' => 'acme/user', 'version' => '1.0.0']], ['acme/user' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/virtual' => '^1.0']]]])
            ->withAdvisories(new PoolAdvisories(AdvisoryBlocking::fromConfig($config), static fn (array $names, bool $complete): array => []));

        $solution = $solver->solve(SolveRequest::update(['acme/user' => '^1.1']));

        self::assertFalse($solution->resolves());
        self::assertNull($solution->error());
        self::assertStringContainsString('acme/user 1.1.0 requires acme/virtual ^1.0 -> could not be found in any version', (string) $solution->problem());
    }

    /** A package that only provides a blocked name is no version of it: Composer blocks by the names a package has and replaces. */
    public function testABlockedRangeOfANameThatAReleaseOnlyProvidesLeavesTheReleaseInThePool(): void
    {
        $solver = self::solver(['require' => ['acme/impl' => '^1.0']], [['name' => 'acme/impl', 'version' => '1.0.0']], ['acme/impl' => [['version' => '1.0.0'], ['version' => '1.1.0', 'provide' => ['acme/virtual' => '1.1.0']]]])
            ->withAdvisories(self::advisories(['acme/virtual' => ['*']]));

        self::assertSame(['acme/impl' => '1.1.0'], $solver->solve(SolveRequest::update(['acme/impl' => '^1.1']))->installs());
    }

    /** Composer looks up the advisories of the pool's names that the update can move: a name that stays locked is not asked. */
    public function testTheAdvisoryLookupAsksOnlyTheNamesThatTheUpdateCanMove(): void
    {
        $asked = [];
        $lookup = static function (array $names, bool $complete) use (&$asked): array {
            $asked[] = $names;

            return [];
        };
        $solver = self::solver(
            ['require' => ['acme/lib' => '^1.0', 'acme/kept' => '^1.0']],
            [['name' => 'acme/lib', 'version' => '1.0.0'], ['name' => 'acme/kept', 'version' => '1.0.0']],
            ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0']], 'acme/kept' => [['version' => '1.0.0'], ['version' => '1.1.0']]]
        );
        $blocking = $solver->withAdvisories(new PoolAdvisories(AdvisoryBlocking::everyAdvisory(), $lookup));

        $solver->solve(SolveRequest::update(['acme/lib' => '^1.1']));
        self::assertSame([], $asked, 'the solver without advisories looks nothing up');

        self::assertTrue($blocking->solve(SolveRequest::update(['acme/lib' => '^1.1']))->resolves());
        self::assertSame([['acme/lib']], $asked);
    }

    public function testEveryAdvisoryRangeOfOneNameBlocks(): void
    {
        $solver = self::solver(['require' => ['acme/lib' => '^1.0']], [['name' => 'acme/lib', 'version' => '1.0.0']], ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0'], ['version' => '1.2.0']]])
            ->withAdvisories(self::advisories(['acme/lib' => ['>=1.1,<1.2', '>=1.2,<1.3']]));

        self::assertFalse($solver->solve(SolveRequest::update(['acme/lib' => '^1.1']))->resolves());
    }

    /**
     * Ruling R6-35: Composer reads the partial records first, and a partial record carries no
     * severity, so `ignore-severity: low` leaves it blocking. The run's complete records only seed
     * the answers that Composer asks after.
     *
     * @dataProvider seededOrNot
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('seededOrNot')]
    public function testAnIgnoredSeverityDoesNotUnblockAPartialRecord(bool $seeded): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['audit' => ['ignore-severity' => ['low' => ['apply' => 'block']]]]]);
        $parser = new VersionParser();
        $partial = PartialSecurityAdvisory::create('acme/lib', ['advisoryId' => 'PKSA-1111-2222-3333', 'affectedVersions' => '>=2.0.0,<2.0.5'], $parser);
        $full = PartialSecurityAdvisory::create('acme/lib', ['advisoryId' => 'PKSA-1111-2222-3333', 'affectedVersions' => '>=2.0.0,<2.0.5', 'title' => 'A flaw', 'sources' => [['name' => 'GitHub', 'remoteId' => 'GHSA-aaaa-bbbb-cccc']], 'reportedAt' => '2026-01-01 00:00:00', 'cve' => null, 'link' => null, 'severity' => 'low'], $parser);
        $advisories = new PoolAdvisories(AdvisoryBlocking::fromConfig($config), static fn (array $names, bool $complete): array => array_intersect_key(['acme/lib' => [$complete ? $full : $partial]], array_flip($names)));
        $advisories = $seeded ? $advisories->withRecords(['acme/lib' => [$full]], ['acme/lib']) : $advisories;
        $solver = self::solver(['require' => ['acme/lib' => '^1.0']], [['name' => 'acme/lib', 'version' => '1.0.0']], ['acme/lib' => [['version' => '1.0.0'], ['version' => '2.0.0'], ['version' => '2.0.4']]])
            ->withAdvisories($advisories);

        self::assertFalse($solver->solve(SolveRequest::require('acme/lib', ['acme/lib' => '^2.0'], false))->resolves());
    }

    /** @return iterable<string, array{bool}> */
    public static function seededOrNot(): iterable
    {
        yield 'the run\'s records seed the complete answer' => [true];
        yield 'no seed' => [false];
    }

    /**
     * Ruling R6-36: a root alias of a dev branch (`dev-main as 1.0.0`) is a stable 1.0.0, and the
     * advisory covers it. Composer asks only about dev-main, so only a range that meets dev-main
     * reaches the filter. Without the alias, acme/user's `^1.0` meets no release that the root allows.
     */
    public function testAnAdvisoryBlocksTheRootAliasOfADevBranch(): void
    {
        $dev = ['version' => 'dev-main', 'source' => ['type' => 'git', 'url' => 'https://example.com/lib.git', 'reference' => 'abc123']];
        $aliases = [['package' => 'acme/lib', 'version' => 'dev-main', 'alias' => '1.0.0', 'alias_normalized' => '1.0.0.0']];
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => ['acme/lib' => 'dev-main as 1.0.0', 'acme/user' => '^1.0']], RootPackage::class);
        self::assertInstanceOf(RootPackage::class, $root);
        $root->setStabilityFlags(['acme/lib' => 20]);
        $root->setAliases($aliases);
        $locked = LockFile::fromArray(['packages' => [['name' => 'acme/lib'] + $dev, ['name' => 'acme/user', 'version' => '1.0.0', 'require' => ['acme/lib' => '^1.0']]], 'aliases' => $aliases])->lockedRepository();
        $remote = new ArrayVersionSource(['acme/lib' => [$dev], 'acme/user' => [['version' => '1.0.0', 'require' => ['acme/lib' => '^1.0']]]]);
        $open = new ComposerSolver($root, $locked, new PlatformRepository([], ['php' => '8.4.99']), $remote);

        $refused = $open->withAdvisories(self::advisories(['acme/lib' => ['*']]))->solve(SolveRequest::unlock(['acme/lib']));

        self::assertTrue($open->solve(SolveRequest::unlock(['acme/lib']))->resolves(), 'the alias satisfies acme/user without blocking');
        self::assertFalse($refused->resolves());
        self::assertContains('acme/user', array_map(static fn (Holder $hold): ?string => $hold->package(), $refused->holds()));
    }

    /** Composer prints the problem text without colour: no console markup reaches a report. */
    public function testTheProblemTextOfABlockedVersionCarriesNoConsoleMarkup(): void
    {
        $solver = self::solver(['require' => ['acme/lib' => '^1.0']], [['name' => 'acme/lib', 'version' => '1.0.0']], ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0']]])
            ->withAdvisories(self::advisories(['acme/lib' => ['>=1.1,<1.2']]));

        $problem = (string) $solver->solve(SolveRequest::require('acme/lib', ['acme/lib' => '^1.1'], false))->problem();

        self::assertStringContainsString('affected by security advisories', $problem);
        self::assertStringContainsString('PKSA-1111-2222-3330', $problem);
        foreach (['<href=', '</>', '<warning>'] as $markup) {
            self::assertStringNotContainsString($markup, $problem);
        }
    }

    /**
     * Ruling R2-47, per solve: the names whose advisories the lookup could not read travel with the
     * Solution of the solve that asked them, and only the names that its result holds.
     */
    public function testASolveCarriesTheUnreadNamesThatItsResultHolds(): void
    {
        $lookup = static fn (array $names, bool $complete): ?array => \in_array('acme/new-dependency', $names, true) ? null : [];
        $solver = self::solver(
            ['require' => ['acme/lib' => '^1.0', 'acme/other' => '^1.0']],
            [['name' => 'acme/lib', 'version' => '1.0.0'], ['name' => 'acme/other', 'version' => '1.0.0']],
            ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/new-dependency' => '^1.0']]], 'acme/new-dependency' => [['version' => '1.0.0']], 'acme/other' => [['version' => '1.0.0'], ['version' => '1.1.0']]]
        )->withAdvisories(new PoolAdvisories(AdvisoryBlocking::everyAdvisory(), $lookup));

        $unread = $solver->solve(SolveRequest::update(['acme/lib' => '^1.1']));
        $read = $solver->solve(SolveRequest::update(['acme/other' => '^1.1']));

        self::assertSame([true, ['acme/lib', 'acme/new-dependency']], [$unread->resolves(), $unread->advisoryUnread()]);
        self::assertSame([true, []], [$read->resolves(), $read->advisoryUnread()]);
    }

    public function testAFilterThatCannotBeBuiltFailsEverySolveThatBlocks(): void
    {
        $solver = self::solver(['require' => ['acme/lib' => '^1.0']], [['name' => 'acme/lib', 'version' => '1.0.0']], ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0']]]);
        $broken = $solver->withAdvisories(new PoolAdvisories(AdvisoryBlocking::unbuildable(new \Error('drift')), static fn (array $names, bool $complete): array => []));

        self::assertSame(\RuntimeException::class, ($broken->solve(SolveRequest::baseline())->error() ?? [])['class'] ?? null);
        self::assertTrue($solver->solve(SolveRequest::baseline())->resolves(), 'a solver without advisories runs no filter');
    }

    /**
     * Ruling R6-2: the machine that runs lockrot decides no move. The solve ignores each
     * requirement in ComposerSolver::IGNORED_PLATFORM, which no runner provides here.
     *
     * @dataProvider hostRequirements
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hostRequirements')]
    public function testAReleaseThatRequiresAnIgnoredPlatformPackageResolves(string $name): void
    {
        $solver = self::solver(['require' => ['acme/lib' => '^1.0']], [['name' => 'acme/lib', 'version' => '1.0.0']], ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => [$name => '*']]]]);

        self::assertSame(['acme/lib' => '1.1.0'], $solver->solve(SolveRequest::update(['acme/lib' => '^1.1']))->installs());
    }

    /** @return iterable<string, array{string}> */
    public static function hostRequirements(): iterable
    {
        foreach (['ext-lockrotprobe', 'lib-lockrotprobe', 'php-debug', 'php-ipv6', 'php-zts'] as $name) {
            yield $name => [$name];
        }
    }

    /**
     * A name that `config.platform` sets is the project's own fact, so the solve checks it. An
     * override of false removes the package, as Composer's PlatformRepository does.
     *
     * @dataProvider configuredPlatform
     *
     * @param array<string, string|false> $platform
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('configuredPlatform')]
    public function testAReleaseThatRequiresMoreThanTheConfiguredPlatformIsRefused(array $platform, string $name, string $constraint): void
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => ['acme/lib' => '^1.0']], RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $root);
        $source = new ArrayVersionSource(['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => [$name => $constraint]]]]);
        $solver = new ComposerSolver($root, LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99'] + $platform), $source, array_keys($platform));

        self::assertFalse($solver->solve(SolveRequest::update(['acme/lib' => '^1.1']))->resolves());
    }

    /** @return iterable<string, array{array<string, string|false>, string, string}> */
    public static function configuredPlatform(): iterable
    {
        yield 'a library below the requirement' => [['lib-icu' => '60.1'], 'lib-icu', '>=70'];
        yield 'an extension set to false' => [['ext-lockrotprobe' => false], 'ext-lockrotprobe', '*'];
    }

    /** The copy offers the locked path package, and the solver it comes from still offers no version of it. */
    public function testALockedOnlyCopyLeavesTheSolverItComesFromAsItWas(): void
    {
        $solver = self::solver(['require' => ['acme/core' => '^7.0']], [['name' => 'acme/core', 'version' => '7.0.0', 'dist' => ['type' => 'path', 'url' => 'core', 'reference' => 'abc']]], []);

        $copy = $solver->withLockedOnly(['acme/core']);

        self::assertSame([true, false], [$copy->solve(SolveRequest::baseline())->resolves(), $solver->solve(SolveRequest::baseline())->resolves()]);
    }

    /**
     * The lock aliases the path package dev-main as 1.0.0, and composer.json no longer declares the
     * alias. Composer reads the path package with no alias, so acme/lib's `^1.0` refuses the baseline.
     */
    public function testALockedOnlyCopyTakesNoRootAliasFromTheLock(): void
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => ['acme/core' => 'dev-main', 'acme/lib' => '^1.0']], RootPackage::class);
        self::assertInstanceOf(RootPackage::class, $root);
        $root->setMinimumStability('dev');
        $lock = LockFile::fromArray([
            'packages' => [
                ['name' => 'acme/core', 'version' => 'dev-main', 'dist' => ['type' => 'path', 'url' => 'core', 'reference' => 'abc']],
                ['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/core' => '^1.0'], 'notification-url' => 'https://packagist.org/downloads/'],
            ],
            'aliases' => [['package' => 'acme/core', 'version' => 'dev-main', 'alias' => '1.0.0', 'alias_normalized' => '1.0.0.0']],
        ]);
        $solver = (new ComposerSolver($root, $lock->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), new ArrayVersionSource(['acme/lib' => [['version' => '1.0.0', 'require' => ['acme/core' => '^1.0']]]])))->withLockedOnly(['acme/core']);

        self::assertFalse($solver->solve(SolveRequest::baseline())->resolves());
    }

    /**
     * `composer require` rewrites composer.json and loads it again, so the stability flag and the
     * root alias of the old constraint do not reach its solve. The only tag needs php 9.
     *
     * @dataProvider pinnedRoots
     *
     * @param array<string, 0|5|10|15|20>                                                            $flags
     * @param list<array{package: string, version: string, alias: string, alias_normalized: string}> $aliases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pinnedRoots')]
    public function testARequireOfAPinnedPackageSolvesWithoutTheOldStabilityFlagAndAlias(string $constraint, array $flags, array $aliases): void
    {
        $dev = ['version' => 'dev-main', 'extra' => ['branch-alias' => ['dev-main' => '1.x-dev']], 'source' => ['type' => 'git', 'url' => 'https://example.com/lib.git', 'reference' => 'abc123']];
        $solver = self::pinned(['acme/lib' => $constraint], $flags, $aliases, [['name' => 'acme/lib'] + $dev], ['acme/lib' => [$dev, ['version' => '1.5.0', 'require' => ['php' => '>=9.0']]]]);

        self::assertFalse($solver->solve(SolveRequest::require('acme/lib', ['acme/lib' => '^1.5.0'], false))->resolves(), 'the locked dev-main satisfies ^1.5.0 only through the old flag or alias');
        self::assertTrue($solver->solve(SolveRequest::baseline())->resolves(), 'the root as composer.json loads it keeps its flag and alias');
    }

    /** @return iterable<string, array{string, array<string, 0|5|10|15|20>, list<array{package: string, version: string, alias: string, alias_normalized: string}>}> */
    public static function pinnedRoots(): iterable
    {
        yield 'a dev branch' => ['dev-main', ['acme/lib' => 20], []];
        yield 'a dev branch with a root alias' => ['dev-main as 1.5.0', ['acme/lib' => 20], [['package' => 'acme/lib', 'version' => 'dev-main', 'alias' => '1.5.0', 'alias_normalized' => '1.5.0.0']]];
    }

    /**
     * At minimum-stability dev no flag holds the branch, so the root alias alone gives the
     * consumer the acme/lib ^1.5.0 that it requires. The only tag needs php 9.
     */
    public function testARequireOfAnAliasedBranchSolvesWithoutTheAlias(): void
    {
        $dev = ['version' => 'dev-main', 'source' => ['type' => 'git', 'url' => 'https://example.com/lib.git', 'reference' => 'abc123']];
        $consumer = ['version' => '1.0.0', 'require' => ['acme/lib' => '^1.5.0']];
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => ['acme/lib' => 'dev-main as 1.5.0', 'acme/consumer' => '^1.0']], RootPackage::class);
        self::assertInstanceOf(RootPackage::class, $root);
        $root->setMinimumStability('dev');
        $root->setAliases([['package' => 'acme/lib', 'version' => 'dev-main', 'alias' => '1.5.0', 'alias_normalized' => '1.5.0.0']]);
        $locked = LockFile::fromArray([
            'packages' => [['name' => 'acme/lib'] + $dev, ['name' => 'acme/consumer'] + $consumer],
            'aliases' => [['package' => 'acme/lib', 'version' => 'dev-main', 'alias' => '1.5.0', 'alias_normalized' => '1.5.0.0']],
        ])->lockedRepository();
        $solver = new ComposerSolver($root, $locked, new PlatformRepository([], ['php' => '8.4.99']), new ArrayVersionSource(['acme/lib' => [$dev, ['version' => '1.5.0', 'require' => ['php' => '>=9.0']]], 'acme/consumer' => [$consumer]]));

        self::assertTrue($solver->solve(SolveRequest::baseline())->resolves(), 'the lock installs with its alias');
        self::assertFalse($solver->solve(SolveRequest::require('acme/lib', ['acme/lib' => 'dev-main'], false))->resolves(), 'the branch satisfies the consumer only through the old alias');
        self::assertTrue($solver->solve(SolveRequest::require('acme/consumer', ['acme/consumer' => '^1.0'], false)->withTransitive(SolveRequest::WITH_ALL_DEPENDENCIES))->resolves(), 'a require of another name keeps the alias of the branch that it unlocks');
    }

    public function testARequireDropsTheBetaFlagOfTheOldConstraintAndTakesTheFlagOfTheNewOne(): void
    {
        $solver = self::pinned(['acme/lib' => '^1.0@beta'], ['acme/lib' => 10], [], [['name' => 'acme/lib', 'version' => '1.0.0']], ['acme/lib' => [['version' => '1.0.0'], ['version' => '2.1.0-beta1']]]);

        self::assertFalse($solver->solve(SolveRequest::require('acme/lib', ['acme/lib' => '^2.0'], false))->resolves(), 'minimum-stability stable admits no beta of ^2.0');
        self::assertSame(['acme/lib' => '2.1.0-beta1'], $solver->solve(SolveRequest::require('acme/lib', ['acme/lib' => '^2.0@beta'], false))->installs());
    }

    /**
     * @param array<string, mixed>                      $root
     * @param list<array<string, mixed>>                $locked
     * @param array<string, list<array<string, mixed>>> $remote
     * @param list<array<string, mixed>>                $lockedDev
     */
    private static function solver(array $root, array $locked, array $remote, array $lockedDev = []): ComposerSolver
    {
        $package = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0'] + $root, RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $package);

        return new ComposerSolver($package, LockFile::fromArray(['packages' => $locked, 'packages-dev' => $lockedDev])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), new ArrayVersionSource($remote));
    }

    /**
     * Composer's default blocking, with the lookup answering one record for each range.
     *
     * @param array<string, list<string>> $ranges name => each affected range
     */
    private static function advisories(array $ranges): PoolAdvisories
    {
        $records = [];
        foreach ($ranges as $name => $list) {
            foreach ($list as $i => $range) {
                $records[$name][] = PartialSecurityAdvisory::create($name, ['advisoryId' => 'PKSA-1111-2222-333'.$i, 'affectedVersions' => $range], new VersionParser());
            }
        }

        return new PoolAdvisories(AdvisoryBlocking::everyAdvisory(), static fn (array $names, bool $complete): array => array_intersect_key($records, array_flip($names)));
    }

    /**
     * The root as RootPackageLoader loads composer.json, at minimum-stability stable: ArrayLoader
     * reads no flag or alias from a constraint, so the test sets them.
     *
     * @param array<string, string>                                                                  $require
     * @param array<string, 0|5|10|15|20>                                                            $flags
     * @param list<array{package: string, version: string, alias: string, alias_normalized: string}> $aliases
     * @param list<array<string, mixed>>                                                             $locked
     * @param array<string, list<array<string, mixed>>>                                              $remote
     */
    private static function pinned(array $require, array $flags, array $aliases, array $locked, array $remote): ComposerSolver
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => $require], RootPackage::class);
        self::assertInstanceOf(RootPackage::class, $root);
        $root->setStabilityFlags($flags);
        $root->setAliases($aliases);

        return new ComposerSolver($root, LockFile::fromArray(['packages' => $locked])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), new ArrayVersionSource($remote));
    }

    /**
     * @param list<Holder> $holds
     *
     * @return list<array<string, ?string>>
     */
    private static function rows(array $holds): array
    {
        return array_map(static fn (Holder $hold): array => ['source' => $hold->source(), 'package' => $hold->source() === Holder::ROOT ? null : $hold->package(), 'version' => $hold->version(), 'link' => $hold->link(), 'target' => $hold->target(), 'constraint' => $hold->constraint()], $holds);
    }
}
