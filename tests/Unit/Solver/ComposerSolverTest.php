<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Config;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackage;
use Composer\Package\RootPackageInterface;
use Composer\Repository\PlatformRepository;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use Lockrot\Lock\LockFile;
use Lockrot\Security\Holder;
use Lockrot\Solver\AdvisoryBlocking;
use Lockrot\Solver\ComposerFeatures;
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

    public function testARequireOfARequireDevPackageSolvesWithTheNewConstraint(): void
    {
        $solver = self::solver(['require-dev' => ['acme/dev' => '^1.0']], [], ['acme/dev' => [['version' => '1.0.0'], ['version' => '2.0.0']]], [['name' => 'acme/dev', 'version' => '1.0.0']]);

        self::assertSame(['acme/dev' => '2.0.0'], $solver->solve(SolveRequest::require('acme/dev', ['acme/dev' => '^2.0']))->installs());
    }

    /** `composer require` without `--dev` moves a name out of require-dev, so the move's constraint is the only one left. */
    public function testARequireOfANameInRequireAndRequireDevSolvesWithTheNewConstraint(): void
    {
        $solver = self::solver(['require' => ['acme/lib' => '^1.0'], 'require-dev' => ['acme/lib' => '^1.0']], [['name' => 'acme/lib', 'version' => '1.0.0']], ['acme/lib' => [['version' => '1.0.0'], ['version' => '2.0.0']]]);

        self::assertSame(['acme/lib' => '2.0.0'], $solver->solve(SolveRequest::require('acme/lib', ['acme/lib' => '^2.0']))->installs());
        self::assertSame(['acme/lib'], array_keys($solver->root()->getDevRequires()), 'the solve changes a copy of the root');
    }

    public function testAnUpdatePastARequireDevConstraintIsRefusedByThatRootRequirement(): void
    {
        if (!ComposerFeatures::hasTemporaryConstraints()) {
            self::markTestSkipped('Composer 2.2 and 2.3 join the inline constraint to the root requirement: that requirement is the request');
        }
        $solver = self::solver(['require-dev' => ['acme/dev' => '^1.0']], [], ['acme/dev' => [['version' => '1.0.0'], ['version' => '2.0.0']]], [['name' => 'acme/dev', 'version' => '1.0.0']]);

        $solution = $solver->solve(SolveRequest::update(['acme/dev' => '^2.0']));

        self::assertSame([['source' => 'root', 'package' => null, 'version' => null, 'link' => 'require', 'target' => null, 'constraint' => '^1.0']], self::rows($solution->holds()));
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

    /** A root requirement holds a move only through a requirement that it leaves no candidate. */
    public function testARefusalNamesNoRootRequirementOfARequirementThatKeepsACandidate(): void
    {
        if (!ComposerFeatures::hasTemporaryConstraints()) {
            self::markTestSkipped('Composer 2.2 lists the root requirement of each dependency that -W frees as a rule of the problem');
        }
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

        $required = $solver->solve(SolveRequest::require('acme/lib', ['acme/lib' => '^2.0']));
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

    /** The project itself can be an abandoned package: `block-abandoned` removes no root. */
    public function testAnAbandonedRootStaysWhenTheConfigBlocksAbandonedPackages(): void
    {
        if (!ComposerFeatures::blocksAdvisories()) {
            self::markTestSkipped('Composer below 2.9 has no SecurityAdvisoryPoolFilter: it blocks nothing in an update');
        }
        $config = new Config(false);
        $config->merge(['config' => ['audit' => ['block-abandoned' => true]]]);
        $solver = self::solver(['require' => ['acme/user' => '^1.0'], 'provide' => ['acme/virtual' => '1.0.0'], 'abandoned' => true], [['name' => 'acme/user', 'version' => '1.0.0']], ['acme/user' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/virtual' => '^1.0']]]])
            ->withAdvisories(new PoolAdvisories(AdvisoryBlocking::fromConfig($config), static fn (array $names, bool $complete): array => []));

        self::assertSame(['acme/user' => '1.1.0'], $solver->solve(SolveRequest::update(['acme/user' => '^1.1']))->installs());
    }

    /** A package that only provides a blocked name is no version of it: Composer blocks by the names a package has and replaces. */
    public function testABlockedRangeOfANameThatAReleaseOnlyProvidesLeavesTheReleaseInThePool(): void
    {
        $solver = self::solver(['require' => ['acme/impl' => '^1.0']], [['name' => 'acme/impl', 'version' => '1.0.0']], ['acme/impl' => [['version' => '1.0.0'], ['version' => '1.1.0', 'provide' => ['acme/virtual' => '1.1.0']]]], [], ['acme/virtual' => [(new VersionParser())->parseConstraints('*')]]);

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

    public function testTheBlockedRangesAndTheAdvisoryRangesOfOneNameBothBlock(): void
    {
        if (!class_exists(PartialSecurityAdvisory::class)) {
            self::markTestSkipped('Composer below 2.4 has no advisory records');
        }
        $parser = new VersionParser();
        $lookup = static fn (array $names, bool $complete): array => ['acme/lib' => [PartialSecurityAdvisory::create('acme/lib', ['advisoryId' => 'PKSA-1111-2222-3333', 'affectedVersions' => '>=1.2,<1.3'], $parser)]];
        $solver = self::solver(['require' => ['acme/lib' => '^1.0']], [['name' => 'acme/lib', 'version' => '1.0.0']], ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0'], ['version' => '1.2.0']]], [], ['acme/lib' => [$parser->parseConstraints('>=1.1,<1.2')]])
            ->withAdvisories(new PoolAdvisories(AdvisoryBlocking::everyAdvisory(), $lookup));

        self::assertFalse($solver->solve(SolveRequest::update(['acme/lib' => '^1.1']))->resolves());
    }

    /**
     * @param array<string, mixed>                      $root
     * @param list<array<string, mixed>>                $locked
     * @param array<string, list<array<string, mixed>>> $remote
     * @param list<array<string, mixed>>                $lockedDev
     * @param array<string, list<ConstraintInterface>>  $blocked
     */
    private static function solver(array $root, array $locked, array $remote, array $lockedDev = [], array $blocked = []): ComposerSolver
    {
        $package = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0'] + $root, RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $package);

        return new ComposerSolver($package, LockFile::fromArray(['packages' => $locked, 'packages-dev' => $lockedDev])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), new ArrayVersionSource($remote), $blocked);
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
