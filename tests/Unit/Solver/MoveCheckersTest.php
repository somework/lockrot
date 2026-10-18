<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackage;
use Composer\Package\RootPackageInterface;
use Composer\Repository\PlatformRepository;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Solver\ComposerSolver;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveCheckers;
use Lockrot\Solver\MoveQuery;
use Lockrot\Solver\SolverChecker;
use Lockrot\Tests\Support\ArrayVersionSource;
use PHPUnit\Framework\TestCase;

final class MoveCheckersTest extends TestCase
{
    public function testThePlatformPhpIsTheConfiguredPatchInTheTargetMinor(): void
    {
        self::assertSame('7.4.29', MoveCheckers::platformPhp('7.4', '7.4.29'));
    }

    /** Composer reads a platform php with no patch as patch 0, so a release that needs a later patch does not install. */
    public function testAPlatformPhpWithNoPatchInTheTargetMinorIsKeptAsWritten(): void
    {
        $php = MoveCheckers::platformPhp('8.4', '8.4');
        $solver = new ComposerSolver(self::root(), LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]])->lockedRepository(), new PlatformRepository([], ['php' => $php]), new ArrayVersionSource(['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['php' => '>=8.4.1']]]]), []);

        $outcome = (new SolverChecker($solver, [], $php, false, Deadline::never()))->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'));

        self::assertSame('8.4', $php);
        self::assertSame(MoveCheck::REFUSED, $outcome->check()->result());
    }

    public function testThePlatformPhpIsTheNewestPatchOfTheTargetWithoutAConfiguredOneInIt(): void
    {
        self::assertSame('8.4.99', MoveCheckers::platformPhp('8.4', null));
        self::assertSame('8.4.99', MoveCheckers::platformPhp('8.4', '7.4.29'));
        self::assertSame('8.4.99', MoveCheckers::platformPhp('8.4', '8.41.0'));
        self::assertSame('8.4.99', MoveCheckers::platformPhp('8.4', '8.4-dev'));
    }

    private static function root(): RootPackageInterface
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => ['acme/lib' => '^1.0']], RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $root);

        return $root;
    }
}
