<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackage;
use Composer\Package\RootPackageInterface;
use Composer\Repository\PlatformRepository;
use Composer\Semver\VersionParser;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Security\Holder;
use Lockrot\Solver\ComposerSolver;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveQuery;
use Lockrot\Solver\SolverChecker;
use Lockrot\Solver\VersionSource;
use Lockrot\Tests\Support\ArrayVersionSource;
use PHPUnit\Framework\TestCase;

/**
 * Each case is the shape of a corpus move that the solver spike measured against
 * `composer ... --dry-run`. The tests run on every Composer of the CI matrix, so they cover the
 * inline-constraint path of Composer 2.4 and later and the joined root requirement of 2.2.
 */
final class SolverCheckerTest extends TestCase
{
    /** wallabag 2.6.14's symfony/yaml: the target conflicts with a console that the root keeps at 4.4. */
    public function testAMoveWhoseTargetConflictsWithALockedRootRequirementIsRefusedWithTheConflictAndTheRootRequirement(): void
    {
        $checker = self::checker(
            ['symfony/console' => '^4.4', 'acme/handler' => '^2.1'],
            [
                ['name' => 'symfony/console', 'version' => 'v4.4.49'],
                ['name' => 'acme/handler', 'version' => 'v2.1.5', 'require' => ['symfony/yaml' => '^4.0 || ^5.0']],
                ['name' => 'symfony/yaml', 'version' => 'v5.3.14'],
            ],
            ['symfony/yaml' => [['version' => 'v5.3.14'], ['version' => 'v5.4.54', 'conflict' => ['symfony/console' => '<5.3']]]]
        );

        $outcome = $checker->check(MoveQuery::update('symfony/yaml', '^5.4.54', 'v5.4.54'));

        self::assertSame(['result' => 'refused', 'form' => null, 'reason' => null], $outcome->check()->jsonSerialize());
        self::assertContains(['source' => 'package', 'package' => 'symfony/yaml', 'version' => 'v5.4.54', 'link' => 'conflict', 'target' => 'symfony/console', 'constraint' => '<5.3'], self::rows($outcome->holds()));
        self::assertContains(['source' => 'root', 'package' => null, 'version' => null, 'link' => 'root-require', 'target' => 'symfony/console', 'constraint' => '^4.4'], self::rows($outcome->holds()));
        self::assertNotNull($outcome->problem());
    }

    /** wallabag 2.6.14's symfony/monolog-bridge: the target needs a http-kernel that the root keeps at 4.4. */
    public function testAMoveWhoseTargetNeedsANewerRootRequirementIsRefusedWithTheRequirementAndTheRootConstraint(): void
    {
        $checker = self::checker(
            ['symfony/http-kernel' => '^4.4.51', 'symfony/monolog-bundle' => '^3.8'],
            [
                ['name' => 'symfony/http-kernel', 'version' => 'v4.4.51'],
                ['name' => 'symfony/monolog-bundle', 'version' => 'v3.8.0', 'require' => ['symfony/monolog-bridge' => '~4.4 || ^5.0']],
                ['name' => 'symfony/monolog-bridge', 'version' => 'v5.2.12', 'require' => ['symfony/http-kernel' => '^4.4 || ^5.0']],
            ],
            ['symfony/monolog-bridge' => [['version' => 'v5.2.12', 'require' => ['symfony/http-kernel' => '^4.4 || ^5.0']], ['version' => 'v5.4.52', 'require' => ['symfony/http-kernel' => '^5.3']]]]
        );

        $outcome = $checker->check(MoveQuery::update('symfony/monolog-bridge', '^5.4.52', 'v5.4.52'));

        self::assertSame(MoveCheck::REFUSED, $outcome->check()->result());
        self::assertContains(['source' => 'package', 'package' => 'symfony/monolog-bridge', 'version' => 'v5.4.52', 'link' => 'require', 'target' => 'symfony/http-kernel', 'constraint' => '^5.3'], self::rows($outcome->holds()));
        self::assertContains(['source' => 'root', 'package' => null, 'version' => null, 'link' => 'root-require', 'target' => 'symfony/http-kernel', 'constraint' => '^4.4.51'], self::rows($outcome->holds()));
    }

    /** magento's guzzlehttp/guzzle: the target needs a newer promises that no root requirement holds. */
    public function testAMoveThatNeedsADependencyUpdateResolvesWithDependencies(): void
    {
        $checker = self::checker(
            ['guzzlehttp/guzzle' => '^7.0'],
            [
                ['name' => 'guzzlehttp/guzzle', 'version' => '7.10.0', 'require' => ['guzzlehttp/promises' => '^2.0']],
                ['name' => 'guzzlehttp/promises', 'version' => '2.3.0'],
            ],
            [
                'guzzlehttp/guzzle' => [['version' => '7.10.0', 'require' => ['guzzlehttp/promises' => '^2.0']], ['version' => '7.15.2', 'require' => ['guzzlehttp/promises' => '^2.5.1']]],
                'guzzlehttp/promises' => [['version' => '2.3.0'], ['version' => '2.5.3']],
            ]
        );

        $outcome = $checker->check(MoveQuery::update('guzzlehttp/guzzle', '^7.15.2', '7.15.2'));

        self::assertSame(['result' => 'resolves', 'form' => 'with-dependencies', 'reason' => null], $outcome->check()->jsonSerialize());
        self::assertSame('7.15.2', $outcome->installs());
        self::assertSame([], $outcome->holds());
    }

    /** drupal/recommended-project: the pinned dependency of the moved package is a root requirement itself. */
    public function testAMoveWhosePinnedDependencyIsARootRequirementResolvesOnlyWithAllDependencies(): void
    {
        $checker = self::checker(
            ['drupal/core-recommended' => '^11.4', 'drupal/core-composer-scaffold' => '^11.4'],
            [
                ['name' => 'drupal/core-recommended', 'version' => '11.4.6', 'require' => ['drupal/core' => '11.4.6']],
                ['name' => 'drupal/core', 'version' => '11.4.6', 'require' => ['drupal/core-composer-scaffold' => '11.4.6']],
                ['name' => 'drupal/core-composer-scaffold', 'version' => '11.4.6'],
            ],
            [
                'drupal/core-recommended' => [['version' => '11.4.6', 'require' => ['drupal/core' => '11.4.6']], ['version' => '11.4.10', 'require' => ['drupal/core' => '11.4.10']]],
                'drupal/core' => [['version' => '11.4.6', 'require' => ['drupal/core-composer-scaffold' => '11.4.6']], ['version' => '11.4.10', 'require' => ['drupal/core-composer-scaffold' => '11.4.10']]],
                'drupal/core-composer-scaffold' => [['version' => '11.4.6'], ['version' => '11.4.10']],
            ]
        );

        self::assertSame(MoveCheck::REFUSED, $checker->check(MoveQuery::update('drupal/core', '^11.4.10', '11.4.10'))->check()->result());
        self::assertSame(['result' => 'resolves', 'form' => 'with-all-dependencies', 'reason' => null], $checker->check(MoveQuery::require('drupal/core-recommended', '^11.4.10', '11.4.10'))->check()->jsonSerialize());
    }

    public function testARequireThatALockedPackageExcludesIsRefusedWithThatPackagesRequirement(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0', 'acme/user' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.2.0'], ['name' => 'acme/user', 'version' => '1.0.0', 'require' => ['acme/lib' => '^1.0']]],
            ['acme/lib' => [['version' => '1.2.0'], ['version' => '2.0.0']], 'acme/user' => [['version' => '1.0.0', 'require' => ['acme/lib' => '^1.0']]]]
        );

        $refused = $checker->check(MoveQuery::require('acme/lib', '^2.0', '2.0.0'));
        $free = self::checker(['acme/lib' => '^1.0'], [['name' => 'acme/lib', 'version' => '1.2.0']], ['acme/lib' => [['version' => '1.2.0'], ['version' => '2.0.0']]])
            ->check(MoveQuery::require('acme/lib', '^2.0', '2.0.0'));

        self::assertSame(MoveCheck::REFUSED, $refused->check()->result());
        self::assertContains(['source' => 'package', 'package' => 'acme/user', 'version' => '1.0.0', 'link' => 'require', 'target' => null, 'constraint' => '^1.0'], self::rows($refused->holds()));
        self::assertSame([MoveCheck::RESOLVES, MoveCheck::INLINE, '2.0.0'], [$free->check()->result(), $free->check()->form(), $free->installs()]);
    }

    /** A raise-php move writes the php requirement first: the target php runs the release. */
    public function testARaisePhpMoveSolvesWithTheRaisedRootPhpRequirement(): void
    {
        $checker = self::checker(
            ['php' => '>=7.4', 'acme/lib' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['php' => '>=7.4']]],
            ['acme/lib' => [['version' => '1.0.0', 'require' => ['php' => '>=7.4']], ['version' => '2.0.0', 'require' => ['php' => '>=8.1']]]]
        );

        $outcome = $checker->check(MoveQuery::require('acme/lib', '^2.0', '2.0.0', '>=8.1'));

        self::assertSame([MoveCheck::RESOLVES, MoveCheck::INLINE, '2.0.0'], [$outcome->check()->result(), $outcome->check()->form(), $outcome->installs()]);
    }

    public function testAnExtensionRequirementOfTheTargetDoesNotHoldTheMove(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0']],
            ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['ext-lockrot-test-missing' => '*']]]]
        );

        self::assertSame(MoveCheck::RESOLVES, $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'))->check()->result());
    }

    /** D5: when the lock itself does not install on the target, no move is checked. */
    public function testARefusedBaselineChecksNoMoveAndKeepsComposersProblemText(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['php' => '~8.1.0 || ~8.2.0']]],
            ['acme/lib' => [['version' => '1.0.0', 'require' => ['php' => '~8.1.0 || ~8.2.0']], ['version' => '1.1.0']]]
        );

        $baseline = $checker->baseline();
        $move = $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'));

        self::assertSame(MoveCheck::REFUSED, $baseline->check()->result());
        self::assertStringContainsString('acme/lib', (string) $baseline->problem());
        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => 'baseline_refused'], $move->check()->jsonSerialize());
        self::assertSame([MoveCheck::NOT_CHECKED, 'baseline_refused'], [$checker->combined(['acme/lib' => '^1.1.0'])->check()->result(), $checker->combined(['acme/lib' => '^1.1.0'])->check()->reason()]);
    }

    /**
     * D6: a refusal that needs a name the source could not load says nothing about the project.
     *
     * @dataProvider unloadedNames
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unloadedNames')]
    public function testARefusalThatNeedsANameTheSourceCouldNotLoadIsNotChecked(string $failure, string $reason): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0']],
            ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/new-dependency' => '^1.0']]]],
            ['acme/new-dependency' => $failure]
        );

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => $reason], $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'))->check()->jsonSerialize());
    }

    /** @return iterable<string, array{string, string}> */
    public static function unloadedNames(): iterable
    {
        yield 'the repository did not answer' => [VersionSource::FAILED, MoveCheck::UNLOADED_NAME];
        yield 'offline, and the cache has no answer' => [VersionSource::OFFLINE, MoveCheck::OFFLINE];
        yield 'the install-time budget ran out' => [VersionSource::BUDGET, MoveCheck::INSTALL_TIME_BUDGET];
    }

    public function testANewDependencyThatTheSourceLoadsResolves(): void
    {
        $source = new ArrayVersionSource([
            'acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/new-dependency' => '^1.0']]],
            'acme/new-dependency' => [['version' => '1.0.0']],
        ]);
        $checker = self::checkerWith(['acme/lib' => '^1.0'], [['name' => 'acme/lib', 'version' => '1.0.0']], $source);

        $outcome = $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'));

        self::assertSame([MoveCheck::RESOLVES, '1.1.0'], [$outcome->check()->result(), $outcome->installs()]);
        self::assertContains('acme/new-dependency', $source->asked());
    }

    /** A path or VCS package keeps only its locked version: no Composer repository serves another. */
    public function testAMoveOfAPackageThatNoComposerRepositoryServesIsNotChecked(): void
    {
        $solver = new ComposerSolver(self::root(['acme/own' => '^1.0']), LockFile::fromArray(['packages' => [['name' => 'acme/own', 'version' => '1.0.0']]])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), new ArrayVersionSource([]), []);
        $checker = new SolverChecker($solver, ['acme/own'], (new VersionParser())->normalize('8.4.99'), false, Deadline::never());

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => 'not_from_composer_repository'], $checker->check(MoveQuery::update('acme/own', '^1.1', '1.1.0'))->check()->jsonSerialize());
    }

    public function testAnExpiredDeadlineChecksNothing(): void
    {
        $solver = new ComposerSolver(self::root(['acme/lib' => '^1.0']), LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), new ArrayVersionSource(['acme/lib' => [['version' => '1.1.0']]]), []);
        $checker = new SolverChecker($solver, [], (new VersionParser())->normalize('8.4.99'), false, Deadline::inSeconds(-1.0));

        self::assertSame([MoveCheck::NOT_CHECKED, 'install_time_budget'], [$checker->baseline()->check()->result(), $checker->baseline()->check()->reason()]);
        self::assertSame('install_time_budget', $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'))->check()->reason());
    }

    /** D8: Composer 2.9 and later drop every affected version but the locked one of a name that stays locked. */
    public function testABlockedAdvisoryRangeRefusesAMoveWhoseOnlyTargetItAffects(): void
    {
        $range = (new VersionParser())->parseConstraints('>=1.1.0,<1.2.0');
        $lock = ['packages' => [['name' => 'acme/lib', 'version' => '1.0.0'], ['name' => 'acme/kept', 'version' => '1.1.0']]];
        $source = new ArrayVersionSource(['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0']], 'acme/kept' => [['version' => '1.1.0']]]);
        $blocked = new ComposerSolver(self::root(['acme/lib' => '^1.0', 'acme/kept' => '^1.0']), LockFile::fromArray($lock)->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), $source, ['acme/lib' => [$range], 'acme/kept' => [$range]]);
        $open = $blocked->withBlocked([]);

        self::assertFalse($blocked->solve(\Lockrot\Solver\SolveRequest::update(['acme/lib' => '^1.1.0']))->resolves());
        self::assertTrue($open->solve(\Lockrot\Solver\SolveRequest::update(['acme/lib' => '^1.1.0']))->resolves());
        self::assertTrue($blocked->solve(\Lockrot\Solver\SolveRequest::baseline())->resolves(), 'the locked 1.1.0 of a name that stays locked is kept');
    }

    public function testTheCombinedUpdateOfTwoMovesThatExcludeEachOtherIsRefused(): void
    {
        $checker = self::checker(
            ['acme/a' => '^1.0', 'acme/b' => '^1.0'],
            [['name' => 'acme/a', 'version' => '1.0.0'], ['name' => 'acme/b', 'version' => '1.0.0']],
            ['acme/a' => [['version' => '1.0.0'], ['version' => '1.1.0', 'conflict' => ['acme/b' => '>=1.1']]], 'acme/b' => [['version' => '1.0.0'], ['version' => '1.1.0']]]
        );

        self::assertSame(MoveCheck::RESOLVES, $checker->check(MoveQuery::update('acme/a', '^1.1.0', '1.1.0'))->check()->result());
        self::assertSame(MoveCheck::RESOLVES, $checker->check(MoveQuery::update('acme/b', '^1.1.0', '1.1.0'))->check()->result());
        $combined = $checker->combined(['acme/a' => '^1.1.0', 'acme/b' => '^1.1.0']);
        self::assertSame(MoveCheck::REFUSED, $combined->check()->result());
        self::assertContains(['source' => 'package', 'package' => 'acme/a', 'version' => '1.1.0', 'link' => 'conflict', 'target' => null, 'constraint' => '>=1.1'], self::rows($combined->holds()));
    }

    /**
     * @param array<string, string>                     $requires
     * @param list<array<string, mixed>>                $locked
     * @param array<string, list<array<string, mixed>>> $remote
     * @param array<string, string>                     $failures
     */
    private static function checker(array $requires, array $locked, array $remote, array $failures = []): SolverChecker
    {
        return self::checkerWith($requires, $locked, new ArrayVersionSource($remote, $failures));
    }

    /**
     * @param array<string, string>      $requires
     * @param list<array<string, mixed>> $locked
     */
    private static function checkerWith(array $requires, array $locked, VersionSource $source): SolverChecker
    {
        $solver = new ComposerSolver(self::root($requires), LockFile::fromArray(['packages' => $locked])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), $source, []);

        return new SolverChecker($solver, [], (new VersionParser())->normalize('8.4.99'), false, Deadline::never());
    }

    /** @param array<string, string> $requires */
    private static function root(array $requires): RootPackageInterface
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => $requires], RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $root);

        return $root;
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
