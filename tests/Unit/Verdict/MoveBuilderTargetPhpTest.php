<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackage;
use Composer\Package\RootPackageInterface;
use Composer\Repository\PlatformRepository;
use Composer\Semver\VersionParser;
use Lockrot\Clock;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Security\Fix;
use Lockrot\Security\FixFinder;
use Lockrot\Security\LinkIndex;
use Lockrot\Signal\BranchRow;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Solver\ComposerSolver;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\SolverChecker;
use Lockrot\Tests\Support\ArrayVersionSource;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Move;
use Lockrot\Verdict\MoveBuilder;
use Lockrot\Verdict\MoveChecks;
use Lockrot\Verdict\MoveContext;
use Lockrot\Verdict\MoveDraft;
use Lockrot\Verdict\MoveSubject;
use PHPUnit\Framework\TestCase;

/**
 * Ruling R8-1: `php_check` of a move that the target runs reads the release that Composer installs
 * at the target, the newest one in the move's constraint whose php admits it. Composer's solver
 * checks each move at the target's platform php.
 */
final class MoveBuilderTargetPhpTest extends TestCase
{
    private const NOW = '2026-10-08T00:00:00+00:00';
    private const PACKAGE = 'acme/lib';

    /** The fix 1.2.0 and 1.8.0 run on 8.0, the branch top 1.9.0 needs 8.1: Composer installs 1.8.0 with no raise. */
    public function testAnUpdateWhoseBranchTopNeedsAPhpAboveTheTargetInstallsTheNewestReleaseThatTheTargetRuns(): void
    {
        $releases = [['1.0.0', '>=7.2'], ['1.2.0', '>=7.2'], ['1.8.0', '>=7.2'], ['1.9.0', '>=8.1']];
        [$move, $checks, $finder, $facts] = self::updateMove($releases, '8.0', '>=7.4');
        $phpCheck = $move->phpCheck();
        $check = $move->check();

        self::assertNotNull($phpCheck);
        self::assertSame(['>=7.2', true, true, null], [$phpCheck->requires(), $phpCheck->projectAllows(), $phpCheck->targetRuns(), $phpCheck->raiseTo()]);
        self::assertNotNull($check);
        self::assertSame(MoveCheck::RESOLVES, $check->result());
        self::assertSame('1.8.0', $move->newest());
        self::assertSame([['composer', 'update', self::PACKAGE.':^1.2.0']], $move->commands());
        self::assertSame([], $move->heldBy());
        self::assertSame(0, $checks->refused(self::PACKAGE)->count(), 'the next pass reads no root hold');
        $installed = $finder->withRefused($checks->refused(self::PACKAGE))->find($facts)->installedBranch();
        self::assertNotNull($installed);
        self::assertSame(Fix::UPDATE, $installed->fixKind());
    }

    /** The release that the target installs needs 8.0.2, above require.php 7.4: the raise writes 8.0.2, not the branch top's 8.1. */
    public function testARaiseWritesThePhpOfTheReleaseThatTheTargetInstalls(): void
    {
        $releases = [['1.0.0', '>=7.2'], ['1.2.0', '>=8.0.2'], ['1.9.0', '>=8.1']];
        [$move] = self::updateMove($releases, '8.0', '>=7.4');
        $phpCheck = $move->phpCheck();
        $check = $move->check();

        self::assertNotNull($phpCheck);
        self::assertSame(['>=8.0.2', true, '>=8.0.2', 'major'], [$phpCheck->requires(), $phpCheck->targetRuns(), $phpCheck->raiseTo(), $phpCheck->raiseSize()]);
        self::assertNotNull($check);
        self::assertSame(MoveCheck::RESOLVES, $check->result(), 'Composer resolves the raise at 8.0.99');
        self::assertSame([['composer', 'require', 'php:>=8.0.2', '--no-update'], ['composer', 'update', self::PACKAGE.':^1.2.0']], $move->commands());
    }

    /** The branch top 2.5.0 needs 8.1, 2.3.0 runs on 8.0: the require writes no raise, and Composer installs 2.3.0. */
    public function testARequireToAHigherBranchReadsTheNewestReleaseThatTheTargetRuns(): void
    {
        $releases = [['1.0.0', '>=7.2'], ['2.0.0', '>=7.2'], ['2.3.0', '>=7.2'], ['2.5.0', '>=8.1']];
        [$move] = self::updateMove($releases, '8.0', '>=7.4', Move::REQUIRE, '2.x', '^2.0.0');
        $phpCheck = $move->phpCheck();
        $check = $move->check();

        self::assertNotNull($phpCheck);
        self::assertSame(['>=7.2', true, null], [$phpCheck->requires(), $phpCheck->targetRuns(), $phpCheck->raiseTo()]);
        self::assertNotNull($check);
        self::assertSame([MoveCheck::RESOLVES, '2.3.0'], [$check->result(), $check->installsBelow($move->newest())]);
        self::assertSame([['composer', 'require', self::PACKAGE.':^2.0.0']], $move->commands());
    }

    /**
     * acme/other's link `<2.4` excludes 2.4.0, the highest 2.x release that 8.0 runs. Composer
     * installs 2.3.0, so the require reads its php and writes no raise for 2.4.0's `>=7.3`.
     */
    public function testARequireReadsNoReleaseThatALockLinkExcludes(): void
    {
        $releases = [['1.0.0', '>=7.2'], ['2.0.0', '>=7.2'], ['2.3.0', '>=7.2'], ['2.4.0', '>=7.3'], ['2.5.0', '>=8.1']];
        $other = ['name' => 'acme/other', 'version' => '1.0.0', 'require' => [self::PACKAGE => '<2.4'], 'notification-url' => 'https://packagist.org/downloads/'];
        [$move] = self::updateMove($releases, '8.0', '>=7.2', Move::REQUIRE, '2.x', '^2.0.0', [$other]);
        $phpCheck = $move->phpCheck();

        self::assertNotNull($phpCheck);
        self::assertSame(['>=7.2', true, null], [$phpCheck->requires(), $phpCheck->targetRuns(), $phpCheck->raiseTo()]);
    }

    /**
     * `config.platform.php` 8.0.1 lies in the target minor, so Composer solves at 8.0.1 and cannot
     * install 1.8.5 (`>=8.0.2`). The update reads 1.8.0, the newest release that 8.0.1 runs.
     */
    public function testAConfiguredPatchInTheTargetMinorReadsTheNewestReleaseThatThePatchRuns(): void
    {
        $releases = [['1.0.0', '>=7.2'], ['1.2.0', '>=7.2'], ['1.8.0', '>=7.2'], ['1.8.5', '>=8.0.2']];
        [$move] = self::updateMove($releases, '8.0', '>=7.2', Move::UPDATE, '1.x', '^1.2.0', [], '8.0.1');
        $phpCheck = $move->phpCheck();
        $check = $move->check();

        self::assertSame('1.8.0', $move->newest());
        self::assertNotNull($phpCheck);
        self::assertSame(['>=7.2', true, null], [$phpCheck->requires(), $phpCheck->targetRuns(), $phpCheck->raiseTo()]);
        self::assertNotNull($check);
        self::assertSame(MoveCheck::RESOLVES, $check->result(), 'Composer resolves the update at 8.0.1');
        self::assertSame([['composer', 'update', self::PACKAGE.':^1.2.0']], $move->commands());
    }

    /**
     * SPEC §5.6 L4: no release on 2.x runs on 8.0, and lockrot read no release the move takes, so
     * `php_check` reads the branch top. The text does not say that the target runs it.
     */
    public function testARequireTextNamesNoTargetThatDoesNotRunTheRelease(): void
    {
        $releases = [['1.0.0', '>=7.2'], ['2.0.0', '>=8.1']];
        [$move] = self::updateMove($releases, '8.0', '>=7.4', Move::REQUIRE, '2.x', null);
        $phpCheck = $move->phpCheck();

        self::assertNotNull($phpCheck);
        self::assertSame(['>=8.1', false], [$phpCheck->requires(), $phpCheck->targetRuns()]);
        self::assertStringNotContainsString('runs it', $move->text());
    }

    /**
     * A move of acme/lib, checked by Composer's solver at the target: by default the update to the
     * fix 1.2.0 on the installed branch. With $constraint, the move to the lowest release on
     * $branch that it admits, else to $branch with no release read. Composer solves at $platform,
     * else at the target minor's newest patch.
     *
     * @param list<array{string, string}> $releases each release of acme/lib: its version and php
     * @param list<array<string, mixed>>   $others   further lock entries beside acme/lib 1.0.0
     *
     * @return array{0: Move, 1: MoveChecks, 2: FixFinder, 3: PackageFacts}
     */
    private static function updateMove(array $releases, string $target, string $projectPhp, string $kind = Move::UPDATE, string $branch = '1.x', ?string $constraint = '^1.2.0', array $others = [], ?string $platform = null): array
    {
        $packages = [];
        $remote = [];
        foreach ($releases as [$version, $php]) {
            $entry = ['version' => $version, 'time' => '2026-01-01T00:00:00+00:00', 'require' => ['php' => $php]];
            $release = (new ArrayLoader())->load(['name' => self::PACKAGE] + $entry);
            self::assertInstanceOf(BasePackage::class, $release);
            $packages[] = $release;
            $remote[] = $entry;
        }
        $lockEntry = ['name' => self::PACKAGE, 'version' => '1.0.0', 'require' => ['php' => '>=7.2'], 'notification-url' => 'https://packagist.org/downloads/'];
        $lock = LockFile::fromArray(['packages' => array_merge([$lockEntry], $others)]);
        $locked = $lock->find(self::PACKAGE);
        self::assertNotNull($locked);
        $facts = new PackageFacts($locked, PackageMetadata::fromPackages(self::PACKAGE, $packages, new \DateTimeImmutable(self::NOW), '1.0.0.0'), null, [new Advisory('A', null, null, null, 'high', null, (new VersionParser())->parseConstraints('<1.2.0'))]);
        $require = ['php' => $projectPhp, self::PACKAGE => '^1.0'];
        $platform ??= $target.'.'.PhpFloor::NEWEST_PATCH;
        $floor = (new PhpFloor($target, $projectPhp))->solvedAt($platform);
        $finder = new FixFinder($floor, LinkIndex::of($lock, ProjectConfig::fromArray(['require' => $require])));
        $fixes = $finder->find($facts);
        $solver = new ComposerSolver(self::root($require), $lock->lockedRepository(), new PlatformRepository([], ['php' => $platform]), new ArrayVersionSource([self::PACKAGE => $remote]));
        $checks = MoveChecks::of(new SolverChecker($solver, [], $platform, false, Deadline::never()));
        $finding = (new FindingBuilder())->withPackage(self::PACKAGE)->withVersion('1.0.0')->build();
        $context = (new MoveContext($finding, new MoveSubject(self::PACKAGE, '1.0.0', true, [], $target, ltrim($projectPhp, '>=').'.0'), $facts->metadata(), BranchRow::all($facts, $floor, Clock::fixed(self::NOW), null, $fixes), $fixes, $finder, $floor, new \DateTimeImmutable(self::NOW), 2, null, [], [], true))
            ->withChecks($checks);
        $rows = array_values(array_filter($context->rows(), static fn (BranchRow $row): bool => $row->branch() === $branch));
        self::assertCount(1, $rows);
        [$lower] = $constraint === null ? [null] : $context->lowestAdmitted($rows[0], $constraint);

        $move = MoveBuilder::build(MoveDraft::of($kind, FlagSet::VULNERABLE)->to($rows[0], $lower, $kind === Move::UPDATE ? null : ($constraint ?? '^2.0')), [], Fix::UPDATE, $context);

        return [$move, $checks, $finder, $facts];
    }

    /** @param array<string, string> $requires */
    private static function root(array $requires): RootPackageInterface
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => $requires], RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $root);

        return $root;
    }
}
