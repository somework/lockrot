<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Clock;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Security\FixFinder;
use Lockrot\Security\LinkIndex;
use Lockrot\Signal\BranchRow;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\MaintenanceMove;
use Lockrot\Verdict\Move;
use Lockrot\Verdict\MoveContext;
use Lockrot\Verdict\MoveDraft;
use Lockrot\Verdict\MoveSubject;
use PHPUnit\Framework\TestCase;

final class MaintenanceMoveTest extends TestCase
{
    private const NOW = '2026-10-08T00:00:00+00:00';

    /** a/b 1.0.0 predates the php that the project targets, and the 2.x and 3.x lines both come after it. */
    public function testAnOldPromiseMovesToTheNearestLineReleasedAfterTheTargetPhp(): void
    {
        $releases = [];
        foreach (['1.0.0' => '2020-01-01', '2.0.0' => '2026-01-01', '3.0.0' => '2026-02-01'] as $version => $at) {
            $release = (new ArrayLoader())->load(['name' => 'a/b', 'version' => $version, 'time' => $at.'T00:00:00+00:00']);
            self::assertInstanceOf(BasePackage::class, $release);
            $releases[] = $release;
        }
        $lock = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $locked = $lock->find('a/b');
        self::assertNotNull($locked);
        $facts = new PackageFacts($locked, PackageMetadata::fromPackages('a/b', $releases, new \DateTimeImmutable(self::NOW), '1.0.0.0'), null, []);
        $floor = new PhpFloor('8.4');
        $finding = (new FindingBuilder())->withPackage('a/b')->withVersion('1.0.0')->build();
        $context = new MoveContext($finding, new MoveSubject('a/b', '1.0.0', true, [], '8.4', null), $facts->metadata(), BranchRow::all($facts, $floor, Clock::fixed(self::NOW)), null, new FixFinder($floor, LinkIndex::of($lock, ProjectConfig::empty())), $floor, new \DateTimeImmutable(self::NOW), 2, new \DateTimeImmutable('2025-01-01T00:00:00+00:00'), [], [], true);

        $draft = MaintenanceMove::draft($context, FlagSet::OLD_PROMISE);
        $row = $draft->row();

        self::assertNotNull($row);
        self::assertSame(['2.x', '^2.0.0'], [$row->branch(), $draft->constraint()]);
    }

    /** `admits_newest` on the installed branch, which the target and the project run and nothing holds. */
    public function testACappedPackageUpdatesToTheReleaseThatAdmitsTheNewestPhpOnItsBranch(): void
    {
        $draft = self::capped(['4.7.1' => '>=7.4, <8.2', '4.17.0' => '>=8.1'], '4.17.0', '4.x');

        self::assertSame([Move::UPDATE, FlagSet::CAPPED, '4.x', null], [$draft->kind(), $draft->source(), self::branch($draft), $draft->constraint()]);
    }

    public function testACappedPackageRequiresTheReleaseThatAdmitsTheNewestPhpOnAHigherBranch(): void
    {
        $draft = self::capped(['4.7.1' => '>=7.4, <8.2', '4.17.0' => '<8.5', '5.0.0' => '>=8.1'], '5.0.0', '5.x');

        self::assertSame([Move::REQUIRE, '5.x', '^5.0.0'], [$draft->kind(), self::branch($draft), $draft->constraint()]);
    }

    /** The constraint drops the tag's `v`: Composer reads `^v5.0.0` too, and `composer.json` writes `^5.0.0`. */
    public function testACappedRequireOfAVPrefixedReleaseWritesItsConstraintWithoutTheV(): void
    {
        $draft = self::capped(['4.7.1' => '>=7.4, <8.2', 'v5.0.0' => '>=8.1'], 'v5.0.0', '5.x');

        self::assertSame([Move::REQUIRE, '^5.0.0'], [$draft->kind(), $draft->constraint()]);
    }

    /** A root constraint that holds the installed branch's release names it, as a move to another branch does. */
    public function testACappedReleaseOnTheInstalledBranchThatARootConstraintHoldsIsRequired(): void
    {
        $draft = self::capped(['4.7.1' => '>=7.4, <8.2', '4.17.0' => '>=8.1'], '4.17.0', '4.x', '8.4', null, ['a/b' => '~4.7.0']);

        self::assertSame([Move::REQUIRE, '4.x', '^4.17.0'], [$draft->kind(), self::branch($draft), $draft->constraint()]);
    }

    public function testACappedReleaseOutsideTheProjectsLowestPhpRaisesIt(): void
    {
        $draft = self::capped(['4.7.1' => '>=7.4, <8.2', '4.17.0' => '~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0'], '4.17.0', '4.x', '8.4', '>=7.4');

        self::assertSame([Move::RAISE_PHP, '4.x', '^4.17.0'], [$draft->kind(), self::branch($draft), $draft->constraint()]);
    }

    public function testACappedReleaseThatTheTargetDoesNotRunIsBlocked(): void
    {
        $draft = self::capped(['4.7.1' => '>=7.4, <8.2', '4.17.0' => '~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0'], '4.17.0', '4.x', '7.4');

        self::assertSame([Move::BLOCKED, '4.x', null], [$draft->kind(), self::branch($draft), $draft->constraint()]);
    }

    /** `blocking` names the project first, but a target outside the release blocks the move. */
    public function testACappedReleaseThatTheProjectAndTheTargetExcludeIsBlocked(): void
    {
        $draft = self::capped(['4.7.1' => '>=7.4, <8.2', '4.17.0' => '~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0'], '4.17.0', '4.x', '7.4', '>=7.4');

        self::assertSame(Move::BLOCKED, $draft->kind());
    }

    /** 4.7.1 requires php `>=7.4, <8.2`: target 8.1 runs it, and target 8.4 does not (S11's `excludes_target`). */
    public function testATargetThatTheInstalledReleaseExcludesChangesNoCappedMove(): void
    {
        $releases = ['4.7.1' => '>=7.4, <8.2', '4.17.0' => '>=7.4'];

        $inside = self::capped($releases, '4.17.0', '4.x', '8.1', null, [], '4.7.1', true, '>=7.4, <8.2', false);
        $outside = self::capped($releases, '4.17.0', '4.x', '8.4', null, [], '4.7.1', true, '>=7.4, <8.2', true);

        self::assertSame([Move::UPDATE, '4.x'], [$inside->kind(), self::branch($inside)]);
        self::assertSame(self::shape($inside), self::shape($outside));
    }

    public function testACappedPackageWithNoReleaseThatAdmitsTheNewestPhpHasNoMove(): void
    {
        $draft = self::capped(['2.0.0' => '^7.0', '2.1.0' => '^7.0'], null, null, '8.4', null, [], '2.0.0');

        self::assertSame([Move::NO_MOVE, 'no_release_admits'], [$draft->kind(), $draft->reason()]);
    }

    /** Unread metadata never claims that no release admits R: the releases were not read. */
    public function testACappedPackageWhoseMetadataWasNotReadHasNoMoveForThatReason(): void
    {
        $draft = self::capped([], null, null, '8.4', null, [], '4.7.1', false);

        self::assertSame([Move::NO_MOVE, 'metadata_not_read'], [$draft->kind(), $draft->reason()]);
    }

    /** @return array{string, string, ?string, ?string, ?string} */
    private static function shape(MoveDraft $draft): array
    {
        $lower = $draft->lower();

        return [$draft->kind(), $draft->source(), self::branch($draft), $draft->constraint(), $lower === null ? null : $lower->release()->pretty()];
    }

    private static function branch(MoveDraft $draft): ?string
    {
        $row = $draft->row();

        return $row === null ? null : $row->branch();
    }

    /**
     * The move of a/b when `capped` leads: its S11 names `admits_newest`.
     *
     * @param array<string, string>  $releases by version, the php that each requires
     * @param array<string, string>  $require  composer.json's `require`
     * @param ?string                $installedPhp the lock entry's `require.php`
     */
    private static function capped(array $releases, ?string $admits, ?string $branch, string $target = '8.4', ?string $projectPhp = null, array $require = [], string $installed = '4.7.1', bool $read = true, ?string $installedPhp = null, bool $excludesTarget = false): MoveDraft
    {
        $packages = [];
        foreach ($releases as $version => $php) {
            $release = (new ArrayLoader())->load(['name' => 'a/b', 'version' => (string) $version, 'time' => '2025-01-01T00:00:00+00:00', 'require' => ['php' => $php]]);
            self::assertInstanceOf(BasePackage::class, $release);
            $packages[] = $release;
        }
        $lock = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => $installed, 'notification-url' => 'https://packagist.org/downloads/'] + ($installedPhp === null ? [] : ['require' => ['php' => $installedPhp]])]]);
        $locked = $lock->find('a/b');
        self::assertNotNull($locked);
        $facts = new PackageFacts($locked, $read ? PackageMetadata::fromPackages('a/b', $packages, new \DateTimeImmutable(self::NOW), $installed) : null, null, []);
        $floor = new PhpFloor($target, $projectPhp);
        $s11 = new Signal(Signal::LEVEL_WARN, 'capped', SignalDataStub::of(Signal::S11, ['excludes_target' => $excludesTarget, 'admits_newest' => $admits === null ? null : ['version' => $admits, 'branch' => $branch, 'blocking' => $floor->blocking($releases[$admits])]]));
        $finding = (new FindingBuilder())->withPackage('a/b')->withVersion($installed)->withSignals([$s11])->withFlags(FlagSet::fromSignals([$s11], null, []), $read)->build();
        $context = new MoveContext($finding, new MoveSubject('a/b', $installed, true, [], $target, $floor->lowestAsString()), $facts->metadata(), BranchRow::all($facts, $floor, Clock::fixed(self::NOW)), null, new FixFinder($floor, LinkIndex::of($lock, ProjectConfig::fromArray(['require' => $require]))), $floor, new \DateTimeImmutable(self::NOW), 2, null, [], [], true);

        return MaintenanceMove::draft($context, FlagSet::CAPPED);
    }
}
