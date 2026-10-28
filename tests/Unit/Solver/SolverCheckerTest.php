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
use Composer\Util\Platform;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Security\Holder;
use Lockrot\Solver\AdvisoryBlocking;
use Lockrot\Solver\ComposerSolver;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveChecker;
use Lockrot\Solver\MoveQuery;
use Lockrot\Solver\PoolAdvisories;
use Lockrot\Solver\SolverChecker;
use Lockrot\Solver\VersionSource;
use Lockrot\Tests\Support\ArrayVersionSource;
use Lockrot\Verdict\MoveChecks;
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

        self::assertSame(['result' => 'refused', 'form' => null, 'reason' => null, 'installs' => null], $outcome->check()->jsonSerialize());
        self::assertContains(['source' => 'package', 'package' => 'symfony/yaml', 'version' => 'v5.4.54', 'link' => 'conflict', 'target' => 'symfony/console', 'constraint' => '<5.3'], self::rows($outcome->holds()));
        self::assertContains(['source' => 'root', 'package' => null, 'version' => null, 'link' => 'root-require', 'target' => 'symfony/console', 'constraint' => '^4.4'], self::rows($outcome->holds()));
        self::assertNull($outcome->problem(), "a move publishes its holds, not Composer's problem text");
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

    /** monica's laravel/framework: the root's `^12.0` admits v12.69.0, so the run's move checks keep only the release's own requirement. */
    public function testARootRequirementThatAdmitsTheMovesReleaseIsNoHold(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0']],
            ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/gone' => '^1.0']]]]
        );

        $outcome = (MoveChecks::of($checker))->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'));

        self::assertSame(MoveCheck::REFUSED, $outcome->check()->result());
        self::assertSame([['source' => 'package', 'package' => 'acme/lib', 'version' => '1.1.0', 'link' => 'require', 'target' => 'acme/gone', 'constraint' => '^1.0']], self::rows($outcome->holds()));
    }

    /**
     * The update solve takes the root requirement with its inline constraint, so a refused
     * `update acme/lib:^1.2.5` under `~1.2.0` says nothing about 1.3.0, which only a require reaches.
     */
    public function testARefusedUpdateHoldsOnlyTheReleasesThatTheRootRequirementAlsoAdmits(): void
    {
        $checker = self::checker(
            ['acme/lib' => '~1.2.0'],
            [['name' => 'acme/lib', 'version' => '1.2.3']],
            ['acme/lib' => [['version' => '1.2.3'], ['version' => '1.2.5', 'require' => ['acme/gone' => '^1.0']], ['version' => '1.3.0']]]
        );
        $checks = MoveChecks::of($checker);

        self::assertSame(MoveCheck::REFUSED, $checks->check(MoveQuery::update('acme/lib', '^1.2.5', '1.2.5'))->check()->result());
        $refused = $checks->refused('acme/lib');

        self::assertSame([true, false], [$refused->refuses('1.2.5.0'), $refused->refuses('1.3.0.0')]);
        self::assertSame([], $refused->holding('1.3.0.0'));
    }

    public function testARefusedUpdateHoldsOnlyTheReleasesThatTheRootDevRequirementAlsoAdmits(): void
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require-dev' => ['acme/lib' => '~1.2.0']], RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $root);
        $locked = LockFile::fromArray(['packages' => [], 'packages-dev' => [['name' => 'acme/lib', 'version' => '1.2.3']]])->lockedRepository();
        $source = new ArrayVersionSource(['acme/lib' => [['version' => '1.2.3'], ['version' => '1.2.5', 'require' => ['acme/gone' => '^1.0']], ['version' => '1.3.0']]]);
        $checks = MoveChecks::of(new SolverChecker(new ComposerSolver($root, $locked, new PlatformRepository([], ['php' => '8.4.99']), $source, []), [], '8.4.99', false, Deadline::never()));

        self::assertSame(MoveCheck::REFUSED, $checks->check(MoveQuery::update('acme/lib', '^1.2.5', '1.2.5'))->check()->result());

        self::assertSame([true, false], [$checks->refused('acme/lib')->refuses('1.2.5.0'), $checks->refused('acme/lib')->refuses('1.3.0.0')]);
    }

    public function testTheCheckerNamesTheVariableThatTurnedTheAdvisoryBlockingOff(): void
    {
        $solver = new ComposerSolver(self::root([]), LockFile::fromArray(['packages' => []])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), new ArrayVersionSource([]), []);
        Platform::putEnv('COMPOSER_NO_SECURITY_BLOCKING', '1');
        try {
            $advisories = new PoolAdvisories(AdvisoryBlocking::fromConfig(new Config(false)), static fn (array $names, bool $complete): array => []);
        } finally {
            Platform::clearEnv('COMPOSER_NO_SECURITY_BLOCKING');
        }

        self::assertSame(
            [['COMPOSER_NO_SECURITY_BLOCKING'], []],
            [(new SolverChecker($solver, [], '8.4.99', false, Deadline::never(), $advisories))->blockingDisabledBy(), (new SolverChecker($solver, [], '8.4.99', false, Deadline::never()))->blockingDisabledBy()]
        );
    }

    public function testARefusedRequireHoldsEveryReleaseOfItsConstraint(): void
    {
        $checker = self::checker(
            ['acme/lib' => '~1.2.0'],
            [['name' => 'acme/lib', 'version' => '1.2.3']],
            ['acme/lib' => [['version' => '1.2.3'], ['version' => '1.3.0', 'require' => ['acme/gone' => '^1.0']], ['version' => '1.4.0', 'require' => ['acme/gone' => '^1.0']]]]
        );
        $checks = MoveChecks::of($checker);

        self::assertSame(MoveCheck::REFUSED, $checks->check(MoveQuery::require('acme/lib', '^1.3.0', '1.3.0'))->check()->result());

        self::assertSame([true, true], [$checks->refused('acme/lib')->refuses('1.3.0.0'), $checks->refused('acme/lib')->refuses('1.4.0.0')]);
    }

    /** The pool serves no release of the move: the root requirement is the one hold that Composer names, so it stays. */
    public function testARootRequirementThatAdmitsTheReleaseStaysWhenItIsTheOnlyHold(): void
    {
        $checker = self::checker(['acme/lib' => '^1.0'], [['name' => 'acme/lib', 'version' => '1.0.0']], ['acme/lib' => [['version' => '1.0.0']]]);

        $outcome = (MoveChecks::of($checker))->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'));

        self::assertSame([['source' => 'root', 'package' => null, 'version' => null, 'link' => 'require', 'target' => null, 'constraint' => '^1.0']], self::rows($outcome->holds()));
    }

    /** A conflict of composer.json on the moved package that matches the release holds it. */
    public function testARootConflictThatMatchesTheMovesReleaseHoldsIt(): void
    {
        $checker = self::checkerWith(['acme/lib' => '^1.0'], [['name' => 'acme/lib', 'version' => '1.0.0']], new ArrayVersionSource(['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0']]]), ['acme/lib' => '>=1.1']);

        $outcome = (MoveChecks::of($checker))->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'));

        self::assertSame(MoveCheck::REFUSED, $outcome->check()->result());
        self::assertContains(['source' => 'root', 'package' => null, 'version' => null, 'link' => 'conflict', 'target' => null, 'constraint' => '>=1.1'], self::rows($outcome->holds()));
    }

    /** No release of the move's own constraint is in the pool: that root hold stays, though its constraint admits the release. */
    public function testAMoveToAReleaseThatThePoolLacksKeepsItsOwnConstraintAsTheHold(): void
    {
        $checker = self::checker(['acme/lib' => '^1.0'], [['name' => 'acme/lib', 'version' => '1.0.0']], ['acme/lib' => [['version' => '1.0.0']]]);

        $outcome = (MoveChecks::of($checker))->check(MoveQuery::require('acme/lib', '^2.0', '2.0.0'));

        self::assertSame(MoveCheck::REFUSED, $outcome->check()->result());
        self::assertSame([['source' => 'root', 'package' => null, 'version' => null, 'link' => 'root-require', 'target' => null, 'constraint' => '^2.0']], self::rows($outcome->holds()));
    }

    /** A locked package's requirement on the moved package is a package hold, whatever release it admits. */
    public function testAPackageRequirementOnTheMovedPackageStaysAHold(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0', 'acme/user' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0'], ['name' => 'acme/user', 'version' => '1.0.0', 'require' => ['acme/lib' => '^1.0']]],
            ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/gone' => '^1.0']]]]
        );

        $outcome = $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'));

        self::assertContains(['source' => 'package', 'package' => 'acme/user', 'version' => '1.0.0', 'link' => 'require', 'target' => null, 'constraint' => '^1.0'], self::rows($outcome->holds()));
    }

    /** A root requirement that excludes the release still holds it. */
    public function testARootRequirementThatExcludesTheMovesReleaseHoldsIt(): void
    {
        $checker = self::checker(
            ['acme/lib' => '~1.0.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0']],
            ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0']]]
        );

        $outcome = (MoveChecks::of($checker))->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'));

        self::assertSame(MoveCheck::REFUSED, $outcome->check()->result());
        self::assertContains(['source' => 'root', 'package' => null, 'version' => null, 'link' => 'require', 'target' => null, 'constraint' => '~1.0.0'], self::rows($outcome->holds()));
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

        self::assertSame(['result' => 'resolves', 'form' => 'with-dependencies', 'reason' => null, 'installs' => '7.15.2'], $outcome->check()->jsonSerialize());
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
        self::assertSame(['result' => 'resolves', 'form' => 'with-all-dependencies', 'reason' => null, 'installs' => '11.4.10'], $checker->check(MoveQuery::require('drupal/core-recommended', '^11.4.10', '11.4.10'))->check()->jsonSerialize());
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
        self::assertNotContains('^2.0', array_column(self::rows($refused->holds()), 'constraint'), 'the root requirement that the request writes holds nothing');
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
        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => 'baseline_refused', 'installs' => null], $move->check()->jsonSerialize());
        self::assertSame([MoveCheck::NOT_CHECKED, 'baseline_refused'], [$checker->combined(['acme/lib' => '^1.1.0'])->check()->result(), $checker->combined(['acme/lib' => '^1.1.0'])->check()->reason()]);
    }

    /**
     * R2-4: composer.json requires acme/new, which the lock lacks and the source could not load. The
     * baseline's refusal says nothing about the lock, so it reads the load reason.
     *
     * @dataProvider unloadedNames
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unloadedNames')]
    public function testABaselineThatNeedsARootRequirementTheSourceCouldNotLoadIsNotChecked(string $failure, string $reason): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0', 'acme/new' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0']],
            ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0']]],
            ['acme/new' => $failure]
        );

        $baseline = $checker->baseline()->check();
        $move = $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'))->check();

        self::assertSame([MoveCheck::NOT_CHECKED, $reason], [$baseline->result(), $baseline->reason()]);
        self::assertSame([MoveCheck::NOT_CHECKED, $reason], [$move->result(), $move->reason()]);
    }

    /** A repository answered that it lists no acme/new: Composer refuses the lock itself. */
    public function testABaselineThatNeedsARootRequirementThatNoRepositoryListsIsRefused(): void
    {
        $checker = self::checker(['acme/lib' => '^1.0', 'acme/new' => '^1.0'], [['name' => 'acme/lib', 'version' => '1.0.0']], ['acme/lib' => [['version' => '1.0.0']]]);

        self::assertSame(MoveCheck::REFUSED, $checker->baseline()->check()->result());
    }

    /** A source can read a platform name as not loaded, offline for one. No repository serves a platform name. */
    public function testABaselineThatTheRootsPhpRequirementRefusesIsRefusedOffline(): void
    {
        $checker = self::checker(['acme/lib' => '^1.0', 'php' => '<7.0'], [['name' => 'acme/lib', 'version' => '1.0.0']], [], ['php' => VersionSource::OFFLINE]);

        self::assertSame(MoveCheck::REFUSED, $checker->baseline()->check()->result());
    }

    /** Of two names that did not load, the reason of the run state wins: offline before a failed repository. */
    public function testASolveWithTwoNamesThatDidNotLoadReadsTheOfflineReason(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0']],
            ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0'], ['version' => '1.2.0', 'require' => ['acme/first' => '^1.0', 'acme/second' => '^1.0']]]],
            ['acme/first' => VersionSource::FAILED, 'acme/second' => VersionSource::OFFLINE]
        );

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => MoveCheck::OFFLINE, 'installs' => null], $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0', null, '1.2.0'))->check()->jsonSerialize());
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

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => $reason, 'installs' => null], $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'))->check()->jsonSerialize());
    }

    /**
     * Ruling R2-3: 1.2.0 needs a name that did not load, so the solve resolves at 1.1.0 on a pool that
     * lacks it. That solve proves nothing.
     *
     * @dataProvider unloadedNames
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unloadedNames')]
    public function testASolveThatResolvesWithoutANameTheSourceCouldNotLoadIsNotChecked(string $failure, string $reason): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0']],
            ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0'], ['version' => '1.2.0', 'require' => ['acme/new-dependency' => '^1.0']]]],
            ['acme/new-dependency' => $failure]
        );

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => $reason, 'installs' => null], $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0', null, '1.2.0'))->check()->jsonSerialize());
    }

    /**
     * Offline, a virtual name such as psr/log-implementation reads as not loaded, because no
     * repository lists it. A solve that installs the newest release proves the move all the same.
     */
    public function testASolveThatInstallsTheNewestReleaseResolvesWithANameTheSourceCouldNotLoad(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0', 'acme/impl' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['psr/log-implementation' => '^1.0']], ['name' => 'acme/impl', 'version' => '1.0.0', 'provide' => ['psr/log-implementation' => '1.0.0']]],
            ['acme/lib' => [['version' => '1.0.0', 'require' => ['psr/log-implementation' => '^1.0']], ['version' => '1.1.0', 'require' => ['psr/log-implementation' => '^1.0']]], 'acme/impl' => [['version' => '1.0.0', 'provide' => ['psr/log-implementation' => '1.0.0']]]],
            ['psr/log-implementation' => VersionSource::OFFLINE]
        );

        self::assertSame(['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'installs' => '1.1.0'], $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0', null, '1.1.0'))->check()->jsonSerialize());
    }

    /**
     * The inline form resolves below the newest release on a pool that lacks a virtual name, which
     * proves nothing. `-w` frees acme/dep and installs the newest release, which proves the move.
     */
    public function testAFormThatALoadStoppedBelowTheNewestReleaseGivesWayToAFormThatInstallsIt(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0', 'acme/impl' => '^1.0'],
            [
                ['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/dep' => '^1.0', 'psr/log-implementation' => '^1.0']],
                ['name' => 'acme/dep', 'version' => '1.0.0'],
                ['name' => 'acme/impl', 'version' => '1.0.0', 'provide' => ['psr/log-implementation' => '1.0.0']],
            ],
            [
                'acme/lib' => [
                    ['version' => '1.0.0', 'require' => ['acme/dep' => '^1.0', 'psr/log-implementation' => '^1.0']],
                    ['version' => '1.1.0', 'require' => ['acme/dep' => '^1.0', 'psr/log-implementation' => '^1.0']],
                    ['version' => '1.2.0', 'require' => ['acme/dep' => '^1.1', 'psr/log-implementation' => '^1.0']],
                ],
                'acme/dep' => [['version' => '1.0.0'], ['version' => '1.1.0']],
                'acme/impl' => [['version' => '1.0.0', 'provide' => ['psr/log-implementation' => '1.0.0']]],
            ],
            ['psr/log-implementation' => VersionSource::OFFLINE]
        );

        self::assertSame(['result' => 'resolves', 'form' => 'with-dependencies', 'reason' => null, 'installs' => '1.2.0'], $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0', null, '1.2.0'))->check()->jsonSerialize());
    }

    /**
     * The inline form installs 1.1.0 with every name loaded. `-w` frees acme/dep, whose 1.1.0 needs
     * an offline name. `-W` also frees acme/other and installs the newest 1.2.0, which proves the move.
     */
    public function testAFormThatALoadStoppedAfterALowerFormResolvedGivesWayToAFormThatInstallsTheNewestRelease(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0', 'acme/other' => '^1.0 || ^2.0'],
            [
                ['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/dep' => '^1.0']],
                ['name' => 'acme/dep', 'version' => '1.0.0', 'require' => ['acme/other' => '^1.0']],
                ['name' => 'acme/other', 'version' => '1.0.0'],
            ],
            [
                'acme/lib' => [['version' => '1.0.0', 'require' => ['acme/dep' => '^1.0']], ['version' => '1.1.0', 'require' => ['acme/dep' => '^1.0']], ['version' => '1.2.0', 'require' => ['acme/dep' => '^1.1']]],
                'acme/dep' => [['version' => '1.0.0', 'require' => ['acme/other' => '^1.0']], ['version' => '1.1.0', 'require' => ['acme/off' => '^1.0']], ['version' => '1.2.0', 'require' => ['acme/other' => '^2.0']]],
                'acme/other' => [['version' => '1.0.0'], ['version' => '2.0.0']],
            ],
            ['acme/off' => VersionSource::OFFLINE]
        );

        self::assertSame(['result' => 'resolves', 'form' => 'with-all-dependencies', 'reason' => null, 'installs' => '1.2.0'], $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0', null, '1.2.0'))->check()->jsonSerialize());
    }

    /** The inline form refuses with every name loaded. `-w` frees acme/dep, whose 1.1.0 needs an offline name, so the refusal proves nothing. */
    public function testAFormThatALoadStoppedWinsOverAnEarlierRefusal(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/dep' => '^1.0']], ['name' => 'acme/dep', 'version' => '1.0.0']],
            [
                'acme/lib' => [['version' => '1.0.0', 'require' => ['acme/dep' => '^1.0']], ['version' => '1.1.0', 'require' => ['acme/dep' => '^1.1']]],
                'acme/dep' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/off' => '^1.0']]],
            ],
            ['acme/off' => VersionSource::OFFLINE]
        );

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => 'offline', 'installs' => null], $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0', null, '1.1.0'))->check()->jsonSerialize());
    }

    /** The inline form needs acme/gone, which failed to load. `-w` also needs an offline name, which comes first in the load reasons: the first stopped form names the reason. */
    public function testTheFirstFormThatALoadStoppedNamesTheReason(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/dep' => '^1.0']], ['name' => 'acme/dep', 'version' => '1.0.0']],
            [
                'acme/lib' => [['version' => '1.0.0', 'require' => ['acme/dep' => '^1.0']], ['version' => '1.1.0', 'require' => ['acme/gone' => '^1.0']], ['version' => '1.2.0', 'require' => ['acme/dep' => '^1.1']]],
                'acme/dep' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/off' => '^1.0']]],
            ],
            ['acme/gone' => VersionSource::FAILED, 'acme/off' => VersionSource::OFFLINE]
        );

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => 'unloaded_name', 'installs' => null], $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0', null, '1.2.0'))->check()->jsonSerialize());
    }

    /** The inline form needs an offline name. Composer fails on `-w`, which frees acme/dep: the move keeps the load reason. */
    public function testAFormThatALoadStoppedKeepsItsReasonWhenComposerFailsOnALaterForm(): void
    {
        $source = new ArrayVersionSource(
            [
                'acme/lib' => [['version' => '1.0.0', 'require' => ['acme/dep' => '^1.0']], ['version' => '1.1.0', 'require' => ['acme/off' => '^1.0']], ['version' => '1.2.0', 'require' => ['acme/dep' => '^1.1']]],
                'acme/dep' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/bad' => '^1.0']]],
            ],
            ['acme/off' => VersionSource::OFFLINE],
            ['acme/bad' => new \UnexpectedValueException('Could not parse version constraint')]
        );
        $checker = self::checkerWith(['acme/lib' => '^1.0'], [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/dep' => '^1.0']], ['name' => 'acme/dep', 'version' => '1.0.0']], $source);

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => 'offline', 'installs' => null], $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0', null, '1.2.0'))->check()->jsonSerialize());
        self::assertSame(\UnexpectedValueException::class, ($checker->solverError() ?? [])['class'] ?? null);
    }

    /** The budget runs out after the baseline resolves and before the move's first form: every form reads the budget. */
    public function testAMoveThatTheBudgetStopsBeforeItsFirstFormIsNotChecked(): void
    {
        // The deadline starts at the first reading, and the clock is inside the budget only when the baseline asks it.
        $readings = [0.0, 0.0];
        $now = static function () use (&$readings): float {
            return array_shift($readings) ?? 100.0;
        };
        $solver = new ComposerSolver(self::root(['acme/lib' => '^1.0']), LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), new ArrayVersionSource(['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0']]]), []);
        $checker = new SolverChecker($solver, [], '8.4.99', false, Deadline::inSeconds(10.0, $now));

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => 'install_time_budget', 'installs' => null], $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0', null, '1.1.0'))->check()->jsonSerialize());
    }

    /** A move with no newest release has no release that proves it, so a solve on a pool that lacks a name stays not checked. */
    public function testAMoveWithNoNewestReleaseThatResolvesWithoutANameTheSourceCouldNotLoadIsNotChecked(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0']],
            ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0'], ['version' => '1.2.0', 'require' => ['acme/new-dependency' => '^1.0']]]],
            ['acme/new-dependency' => VersionSource::OFFLINE]
        );

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => 'offline', 'installs' => null], $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'))->check()->jsonSerialize());
    }

    /** No form installs the newest release, and the load stopped the inline form: the move stays not checked. */
    public function testAFormThatALoadStoppedStaysWhenNoLaterFormResolvesWithoutTheLoad(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0', 'acme/impl' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['psr/log-implementation' => '^1.0']], ['name' => 'acme/impl', 'version' => '1.0.0', 'provide' => ['psr/log-implementation' => '1.0.0']]],
            ['acme/lib' => [['version' => '1.0.0', 'require' => ['psr/log-implementation' => '^1.0']], ['version' => '1.1.0', 'require' => ['psr/log-implementation' => '^1.0']], ['version' => '1.2.0', 'require' => ['acme/missing' => '^1.0']]], 'acme/impl' => [['version' => '1.0.0', 'provide' => ['psr/log-implementation' => '1.0.0']]]],
            ['psr/log-implementation' => VersionSource::OFFLINE]
        );

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => 'offline', 'installs' => null], $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0', null, '1.2.0'))->check()->jsonSerialize());
    }

    /**
     * D6: the moved package's own versions did not load, so the solve has no target to refuse.
     *
     * @dataProvider unloadedNames
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unloadedNames')]
    public function testAMoveWhosePackageTheSourceCouldNotLoadIsNotChecked(string $failure, string $reason): void
    {
        $checker = self::checker(['acme/lib' => '^1.0'], [['name' => 'acme/lib', 'version' => '1.0.0']], [], ['acme/lib' => $failure]);

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => $reason, 'installs' => null], $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'))->check()->jsonSerialize());
    }

    /** wallabag 2.6.14 at 8.4: the root requires guzzle5-adapter ^2.0, and its one 2.x release requires php ^7.0. */
    public function testAnUnlockThatNoReleaseAdmitsIsRefusedAndTheRootRequirementKeepsItsTarget(): void
    {
        $checker = self::checker(
            ['acme/adapter' => '^2.0'],
            [['name' => 'acme/adapter', 'version' => '2.0.0', 'require' => ['php' => '^7.0']]],
            ['acme/adapter' => [['version' => '2.0.0', 'require' => ['php' => '^7.0']]]]
        );

        $outcome = $checker->solveAt(['acme/adapter']);

        self::assertSame(['result' => 'refused', 'form' => null, 'reason' => null, 'installs' => null], $outcome->check()->jsonSerialize());
        self::assertContains(['source' => 'root', 'package' => null, 'version' => null, 'link' => 'root-require', 'target' => 'acme/adapter', 'constraint' => '^2.0'], self::rows($outcome->holds()));
        self::assertContains(['source' => 'package', 'package' => 'acme/adapter', 'version' => '2.0.0', 'link' => 'platform', 'target' => 'php', 'constraint' => '^7.0'], self::rows($outcome->holds()));
    }

    public function testAnUnlockWhoseNewerReleaseAdmitsTheSolvesPhpResolvesInlineAlthoughTheBaselineIsRefused(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['php' => '<8.4']]],
            ['acme/lib' => [['version' => '1.0.0', 'require' => ['php' => '<8.4']], ['version' => '1.1.0', 'require' => ['php' => '>=8.1']]]]
        );

        $outcome = $checker->solveAt(['acme/lib']);

        self::assertSame(MoveCheck::REFUSED, $checker->baseline()->check()->result());
        self::assertSame(['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'installs' => null], $outcome->check()->jsonSerialize());
    }

    /** `composer update --lock` moves no package, so a newer release that admits the solve's php does not resolve it. */
    public function testAnUnlockOfNoNameMovesNoPackage(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['php' => '<8.4']]],
            ['acme/lib' => [['version' => '1.0.0', 'require' => ['php' => '<8.4']], ['version' => '1.1.0', 'require' => ['php' => '>=8.1']]]]
        );

        $outcome = $checker->solveAt([]);

        self::assertSame(['result' => 'refused', 'form' => null, 'reason' => null, 'installs' => null], $outcome->check()->jsonSerialize());
    }

    /** monica at 8.5: the newer release needs a dependency that a root requirement keeps, so only `-W` moves it. */
    public function testAnUnlockWhoseNewerReleaseNeedsARootRequirementMovedResolvesOnlyWithAllDependencies(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0', 'acme/dep' => '^1.0 || ^2.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['php' => '<8.4', 'acme/dep' => '^1.0']], ['name' => 'acme/dep', 'version' => '1.0.0']],
            [
                'acme/lib' => [['version' => '1.0.0', 'require' => ['php' => '<8.4', 'acme/dep' => '^1.0']], ['version' => '1.1.0', 'require' => ['acme/dep' => '^2.0']]],
                'acme/dep' => [['version' => '1.0.0'], ['version' => '2.0.0']],
            ]
        );

        self::assertSame(['result' => 'resolves', 'form' => 'with-all-dependencies', 'reason' => null, 'installs' => null], $checker->solveAt(['acme/lib'])->check()->jsonSerialize());
    }

    public function testAnUnlockTakesTheReasonOfABaselineThatWasNotChecked(): void
    {
        $solver = new ComposerSolver(self::root(['acme/lib' => '^1.0']), LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), new ArrayVersionSource(['acme/lib' => [['version' => '1.1.0']]]), []);
        $checker = new SolverChecker($solver, [], '8.4.99', false, Deadline::inSeconds(-1.0));

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => 'install_time_budget', 'installs' => null], $checker->solveAt(['acme/lib'])->check()->jsonSerialize());
    }

    public function testAnUnlockOfAPackageThatNoComposerRepositoryServesIsNotChecked(): void
    {
        $solver = new ComposerSolver(self::root(['acme/own' => '^1.0']), LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0'], ['name' => 'acme/own', 'version' => '1.0.0']]])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), new ArrayVersionSource([]), []);
        $checker = new SolverChecker($solver, ['acme/own'], '8.4.99', false, Deadline::never());

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => 'not_from_composer_repository', 'installs' => null], $checker->solveAt(['acme/lib', 'acme/own'])->check()->jsonSerialize());
        self::assertNotSame('not_from_composer_repository', $checker->solveAt(['acme/lib'])->check()->reason(), 'the other names solve');
    }

    /** The unlock takes the reason of a baseline that was not checked, although its own solve could run. */
    public function testAnUnlockTakesTheReasonOfTheBaselineOverItsOwnSolve(): void
    {
        // The clock is past the budget only when the baseline asks it.
        $readings = [0.0, 100.0];
        $now = static function () use (&$readings): float {
            return array_shift($readings) ?? 0.0;
        };
        $solver = new ComposerSolver(self::root(['acme/lib' => '^1.0']), LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), new ArrayVersionSource(['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0']]]), []);
        $checker = new SolverChecker($solver, [], '8.4.99', false, Deadline::inSeconds(10.0, $now));

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => 'install_time_budget', 'installs' => null], $checker->solveAt(['acme/lib'])->check()->jsonSerialize());
    }

    /**
     * @dataProvider unloadedNames
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unloadedNames')]
    public function testAnUnlockOfAPackageTheSourceCouldNotLoadIsNotChecked(string $failure, string $reason): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0', 'acme/other' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['php' => '<8.4']], ['name' => 'acme/other', 'version' => '1.0.0', 'require' => ['php' => '<8.4']]],
            ['acme/lib' => [['version' => '1.0.0', 'require' => ['php' => '<8.4']], ['version' => '1.1.0']]],
            ['acme/other' => $failure]
        );

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => $reason, 'installs' => null], $checker->solveAt(['acme/lib', 'acme/other'])->check()->jsonSerialize());
    }

    /** @return iterable<string, array{string, string}> */
    public static function unloadedNames(): iterable
    {
        yield 'the repository did not answer' => [VersionSource::FAILED, MoveCheck::UNLOADED_NAME];
        yield 'offline, and the cache has no answer' => [VersionSource::OFFLINE, MoveCheck::OFFLINE];
        yield 'the install-time budget ran out' => [VersionSource::BUDGET, MoveCheck::INSTALL_TIME_BUDGET];
    }

    /** R4-1: a release needs a name that only a path, VCS or package repository serves, and Composer can install it. */
    public function testARefusalThatWantsANameNoComposerRepositoryListsIsNotChecked(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0']],
            ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/private' => '^1.0']]]],
            ['acme/private' => VersionSource::UNREACHED]
        );

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => MoveCheck::UNLOADED_NAME, 'installs' => null], $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'))->check()->jsonSerialize());
    }

    /**
     * R5-4: the listed form stops on acme/private, which no Composer repository lists. `-w` unlocks
     * acme/mid, whose only release a blocked advisory covers. Composer's refusal of the wider forms
     * still explains acme/lib 1.1.0 by acme/private, so the move reads not_checked, never refused.
     */
    public function testARefusalOfAWiderFormStillWantsTheNameThatStoppedTheListedForm(): void
    {
        $locked = [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/mid' => '^1.0']], ['name' => 'acme/other', 'version' => '1.0.0', 'require' => ['acme/mid' => '^1.0']], ['name' => 'acme/mid', 'version' => '1.0.0']];
        $source = new ArrayVersionSource([
            'acme/lib' => [['version' => '1.0.0', 'require' => ['acme/mid' => '^1.0']], ['version' => '1.1.0', 'require' => ['acme/private' => '^1.0', 'acme/mid' => '^1.0']]],
            'acme/other' => [['version' => '1.0.0', 'require' => ['acme/mid' => '^1.0']]],
            'acme/mid' => [['version' => '1.0.0']],
        ], ['acme/private' => VersionSource::UNREACHED]);
        $solver = new ComposerSolver(self::root(['acme/lib' => '^1.0', 'acme/other' => '^1.0']), LockFile::fromArray(['packages' => $locked])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), $source, ['acme/mid' => [(new VersionParser())->parseConstraints('<1.1')]]);
        $checker = new SolverChecker($solver, [], '8.4.99', false, Deadline::never());

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => MoveCheck::UNLOADED_NAME, 'installs' => null], $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'))->check()->jsonSerialize());
    }

    /** acme/virtual is unreached, and acme/prov provides it: the refusal on acme/mid needs no unreached name, so it proves the refusal. */
    public function testARefusalThatDoesNotWantAnUnreachedNameThatItAskedForIsRefused(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0'], ['name' => 'acme/prov', 'version' => '1.0.0', 'provide' => ['acme/virtual' => '1.0.0']], ['name' => 'acme/mid', 'version' => '1.0.0']],
            ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/virtual' => '^1.0', 'acme/mid' => '^2.0']]], 'acme/prov' => [['version' => '1.0.0', 'provide' => ['acme/virtual' => '1.0.0']]], 'acme/mid' => [['version' => '1.0.0']]],
            ['acme/virtual' => VersionSource::UNREACHED]
        );

        self::assertSame('refused', $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'))->check()->jsonSerialize()['result']);
    }

    /**
     * The listed form stops on the shadowed acme/zeta, and the wider forms refuse on acme/mid. A
     * shadowed name has loaded versions, so the refusal does not want it, yet its hidden releases
     * can resolve the listed form: the move reads not_checked, never refused.
     */
    public function testARefusalOfAWiderFormAfterAShadowedNameIsNotChecked(): void
    {
        $locked = [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/mid' => '^1.0']], ['name' => 'acme/other', 'version' => '1.0.0', 'require' => ['acme/mid' => '^1.0']], ['name' => 'acme/mid', 'version' => '1.0.0']];
        $source = new ArrayVersionSource([
            'acme/lib' => [['version' => '1.0.0', 'require' => ['acme/mid' => '^1.0']], ['version' => '1.1.0', 'require' => ['acme/zeta' => '^1.0', 'acme/mid' => '^1.0']]],
            'acme/other' => [['version' => '1.0.0', 'require' => ['acme/mid' => '^1.0']]],
            'acme/mid' => [['version' => '1.0.0']],
            'acme/zeta' => [['version' => '1.0.0']],
        ], ['acme/zeta' => VersionSource::SHADOWED]);
        $solver = new ComposerSolver(self::root(['acme/lib' => '^1.0', 'acme/other' => '^1.0']), LockFile::fromArray(['packages' => $locked])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), $source, ['acme/mid' => [(new VersionParser())->parseConstraints('<1.1')]]);
        $checker = new SolverChecker($solver, [], '8.4.99', false, Deadline::never());

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => MoveCheck::UNLOADED_NAME, 'installs' => null], $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'))->check()->jsonSerialize());
    }

    /**
     * R6-1: the listed form stops on the offline acme/virtual. `-w` and `-W` free acme/mid, whose
     * locked 1.0.0 no repository serves, so Composer refuses both cleanly. A refusal never replaces
     * the first stopped form: the move reads not_checked with the stopped form's reason.
     *
     * @dataProvider stoppingFailures
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('stoppingFailures')]
    public function testACleanRefusalOfAWiderFormNeverReplacesTheFormThatALoadStopped(string $failure, string $reason): void
    {
        $checker = self::checkerWith(['acme/lib' => '^1.0', 'acme/other' => '^1.0'], self::stoppedLock(), self::stoppedSource($failure));

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => $reason, 'installs' => null], $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0', null, '1.2.0'))->check()->jsonSerialize());
    }

    /** @return iterable<string, array{string, string}> */
    public static function stoppingFailures(): iterable
    {
        yield 'offline' => [VersionSource::OFFLINE, MoveCheck::OFFLINE];
        yield 'failed' => [VersionSource::FAILED, MoveCheck::UNLOADED_NAME];
    }

    /** R6-1: a target fix walks the same forms, so the stopped listed form wins over the clean refusals of `-w` and `-W`. */
    public function testATargetFixWhoseListedFormALoadStoppedIsNotRefused(): void
    {
        $checker = self::checkerWith(['acme/lib' => '^1.0', 'acme/other' => '^1.0'], self::stoppedLock(), self::stoppedSource(VersionSource::OFFLINE));

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => MoveCheck::OFFLINE, 'installs' => null], $checker->solveAt(['acme/lib'])->check()->jsonSerialize());
    }

    /** R6-1: a stopped move names no hold, so MoveChecks asks no joint request and keeps no refusal of the package. */
    public function testAMoveThatALoadStoppedHasNoHoldsAndNoRefusal(): void
    {
        $checks = MoveChecks::of(self::checkerWith(['acme/lib' => '^1.0', 'acme/other' => '^1.0'], self::stoppedLock(), self::stoppedSource(VersionSource::OFFLINE)));

        $outcome = $checks->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0', null, '1.2.0'));

        self::assertSame([MoveCheck::NOT_CHECKED, [], false], [$outcome->check()->result(), $outcome->holds(), $outcome->isJoint()]);
        self::assertFalse($checks->refused('acme/lib')->refuses('1.1.0.0'));
    }

    /** R6-1: Composer fails on the listed form, and `-w` and `-W` refuse cleanly: the failure is the move's reason. */
    public function testACleanRefusalOfAWiderFormNeverReplacesAFormThatComposerFailedOn(): void
    {
        $source = self::stoppedSource(null);
        $checker = self::checkerWith(['acme/lib' => '^1.0', 'acme/other' => '^1.0'], self::stoppedLock(), $source);
        $checker->baseline();
        $source->throwingOnce('acme/lib', new \UnexpectedValueException('Could not parse version constraint'));

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => MoveCheck::SOLVER_ERROR, 'installs' => null], $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0', null, '1.2.0'))->check()->jsonSerialize());
    }

    /** @return list<array<string, mixed>> acme/other provides acme/virtual, and acme/lib and acme/other require acme/mid ^1.0 */
    private static function stoppedLock(): array
    {
        return [
            ['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/mid' => '^1.0']],
            ['name' => 'acme/mid', 'version' => '1.0.0'],
            ['name' => 'acme/other', 'version' => '1.0.0', 'require' => ['acme/mid' => '^1.0'], 'provide' => ['acme/virtual' => '1.0.0']],
        ];
    }

    /** acme/lib 1.1.0 needs acme/virtual, which fails to load with $failure. acme/lib 1.2.0 needs an unserved acme/other ^2.0. */
    private static function stoppedSource(?string $failure): ArrayVersionSource
    {
        return new ArrayVersionSource([
            'acme/lib' => [
                ['version' => '1.0.0', 'require' => ['acme/mid' => '^1.0']],
                ['version' => '1.1.0', 'require' => ['acme/mid' => '^1.0', 'acme/virtual' => '^1.0']],
                ['version' => '1.2.0', 'require' => ['acme/mid' => '^2.0', 'acme/other' => '^2.0']],
            ],
            'acme/mid' => [['version' => '2.0.0']],
            'acme/other' => [['version' => '1.0.0', 'require' => ['acme/mid' => '^1.0'], 'provide' => ['acme/virtual' => '1.0.0']]],
        ], $failure === null ? [] : ['acme/virtual' => $failure]);
    }

    /**
     * R5-33: a canonical vcs repository before the Composer repository can serve acme/zeta and hide
     * the release that the solve installs, so even a solve that installs the newest proves nothing.
     */
    public function testASolveThatReadsAShadowedNameIsNotChecked(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0']],
            ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/zeta' => '^2.0']]], 'acme/zeta' => [['version' => '2.0.0']]],
            ['acme/zeta' => VersionSource::SHADOWED]
        );

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => MoveCheck::UNLOADED_NAME, 'installs' => null], $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0', null, '1.1.0'))->check()->jsonSerialize());
    }

    public function testACombinedSolveThatReadsAShadowedNameIsNotChecked(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0']],
            ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/zeta' => '^2.0']]], 'acme/zeta' => [['version' => '2.0.0']]],
            ['acme/zeta' => VersionSource::SHADOWED]
        );

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => MoveCheck::UNLOADED_NAME, 'installs' => null], $checker->combined(['acme/lib' => '^1.1.0'])->check()->jsonSerialize());
    }

    /** A virtual name such as `psr/log-implementation` is listed by no repository: a solve that a provider meets proves the move. */
    public function testASolveThatResolvesBelowTheNewestWithANameNoComposerRepositoryListsResolves(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0', 'acme/logger' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0'], ['name' => 'acme/logger', 'version' => '1.0.0', 'provide' => ['psr/log-implementation' => '1.0.0']]],
            ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['psr/log-implementation' => '^1.0']], ['version' => '1.2.0', 'require' => ['php' => '>=9.0']]], 'acme/logger' => [['version' => '1.0.0', 'provide' => ['psr/log-implementation' => '1.0.0']]]],
            ['psr/log-implementation' => VersionSource::UNREACHED]
        );

        self::assertSame(['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'installs' => '1.1.0'], $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.2.0', null, '1.2.0'))->check()->jsonSerialize());
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
        $checker = new SolverChecker($solver, ['acme/own'], '8.4.99', false, Deadline::never());

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => 'not_from_composer_repository', 'installs' => null], $checker->check(MoveQuery::update('acme/own', '^1.1', '1.1.0'))->check()->jsonSerialize());
    }

    public function testAnExpiredDeadlineChecksNothing(): void
    {
        $solver = new ComposerSolver(self::root(['acme/lib' => '^1.0']), LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), new ArrayVersionSource(['acme/lib' => [['version' => '1.1.0']]]), []);
        $checker = new SolverChecker($solver, [], '8.4.99', false, Deadline::inSeconds(-1.0));

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

    /** A root alias of a locked release (`1.2.3 as 1.3.0`) keeps the release itself locked too. */
    public function testABlockedRangeKeepsALockedReleaseThatTheRootAliases(): void
    {
        $range = (new VersionParser())->parseConstraints('>=1.2.0,<1.2.5');
        $root = self::root(['acme/lib' => '1.2.3 as 1.3.0', 'acme/other' => '^1.0']);
        self::assertInstanceOf(RootPackage::class, $root);
        $root->setAliases([['package' => 'acme/lib', 'version' => '1.2.3.0', 'alias' => '1.3.0', 'alias_normalized' => '1.3.0.0']]);
        $lock = ['packages' => [['name' => 'acme/lib', 'version' => '1.2.3'], ['name' => 'acme/other', 'version' => '1.0.0']], 'aliases' => [['package' => 'acme/lib', 'version' => '1.2.3.0', 'alias' => '1.3.0', 'alias_normalized' => '1.3.0.0']]];
        $source = new ArrayVersionSource(['acme/lib' => [['version' => '1.2.3'], ['version' => '1.2.5']], 'acme/other' => [['version' => '1.0.0'], ['version' => '1.1.0']]]);
        $solver = new ComposerSolver($root, LockFile::fromArray($lock)->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), $source, ['acme/lib' => [$range]]);

        self::assertTrue($solver->solve(\Lockrot\Solver\SolveRequest::baseline())->resolves(), 'the locked 1.2.3 stays');
        self::assertTrue($solver->solve(\Lockrot\Solver\SolveRequest::update(['acme/other' => '^1.1.0']))->resolves());
    }

    /**
     * lockrot overrides `php` with the target PHP, and Composer appends the PHP that runs lockrot to
     * the override. The problem text names the override without the running PHP, so it is the same on
     * every machine.
     */
    public function testTheProblemTextOfARefusedBaselineOmitsThePhpThatRunsLockrot(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['php' => '~8.1.0']]],
            ['acme/lib' => [['version' => '1.0.0', 'require' => ['php' => '~8.1.0']]]]
        );

        $problem = (string) $checker->baseline()->problem();

        self::assertStringContainsString('8.4.99', $problem);
        self::assertStringNotContainsString('actual', $problem);
        self::assertStringNotContainsString(\PHP_VERSION, $problem);
    }

    /**
     * D8: Composer looks up the advisories of every pool name that the update can move, so a new
     * dependency that the run never analysed is blocked too. One lookup asks the names that the
     * run's own lookup did not cover, and its answer is kept.
     */
    public function testAnAdvisoryOfANewDependencyThatTheRunDidNotLookUpBlocksTheMove(): void
    {
        $asked = [];
        $lookup = static function (array $names, bool $complete) use (&$asked): array {
            $asked[] = $names;

            return ['acme/new-dependency' => [PartialSecurityAdvisory::create('acme/new-dependency', ['advisoryId' => 'PKSA-1111-2222-3333', 'affectedVersions' => '<2.0'], new VersionParser())]];
        };
        $source = new ArrayVersionSource(['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/new-dependency' => '^1.0']]], 'acme/new-dependency' => [['version' => '1.0.0']]]);
        $solver = new ComposerSolver(self::root(['acme/lib' => '^1.0']), LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), $source, []);
        $checker = (new SolverChecker($solver, [], '8.4.99', true, Deadline::never(), new PoolAdvisories(AdvisoryBlocking::everyAdvisory(), $lookup)))->withAdvisories(['acme/lib' => []], ['acme/lib']);

        $outcome = $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'));
        $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'));

        self::assertSame(MoveCheck::REFUSED, $outcome->check()->result());
        self::assertSame([['acme/new-dependency']], $asked, 'one lookup of the name that the run did not cover');
    }

    /**
     * SPEC §5.6 (ruling Q2): Composer blocks only the advisories that it reads, so a solve that
     * installs a name whose records the lookup could not read holds only if Composer resolves it.
     */
    public function testAMoveThatInstallsANameWhoseAdvisoriesTheLookupCouldNotReadIsNotChecked(): void
    {
        $source = new ArrayVersionSource(['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/new-dependency' => '^1.0']]], 'acme/new-dependency' => [['version' => '1.0.0']]]);
        $solver = new ComposerSolver(self::root(['acme/lib' => '^1.0']), LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), $source, []);
        $checker = static fn (bool $read): MoveChecker => (new SolverChecker($solver, [], '8.4.99', true, Deadline::never(), new PoolAdvisories(AdvisoryBlocking::everyAdvisory(), static fn (array $names, bool $complete): ?array => $read ? [] : null)))->withAdvisories(['acme/lib' => []], ['acme/lib']);

        $unread = $checker(false)->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'))->check();
        $read = $checker(true)->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'))->check();
        $refused = $checker(false)->check(MoveQuery::update('acme/lib', '^2.0', '2.0.0'))->check();

        self::assertSame([MoveCheck::NOT_CHECKED, MoveCheck::ADVISORY_LOOKUP_INCOMPLETE], [$unread->result(), $unread->reason()]);
        self::assertSame(MoveCheck::RESOLVES, $read->result(), 'a name that the lookup read and found no record for');
        self::assertSame(MoveCheck::REFUSED, $refused->result(), 'a refused solve stays refused');
        self::assertSame(MoveCheck::ADVISORY_LOOKUP_INCOMPLETE, $checker(false)->combined(['acme/lib' => '^1.1.0'])->check()->reason(), 'the update-now request');
        self::assertSame(MoveCheck::ADVISORY_LOOKUP_INCOMPLETE, $checker(false)->joint(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'), ['acme/lib'])->check()->reason(), 'the joint request');
        self::assertSame(MoveCheck::RESOLVES, $checker(true)->combined(['acme/lib' => '^1.1.0'])->check()->result());
        self::assertSame(MoveCheck::RESOLVES, $checker(true)->joint(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'), ['acme/lib'])->check()->result());
    }

    /** Composer's `block-abandoned` removes an abandoned release from the pool of an update. */
    public function testAMoveToAnAbandonedReleaseIsRefusedWhenTheConfigBlocksAbandonedPackages(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['audit' => ['block-abandoned' => true]]]);
        $source = new ArrayVersionSource(['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'abandoned' => true]]]);
        $solver = new ComposerSolver(self::root(['acme/lib' => '^1.0']), LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), $source, []);
        $advisories = new PoolAdvisories(AdvisoryBlocking::fromConfig($config), static fn (array $names, bool $complete): array => []);
        $checker = (new SolverChecker($solver, [], '8.4.99', true, Deadline::never(), $advisories))->withAdvisories([], ['acme/lib']);

        self::assertSame(MoveCheck::REFUSED, $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'))->check()->result());
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

    /** A plain member of the combined update can keep its locked release, so the outcome names what the solve installs. */
    public function testTheCombinedUpdateCarriesTheReleaseThatTheSolveInstallsOfEachName(): void
    {
        $checker = self::checker(
            ['acme/a' => '^1.0', 'acme/b' => '^1.0'],
            [['name' => 'acme/a', 'version' => '1.0.0'], ['name' => 'acme/b', 'version' => '1.0.0']],
            ['acme/a' => [['version' => '1.0.0'], ['version' => '1.1.0', 'conflict' => ['acme/b' => '>=1.1']]], 'acme/b' => [['version' => '1.0.0'], ['version' => '1.1.0']]]
        );

        $combined = $checker->combined(['acme/a' => '^1.1.0', 'acme/b' => '']);

        self::assertSame(MoveCheck::RESOLVES, $combined->check()->result());
        self::assertSame(['acme/a' => '1.1.0'], $combined->changes());
    }

    /** A release that starts to replace a package that the root keeps: Composer can install only one of the two. */
    public function testAMoveWhoseTargetReplacesALockedPackageIsRefusedWithTheReplace(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0', 'acme/legacy' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0'], ['name' => 'acme/legacy', 'version' => '1.0.0']],
            ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'replace' => ['acme/legacy' => '2.0.*']]], 'acme/legacy' => [['version' => '1.0.0']]]
        );

        $outcome = $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'));

        self::assertSame(MoveCheck::REFUSED, $outcome->check()->result());
        self::assertContains(['source' => 'package', 'package' => 'acme/lib', 'version' => '1.1.0', 'link' => 'replace', 'target' => 'acme/legacy', 'constraint' => '2.0.*'], self::rows($outcome->holds()));
    }

    /** D8: every release that the new constraint admits is blocked, so no release of it can install. */
    public function testARequireThatNoReleaseInThePoolAdmitsIsRefusedWithTheRootRequirementItWrites(): void
    {
        $range = (new VersionParser())->parseConstraints('>=2.0.0,<2.1.0');
        $source = new ArrayVersionSource(['acme/lib' => [['version' => '1.0.0'], ['version' => '2.0.0']]]);
        $solver = new ComposerSolver(self::root(['acme/lib' => '^1.0']), LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), $source, ['acme/lib' => [$range]]);
        $checker = new SolverChecker($solver, [], '8.4.99', true, Deadline::never());

        $outcome = $checker->check(MoveQuery::require('acme/lib', '^2.0', '2.0.0'));

        self::assertSame(MoveCheck::REFUSED, $outcome->check()->result());
        self::assertSame([['source' => 'root', 'package' => null, 'version' => null, 'link' => 'root-require', 'target' => null, 'constraint' => '^2.0']], self::rows($outcome->holds()));
    }

    public function testAMoveThatTheRootConflictExcludesIsRefusedWithTheRootConflict(): void
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => ['acme/lib' => '^1.0'], 'conflict' => ['acme/lib' => '>=1.1']], RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $root);
        $solver = new ComposerSolver($root, LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), new ArrayVersionSource(['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0']]]), []);
        $checker = new SolverChecker($solver, [], '8.4.99', false, Deadline::never());

        $outcome = $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'));

        self::assertSame(MoveCheck::REFUSED, $outcome->check()->result());
        self::assertContains(['source' => 'root', 'package' => null, 'version' => null, 'link' => 'conflict', 'target' => null, 'constraint' => '>=1.1'], self::rows($outcome->holds()));
        self::assertNotContains('package', array_column(self::rows($outcome->holds()), 'source'), 'the root is no locked package');
    }

    /**
     * On Composer 2.2 and 2.3 the inline constraint of a transitive package joins the root
     * requirements: that requirement is the request, never a hold of composer.json.
     */
    public function testATransitiveMoveThatADependentHoldsNamesNoRootHoldForItsOwnConstraint(): void
    {
        $checker = self::checker(
            ['acme/user' => '^1.0'],
            [['name' => 'acme/user', 'version' => '1.0.0', 'require' => ['acme/lib' => '^1.0']], ['name' => 'acme/lib', 'version' => '1.0.0']],
            ['acme/user' => [['version' => '1.0.0', 'require' => ['acme/lib' => '^1.0']]], 'acme/lib' => [['version' => '1.0.0'], ['version' => '2.0.0']]]
        );

        $outcome = $checker->check(MoveQuery::update('acme/lib', '^2.0', '2.0.0'));

        self::assertSame(MoveCheck::REFUSED, $outcome->check()->result());
        self::assertContains(['source' => 'package', 'package' => 'acme/user', 'version' => '1.0.0', 'link' => 'require', 'target' => null, 'constraint' => '^1.0'], self::rows($outcome->holds()));
        self::assertNotContains('^2.0', array_column(self::rows($outcome->holds()), 'constraint'), 'the inline constraint is the request');
    }

    /** The move check never stops a run: a failure of Composer's solver leaves the move not checked, and the checker keeps it. */
    public function testAMoveThatComposersSolverFailsOnIsNotCheckedAndTheFailureIsKept(): void
    {
        $source = new ArrayVersionSource(['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/new-dependency' => '^1.0']]]], [], ['acme/new-dependency' => new \UnexpectedValueException('Could not parse version constraint')]);
        $checker = self::checkerWith(['acme/lib' => '^1.0'], [['name' => 'acme/lib', 'version' => '1.0.0']], $source);

        $outcome = $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'));

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => MoveCheck::SOLVER_ERROR, 'installs' => null], $outcome->check()->jsonSerialize());
        self::assertSame(['class' => \UnexpectedValueException::class, 'message' => 'Could not parse version constraint'], $checker->solverError());
    }

    public function testAnErrorOfTheSolveEndsTheCheck(): void
    {
        $source = new ArrayVersionSource(['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/new-dependency' => '^1.0']]]], [], ['acme/new-dependency' => new \TypeError('a defect')]);
        $checker = self::checkerWith(['acme/lib' => '^1.0'], [['name' => 'acme/lib', 'version' => '1.0.0']], $source);

        $this->expectException(\TypeError::class);

        $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'));
    }

    /** The solver stops at its first failing chain: the scan of the target release names the other holds. */
    public function testARefusalAlsoNamesTheHoldsOfTheTargetThatTheSolversFirstFailingChainLeavesOut(): void
    {
        $checker = self::checker(
            ['acme/lib' => '^1.0', 'acme/x' => '^1.0', 'acme/y' => '^1.0'],
            [['name' => 'acme/lib', 'version' => '1.0.0'], ['name' => 'acme/x', 'version' => '1.0.0'], ['name' => 'acme/y', 'version' => '1.0.0']],
            ['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'conflict' => ['acme/x' => '<2.0'], 'require' => ['acme/y' => '^2.0']]], 'acme/x' => [['version' => '1.0.0']], 'acme/y' => [['version' => '1.0.0']]]
        );

        $holds = self::rows($checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'))->holds());

        self::assertContains(['source' => 'package', 'package' => 'acme/lib', 'version' => '1.1.0', 'link' => 'conflict', 'target' => 'acme/x', 'constraint' => '<2.0'], $holds);
        self::assertContains(['source' => 'package', 'package' => 'acme/lib', 'version' => '1.1.0', 'link' => 'require', 'target' => 'acme/y', 'constraint' => '^2.0'], $holds);
    }

    public function testACombinedUpdateOfANameThatTheSourceCouldNotLoadIsNotChecked(): void
    {
        $checker = self::checker(['acme/lib' => '^1.0'], [['name' => 'acme/lib', 'version' => '1.0.0']], [], ['acme/lib' => VersionSource::FAILED]);

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => MoveCheck::UNLOADED_NAME, 'installs' => null], $checker->combined(['acme/lib' => '^1.1.0'])->check()->jsonSerialize());
    }

    /** The run note names the first failure of Composer's solver, so a later solve that succeeds keeps it. */
    public function testTheFirstSolverFailureIsKeptAfterALaterSolveSucceeds(): void
    {
        $source = new ArrayVersionSource(['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['acme/new-dependency' => '^1.0']]], 'acme/other' => [['version' => '1.0.0'], ['version' => '1.1.0']]], [], ['acme/new-dependency' => new \UnexpectedValueException('Could not parse version constraint')]);
        $checker = self::checkerWith(['acme/lib' => '^1.0', 'acme/other' => '^1.0'], [['name' => 'acme/lib', 'version' => '1.0.0'], ['name' => 'acme/other', 'version' => '1.0.0']], $source);

        $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'));
        $resolved = $checker->check(MoveQuery::update('acme/other', '^1.1.0', '1.1.0'));

        self::assertSame(MoveCheck::RESOLVES, $resolved->check()->result());
        self::assertSame(['class' => \UnexpectedValueException::class, 'message' => 'Could not parse version constraint'], $checker->solverError());
    }

    /**
     * @dataProvider advisoryCheckers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('advisoryCheckers')]
    public function testOnlyACopyOfACheckerThatBlocksAdvisoriesReadsTheRunsRecords(bool $blocksAdvisories, string $result): void
    {
        $source = new ArrayVersionSource(['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0']]]);
        $solver = new ComposerSolver(self::root(['acme/lib' => '^1.0']), LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), $source, []);
        $checker = new SolverChecker($solver, [], '8.4.99', $blocksAdvisories, Deadline::never(), new PoolAdvisories(AdvisoryBlocking::everyAdvisory(), static fn (array $names, bool $complete): array => []));
        $records = ['acme/lib' => [PartialSecurityAdvisory::create('acme/lib', ['advisoryId' => 'PKSA-1111-2222-3333', 'affectedVersions' => '>=1.1.0,<1.2.0'], new VersionParser())]];

        $copy = $checker->withAdvisories($records, ['acme/lib']);

        self::assertSame(MoveCheck::RESOLVES, $checker->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'))->check()->result(), 'the checker itself reads no records');
        self::assertSame($result, $copy->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'))->check()->result());
    }

    /** @return iterable<string, array{bool, string}> */
    public static function advisoryCheckers(): iterable
    {
        yield 'a checker that blocks advisories' => [true, MoveCheck::REFUSED];
        yield 'a checker that blocks none' => [false, MoveCheck::RESOLVES];
    }

    /**
     * monica at 8.4, firebase/php-jwt: socialite and the manager hold php-jwt at 6.x. The joint
     * request may update them too, and it resolves: the move lists the holders whose release the
     * solve changes, and facebook, whose release stays, is not one of them.
     */
    public function testARefusedMoveResolvesWithTheHoldersWhoseReleaseTheJointSolveChanges(): void
    {
        $checker = self::checker(
            ['laravel/socialite' => '^5.5', 'socialiteproviders/facebook' => '^4.1'],
            [
                ['name' => 'laravel/socialite', 'version' => 'v5.23.0', 'require' => ['firebase/php-jwt' => '^6.4']],
                ['name' => 'socialiteproviders/facebook', 'version' => '4.1.0', 'require' => ['socialiteproviders/manager' => '~4.0']],
                ['name' => 'socialiteproviders/manager', 'version' => 'v4.8.1', 'require' => ['laravel/socialite' => '^5.5']],
                ['name' => 'firebase/php-jwt', 'version' => 'v6.11.1'],
            ],
            [
                'firebase/php-jwt' => [['version' => 'v6.11.1'], ['version' => 'v7.0.0'], ['version' => 'v7.2.1']],
                'laravel/socialite' => [['version' => 'v5.23.0', 'require' => ['firebase/php-jwt' => '^6.4']], ['version' => 'v5.30.0', 'require' => ['firebase/php-jwt' => '^6.4|^7.0']]],
                'socialiteproviders/facebook' => [['version' => '4.1.0', 'require' => ['socialiteproviders/manager' => '~4.0']]],
                'socialiteproviders/manager' => [['version' => 'v4.8.1', 'require' => ['laravel/socialite' => '^5.5']], ['version' => '4.10.0', 'require' => ['laravel/socialite' => '^5.5']]],
            ]
        );

        $outcome = (MoveChecks::of($checker))->check(MoveQuery::update('firebase/php-jwt', '^7.0.0', 'v7.0.0', null, 'v7.2.1'));

        self::assertSame(['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'installs' => 'v7.2.1'], $outcome->check()->jsonSerialize());
        self::assertSame([], $outcome->holds());
        self::assertSame([
            ['package' => 'laravel/socialite', 'installed_version' => 'v5.23.0', 'version' => 'v5.30.0'],
            ['package' => 'socialiteproviders/manager', 'installed_version' => 'v4.8.1', 'version' => '4.10.0'],
        ], $outcome->movesWith());
    }

    /**
     * monica at 8.4, erusev/parsedown: the joint update of scribe resolves by a scribe release that
     * drops erusev/parsedown. A solve that does not install the move is not the move, so the move
     * stays refused with the holds of its first refusal.
     */
    public function testAJointSolveThatDropsTheMovedPackageLeavesTheFirstRefusal(): void
    {
        $checker = self::checker(
            ['knuckleswtf/scribe' => '^5.1'],
            [
                ['name' => 'knuckleswtf/scribe', 'version' => '5.3.0', 'require' => ['erusev/parsedown' => '1.7.4']],
                ['name' => 'erusev/parsedown', 'version' => '1.7.4'],
            ],
            [
                'erusev/parsedown' => [['version' => '1.7.4'], ['version' => '1.8.0']],
                'knuckleswtf/scribe' => [['version' => '5.3.0', 'require' => ['erusev/parsedown' => '1.7.4']], ['version' => '5.11.0', 'require' => ['parsedown/parsedown' => '^1.7']]],
                'parsedown/parsedown' => [['version' => '1.8.0']],
            ]
        );

        $outcome = (MoveChecks::of($checker))->check(MoveQuery::update('erusev/parsedown', '^1.8.0', '1.8.0', null, '1.8.0'));

        self::assertSame(MoveCheck::REFUSED, $outcome->check()->result());
        self::assertContains(['source' => 'package', 'package' => 'knuckleswtf/scribe', 'version' => '5.3.0', 'link' => 'require', 'target' => null, 'constraint' => '1.7.4'], self::rows($outcome->holds()));
        self::assertSame([], $outcome->movesWith());
    }

    /**
     * acme/lib is a lock entry that nothing requires. Composer before 2.4 joins the inline
     * constraint to the root requirement, which alone keeps acme/lib installed. Composer 2.4 and
     * later drop it.
     */
    public function testAnUpdateOfALockEntryThatNothingRequiresInstallsNoRelease(): void
    {
        $checker = self::checker(
            ['acme/holder' => '^1.0'],
            [['name' => 'acme/holder', 'version' => '1.1.0'], ['name' => 'acme/lib', 'version' => '1.0.0']],
            ['acme/holder' => [['version' => '1.1.0']], 'acme/lib' => [['version' => '1.0.0'], ['version' => '2.0.0']]]
        );

        $outcome = $checker->check(MoveQuery::update('acme/lib', '^2.0.0', '2.0.0', null, '2.0.0'));

        self::assertNull($outcome->installs(), (string) $outcome->installs());
    }

    /**
     * The joint solve installs the move only through another reference of the locked holder's tag,
     * which changes no holder: the step would be the request that the first check refused.
     */
    public function testAJointSolveThatChangesNoHolderLeavesTheFirstRefusal(): void
    {
        $checker = self::checker(
            ['acme/h' => '^1.0'],
            [
                ['name' => 'acme/lib', 'version' => '1.0.0'],
                ['name' => 'acme/h', 'version' => '1.0.0', 'require' => ['acme/lib' => '^1.0'], 'dist' => ['type' => 'zip', 'url' => 'https://example.org/a.zip', 'reference' => 'aaaa']],
            ],
            [
                'acme/lib' => [['version' => '1.0.0'], ['version' => '2.0.0']],
                'acme/h' => [['version' => '1.0.0', 'require' => ['acme/lib' => '^1.0 || ^2.0'], 'dist' => ['type' => 'zip', 'url' => 'https://example.org/b.zip', 'reference' => 'bbbb']]],
            ]
        );

        $outcome = (MoveChecks::of($checker))->check(MoveQuery::update('acme/lib', '^2.0', '2.0.0', null, '2.0.0'));

        self::assertSame(MoveCheck::REFUSED, $outcome->check()->result());
        self::assertContains(['source' => 'package', 'package' => 'acme/h', 'version' => '1.0.0', 'link' => 'require', 'target' => null, 'constraint' => '^1.0'], self::rows($outcome->holds()));
        self::assertSame([], $outcome->movesWith());
    }

    /**
     * The joint request is refused too: the holds come from that refusal, and the root holds on a
     * package that a package hold names drop beside it.
     */
    public function testARefusedJointRequestGivesItsOwnHolds(): void
    {
        $checker = self::checker(
            ['acme/frame' => '^1.0', 'acme/http' => '^1.0'],
            [
                ['name' => 'acme/frame', 'version' => '1.0.0', 'require' => ['acme/lib' => '^1.0']],
                ['name' => 'acme/http', 'version' => '1.0.0'],
                ['name' => 'acme/lib', 'version' => '1.0.0'],
            ],
            [
                'acme/lib' => [['version' => '1.0.0'], ['version' => '2.0.0']],
                'acme/frame' => [['version' => '1.0.0', 'require' => ['acme/lib' => '^1.0']], ['version' => '1.1.0', 'require' => ['acme/lib' => '^1.0|^2.0', 'acme/http' => '^2.0']]],
                'acme/http' => [['version' => '1.0.0'], ['version' => '2.0.0']],
            ]
        );

        $outcome = (MoveChecks::of($checker))->check(MoveQuery::update('acme/lib', '^2.0.0', '2.0.0'));

        self::assertSame(MoveCheck::REFUSED, $outcome->check()->result());
        self::assertContains(['source' => 'root', 'package' => null, 'version' => null, 'link' => 'root-require', 'target' => 'acme/http', 'constraint' => '^1.0'], self::rows($outcome->holds()));
        self::assertNotContains(['source' => 'root', 'package' => null, 'version' => null, 'link' => 'root-require', 'target' => 'acme/frame', 'constraint' => '^1.0'], self::rows($outcome->holds()));
    }

    /**
     * monica at 8.4, web-auth/webauthn-lib: the inline update installs 5.3.8, below the newest 5.3.9,
     * which needs a newer cbor-php. `-w` installs 5.3.9, so it is the move's form.
     */
    public function testAFormThatInstallsBelowTheNewestReleaseGivesWayToTheNextForm(): void
    {
        $checker = self::checker(['acme/app-auth' => '^1.0'], self::webauthnLock(), self::webauthnRemote(true));

        $outcome = $checker->check(MoveQuery::update('web-auth/webauthn-lib', '^5.3.5', '5.3.5', null, '5.3.9'));

        self::assertSame(['result' => 'resolves', 'form' => 'with-dependencies', 'reason' => null, 'installs' => '5.3.9'], $outcome->check()->jsonSerialize());
    }

    /** No form installs 5.3.9: the first form that resolves stays the move's form, with the release it installs. */
    public function testWhenNoFormInstallsTheNewestReleaseTheFirstResolvingFormStays(): void
    {
        $checker = self::checker(['acme/app-auth' => '^1.0'], self::webauthnLock(), self::webauthnRemote(false));

        $outcome = $checker->check(MoveQuery::update('web-auth/webauthn-lib', '^5.3.5', '5.3.5', null, '5.3.9'));

        self::assertSame(['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'installs' => '5.3.8'], $outcome->check()->jsonSerialize());
    }

    /** No form installs 5.4.0, which needs a package that no repository has: `-w` installs 5.3.9, higher than the inline 5.3.8. */
    public function testWhenNoFormInstallsTheNewestReleaseTheFormThatInstallsTheHighestReleaseWins(): void
    {
        $remote = self::webauthnRemote(true);
        $remote['web-auth/webauthn-lib'][] = ['version' => '5.4.0', 'require' => ['acme/missing' => '^1.0']];
        $checker = self::checker(['acme/app-auth' => '^1.0'], self::webauthnLock(), $remote);

        $outcome = $checker->check(MoveQuery::update('web-auth/webauthn-lib', '^5.3.5', '5.3.5', null, '5.4.0'));

        self::assertSame(['result' => 'resolves', 'form' => 'with-dependencies', 'reason' => null, 'installs' => '5.3.9'], $outcome->check()->jsonSerialize());
    }

    /** Without a newest release, the first form that resolves is the move's form, although `-w` installs a higher release. */
    public function testWithoutANewestReleaseTheFirstResolvingFormStays(): void
    {
        $checker = self::checker(['acme/app-auth' => '^1.0'], self::webauthnLock(), self::webauthnRemote(true));

        $outcome = $checker->check(MoveQuery::update('web-auth/webauthn-lib', '^5.3.5', '5.3.5'));

        self::assertSame(['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'installs' => '5.3.8'], $outcome->check()->jsonSerialize());
    }

    /** The form that installs the move's newest release wins, although a later form installs a higher one. */
    public function testTheFirstFormThatInstallsTheNewestReleaseWinsOverAHigherRelease(): void
    {
        $checker = self::checker(['acme/app-auth' => '^1.0'], self::webauthnLock(), self::webauthnRemote(true));

        $outcome = $checker->check(MoveQuery::update('web-auth/webauthn-lib', '^5.3.5', '5.3.5', null, '5.3.8'));

        self::assertSame(['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'installs' => '5.3.8'], $outcome->check()->jsonSerialize());
    }

    /** The budget stops `-w` after the inline form resolved below the newest release: the inline form stays the move's form. */
    public function testABudgetThatStopsALaterFormKeepsTheFormThatResolved(): void
    {
        // The clock reads: the deadline, the baseline, the inline form, then `-w` past the budget.
        $readings = [0.0, 0.0, 0.0, 100.0];
        $now = static function () use (&$readings): float {
            return array_shift($readings) ?? 100.0;
        };
        $solver = new ComposerSolver(self::root(['acme/app-auth' => '^1.0']), LockFile::fromArray(['packages' => self::webauthnLock()])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), new ArrayVersionSource(self::webauthnRemote(true)), []);
        $checker = new SolverChecker($solver, [], '8.4.99', false, Deadline::inSeconds(10.0, $now));

        $outcome = $checker->check(MoveQuery::update('web-auth/webauthn-lib', '^5.3.5', '5.3.5', null, '5.3.9'));

        self::assertSame(['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'installs' => '5.3.8'], $outcome->check()->jsonSerialize());
    }

    /** The inline form keeps 5.2.2, because 5.3.9 needs a newer cbor-php: `-w` installs a release, so it wins. */
    public function testAFormThatInstallsTheMovedPackageWinsOverAFormThatKeepsIt(): void
    {
        $remote = self::webauthnRemote(true);
        $remote['web-auth/webauthn-lib'] = [
            ['version' => '5.2.2', 'require' => ['spomky-labs/cbor-php' => '^3.0']],
            ['version' => '5.3.9', 'require' => ['spomky-labs/cbor-php' => '^3.4']],
            ['version' => '5.4.0', 'require' => ['acme/missing' => '^1.0']],
        ];
        $checker = self::checker(['acme/app-auth' => '^1.0'], self::webauthnLock(), $remote);

        $outcome = $checker->check(MoveQuery::update('web-auth/webauthn-lib', '^5.2.0', '5.2.0', null, '5.4.0'));

        self::assertSame(['result' => 'resolves', 'form' => 'with-dependencies', 'reason' => null, 'installs' => '5.3.9'], $outcome->check()->jsonSerialize());
    }

    /** No form installs a release of the package: the inline form stays, the first on the tie. */
    public function testWhenNoFormInstallsTheMovedPackageTheInlineFormStays(): void
    {
        $remote = self::webauthnRemote(false);
        $remote['web-auth/webauthn-lib'] = [
            ['version' => '5.2.2', 'require' => ['spomky-labs/cbor-php' => '^3.0']],
            ['version' => '5.4.0', 'require' => ['acme/missing' => '^1.0']],
        ];
        $checker = self::checker(['acme/app-auth' => '^1.0'], self::webauthnLock(), $remote);

        $outcome = $checker->check(MoveQuery::update('web-auth/webauthn-lib', '^5.2.0', '5.2.0', null, '5.4.0'));

        self::assertSame(['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'installs' => null], $outcome->check()->jsonSerialize());
    }

    /** @return list<array<string, mixed>> */
    private static function webauthnLock(): array
    {
        return [
            ['name' => 'acme/app-auth', 'version' => '1.0.0', 'require' => ['web-auth/webauthn-lib' => '^5.2']],
            ['name' => 'web-auth/webauthn-lib', 'version' => '5.2.2', 'require' => ['spomky-labs/cbor-php' => '^3.0']],
            ['name' => 'spomky-labs/cbor-php', 'version' => '3.1.1'],
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function webauthnRemote(bool $newCbor): array
    {
        return [
            'web-auth/webauthn-lib' => [
                ['version' => '5.2.2', 'require' => ['spomky-labs/cbor-php' => '^3.0']],
                ['version' => '5.3.8', 'require' => ['spomky-labs/cbor-php' => '^3.0']],
                ['version' => '5.3.9', 'require' => ['spomky-labs/cbor-php' => '^3.4']],
            ],
            'spomky-labs/cbor-php' => array_merge([['version' => '3.1.1']], $newCbor ? [['version' => '3.4.2']] : []),
        ];
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
     * @param array<string, string>      $conflicts the root's `conflict`
     */
    private static function checkerWith(array $requires, array $locked, VersionSource $source, array $conflicts = []): SolverChecker
    {
        $solver = new ComposerSolver(self::root($requires, $conflicts), LockFile::fromArray(['packages' => $locked])->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), $source, []);

        return new SolverChecker($solver, [], '8.4.99', false, Deadline::never());
    }

    /**
     * @param array<string, string> $requires
     * @param array<string, string> $conflicts
     */
    private static function root(array $requires, array $conflicts = []): RootPackageInterface
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => $requires, 'conflict' => $conflicts], RootPackage::class);
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
