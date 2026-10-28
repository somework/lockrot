<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackage;
use Composer\Package\RootPackageInterface;
use Lockrot\Lock\LockFile;
use Lockrot\Security\Holder;
use Lockrot\Solver\HoldScan;
use PHPUnit\Framework\TestCase;

final class HoldScanTest extends TestCase
{
    public function testAConflictOfTheTargetOnALockedVersionThatItMatchesHoldsTheMove(): void
    {
        $scan = self::scan([], [['name' => 'acme/old', 'version' => '1.0.0'], ['name' => 'acme/new', 'version' => '2.0.0']]);
        $target = self::target(['conflict' => ['acme/old' => '<2.0', 'acme/new' => '<2.0', 'acme/absent' => '*']]);

        self::assertSame([
            ['source' => 'package', 'package' => 'acme/lib', 'version' => '1.1.0', 'link' => 'conflict', 'target' => 'acme/old', 'constraint' => '<2.0'],
        ], self::rows($scan->of($target, null)));
    }

    public function testARequirementThatALockedVersionDoesNotMeetHoldsTheMoveAndEveryOtherOneIsRead(): void
    {
        $scan = self::scan([], [['name' => 'acme/met', 'version' => '1.0.0'], ['name' => 'acme/dep', 'version' => '1.0.0']]);
        $target = self::target(['require' => ['acme/met' => '^1.0', 'ext-lockrot-test' => '*', 'acme/unlocked' => '^1.0', 'acme/dep' => '^2.0'], 'conflict' => ['acme/met' => '<1.1']]);

        self::assertSame([
            ['source' => 'package', 'package' => 'acme/lib', 'version' => '1.1.0', 'link' => 'conflict', 'target' => 'acme/met', 'constraint' => '<1.1'],
            ['source' => 'package', 'package' => 'acme/lib', 'version' => '1.1.0', 'link' => 'require', 'target' => 'acme/dep', 'constraint' => '^2.0'],
        ], self::rows($scan->of($target, null)));
    }

    /**
     * @dataProvider rootRequirements
     *
     * @param array<string, mixed> $root
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rootRequirements')]
    public function testTheRootRequirementThatKeepsAnUnmetDependencyLockedHoldsTheMoveToo(array $root): void
    {
        $scan = self::scan($root, [['name' => 'acme/dep', 'version' => '1.0.0'], ['name' => 'acme/open', 'version' => '1.0.0']]);
        $target = self::target(['require' => ['acme/dep' => '^2.0', 'acme/open' => '^2.0']]);

        self::assertSame([
            ['source' => 'package', 'package' => 'acme/lib', 'version' => '1.1.0', 'link' => 'require', 'target' => 'acme/dep', 'constraint' => '^2.0'],
            ['source' => 'root', 'package' => null, 'version' => null, 'link' => 'root-require', 'target' => 'acme/dep', 'constraint' => '^1.0'],
            ['source' => 'package', 'package' => 'acme/lib', 'version' => '1.1.0', 'link' => 'require', 'target' => 'acme/open', 'constraint' => '^2.0'],
        ], self::rows($scan->of($target, null)));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function rootRequirements(): iterable
    {
        yield 'require' => [['require' => ['acme/dep' => '^1.0', 'acme/open' => '>=1.0']]];
        yield 'require-dev' => [['require-dev' => ['acme/dep' => '^1.0', 'acme/open' => '>=1.0']]];
    }

    /** Composer's solve takes the require-dev constraint of a name that both sections list. */
    public function testTheRootHoldOfANameInBothSectionsIsItsRequireDevConstraint(): void
    {
        $scan = self::scan(['require' => ['acme/dep' => '^1.0'], 'require-dev' => ['acme/dep' => '^1.2']], [['name' => 'acme/dep', 'version' => '1.2.0']]);
        $target = self::target(['require' => ['acme/dep' => '^2.0']]);

        self::assertContains(['source' => 'root', 'package' => null, 'version' => null, 'link' => 'root-require', 'target' => 'acme/dep', 'constraint' => '^1.2'], self::rows($scan->of($target, null)));
    }

    public function testAPhpRequirementThatTheTargetPhpDoesNotMeetHoldsTheMove(): void
    {
        $scan = self::scan([], [['name' => 'acme/dep', 'version' => '1.0.0']]);
        $target = self::target(['require' => ['php' => '>=8.5', 'acme/dep' => '^2.0']]);

        self::assertSame([
            ['source' => 'package', 'package' => 'acme/lib', 'version' => '1.1.0', 'link' => 'platform', 'target' => 'php', 'constraint' => '>=8.5'],
            ['source' => 'package', 'package' => 'acme/lib', 'version' => '1.1.0', 'link' => 'require', 'target' => 'acme/dep', 'constraint' => '^2.0'],
        ], self::rows($scan->of($target, '8.4.99.0')));
    }

    public function testAPhpRequirementThatTheTargetPhpMeetsOrThatNoTargetPhpChecksHoldsNothing(): void
    {
        $scan = self::scan([], []);
        $target = self::target(['require' => ['php' => '>=8.1']]);

        self::assertSame([], self::rows($scan->of($target, '8.4.99.0')));
        self::assertSame([], self::rows($scan->of($target, null)));
    }

    /** A branch alias loads before the dev version that it aliases, and the alias version is the one that a requirement reads. */
    public function testARequirementThatTheBranchAliasOfALockedDevVersionMeetsHoldsNothing(): void
    {
        $scan = self::scan([], [['name' => 'acme/dep', 'version' => 'dev-main', 'extra' => ['branch-alias' => ['dev-main' => '2.x-dev']]]]);

        self::assertSame([], self::rows($scan->of(self::target(['require' => ['acme/dep' => '^2.0@dev']]), null)));
    }

    /**
     * @param array<string, mixed>       $root
     * @param list<array<string, mixed>> $locked
     */
    private static function scan(array $root, array $locked): HoldScan
    {
        $package = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0'] + $root, RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $package);

        return new HoldScan($package, LockFile::fromArray(['packages' => $locked])->lockedRepository());
    }

    /** @param array<string, mixed> $links */
    private static function target(array $links): BasePackage
    {
        $package = (new ArrayLoader())->load(['name' => 'acme/lib', 'version' => '1.1.0'] + $links);
        self::assertInstanceOf(BasePackage::class, $package);

        return $package;
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
