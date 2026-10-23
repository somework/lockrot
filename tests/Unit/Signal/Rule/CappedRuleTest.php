<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Rule;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockFile;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpCap;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Rule\CappedRule;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;

/** S11, the PHP cap: docs/verdicts.md#capped. */
final class CappedRuleTest extends TestCase
{
    private const NOW = '2026-10-08T00:00:00+00:00';
    private const KNOWN = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

    /** php-http/guzzle5-adapter 2.0.0 on wallabag 2.6.14: `^7.0` stops at 7.4, and nothing above it admits 8.5. */
    public function testACapBelowTheNewestKnownMinorFiresWithItsData(): void
    {
        $signal = self::rule()->evaluate(self::facts('2.0.0', '^7.0', [['2.0.0', '^7.0']]));

        self::assertNotNull($signal);
        self::assertSame([Signal::S11, Signal::LEVEL_WARN], [$signal->id(), $signal->level()]);
        self::assertSame('php "^7.0" stops at 7.4; the newest PHP, 8.5, is excluded', $signal->summary());
        self::assertSame(['newest_php' => '8.5', 'newest_php_source' => 'known', 'requires' => '^7.0', 'stops_at' => '7.4', 'excludes_target' => true, 'admits_newest' => null], JsonPath::decoded($signal->data()));
    }

    public function testARootCapBelowTheNewestKnownMinorSetsTheNewestPhp(): void
    {
        $signal = self::rule(new PhpCap(self::KNOWN, '~8.1.0 || ~8.2.0 || ~8.3.0'))->evaluate(self::facts('1.0.0', '~8.1.0 || ~8.2.0'));

        self::assertNotNull($signal);
        self::assertSame('php "~8.1.0 || ~8.2.0" stops at 8.2; 8.3, the newest PHP that require.php allows, is excluded', $signal->summary());
        self::assertSame(['8.3', 'require_php'], [JsonPath::stringAt(JsonPath::decoded($signal->data()), ['newest_php']), JsonPath::stringAt(JsonPath::decoded($signal->data()), ['newest_php_source'])]);
    }

    public function testTheLocksPlatformPhpSetsTheNewestPhpWithoutARequirePhp(): void
    {
        $signal = self::rule(new PhpCap(self::KNOWN, null, '~8.1.0 || ~8.2.0 || ~8.3.0'))->evaluate(self::facts('1.0.0', '~8.1.0 || ~8.2.0'));

        self::assertNotNull($signal);
        self::assertSame('php "~8.1.0 || ~8.2.0" stops at 8.2; 8.3, the newest PHP that the lock\'s platform allows, is excluded', $signal->summary());
    }

    public function testARootPhpThatDoesNotParseRaisesNoCap(): void
    {
        self::assertNull(self::rule(new PhpCap(self::KNOWN, 'not a constraint'))->evaluate(self::facts('2.0.0', '^7.0')));
    }

    /** symfony/string v8.1.2 in the laminas skeleton: `>=8.4.1` lies above R, so it is no cap. */
    public function testAFloorAboveTheNewestPhpRaisesNoCap(): void
    {
        self::assertNull(self::rule(new PhpCap(self::KNOWN, '~8.1.0 || ~8.2.0 || ~8.3.0'))->evaluate(self::facts('8.1.2', '>=8.4.1')));
    }

    public function testADisjunctionWithAnOpenEndRaisesNoCap(): void
    {
        self::assertNull(self::rule()->evaluate(self::facts('1.0.0', '~7.4.0 || >=8.1')));
    }

    public function testAPackageThatStatesNoPhpRaisesNoCap(): void
    {
        self::assertNull(self::rule()->evaluate(self::facts('1.0.0', null)));
    }

    /** `excludes_target` is the target's minor test, as `installed_php.target_runs` false. */
    public function testExcludesTargetIsTrueExactlyWhenTheTargetMinorIsOutsideTheRequirement(): void
    {
        $outside = self::rule()->evaluate(self::facts('1.0.0', '>=7.4, <8.2'));
        $inside = self::rule()->evaluate(self::facts('1.0.0', '~8.3.0 || ~8.4.0'));

        self::assertNotNull($outside);
        self::assertNotNull($inside);
        self::assertTrue(JsonPath::decoded($outside->data())['excludes_target']);
        self::assertFalse(JsonPath::decoded($inside->data())['excludes_target']);
    }

    public function testTheNewestReleaseThatAdmitsTheNewestPhpOnTheInstalledBranchIsAdmitsNewest(): void
    {
        $signal = self::rule()->evaluate(self::facts('4.7.1', '>=7.4, <8.2', [['4.7.1', '>=7.4, <8.2'], ['4.16.0', '~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0'], ['4.17.0', '~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0'], ['5.0.0', '~8.3.0 || ~8.4.0 || ~8.5.0']]));

        self::assertNotNull($signal);
        self::assertSame(['version' => '4.17.0', 'branch' => '4.x', 'blocking' => null], JsonPath::decoded($signal->data())['admits_newest']);
    }

    /** The lowest branch with a release that admits R, then its newest such release. */
    public function testWithNoReleaseOnTheInstalledBranchTheLowestHigherBranchIsAdmitsNewest(): void
    {
        $signal = self::rule()->evaluate(self::facts('4.7.1', '>=7.4, <8.2', [['4.7.1', '>=7.4, <8.2'], ['4.17.0', '~8.2.0 || ~8.3.0 || ~8.4.0'], ['5.0.0', '~8.3.0 || ~8.4.0 || ~8.5.0'], ['5.1.0', '~8.3.0 || ~8.4.0 || ~8.5.0'], ['6.0.0', '>=8.4']]));

        self::assertNotNull($signal);
        self::assertSame(['version' => '5.1.0', 'branch' => '5.x', 'blocking' => null], JsonPath::decoded($signal->data())['admits_newest']);
    }

    /** Composer installs a branch's newest release: 4.18.0 caps php at 8.4 again, so 4.x is no answer (case `capped-recapped‡`). */
    public function testABranchWhoseNewestReleaseCapsPhpAgainGivesWayToTheNextBranch(): void
    {
        $signal = self::rule()->evaluate(self::facts('4.7.1', '>=7.4, <8.2', [['4.7.1', '>=7.4, <8.2'], ['4.17.0', '~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0'], ['4.18.0', '~8.2.0 || ~8.3.0 || ~8.4.0'], ['5.0.0', '~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0']]));

        self::assertNotNull($signal);
        self::assertSame(['version' => '5.0.0', 'branch' => '5.x', 'blocking' => null], JsonPath::decoded($signal->data())['admits_newest']);
    }

    public function testWithNoHigherBranchAnOlderReleaseThatAdmitsTheNewestPhpUnderARecapIsNoAnswer(): void
    {
        $signal = self::rule()->evaluate(self::facts('4.7.1', '>=7.4, <8.2', [['4.17.0', '~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0'], ['4.18.0', '~8.2.0 || ~8.3.0 || ~8.4.0']]));

        self::assertNotNull($signal);
        self::assertNull(JsonPath::decoded($signal->data())['admits_newest']);
    }

    public function testABranchWhoseNewestReleaseStatesNoPhpGivesWayToTheNextBranch(): void
    {
        $signal = self::rule()->evaluate(self::facts('4.7.1', '>=7.4, <8.2', [['4.17.0', '~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0'], ['4.18.0', null], ['5.0.0', '~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0']]));

        self::assertNotNull($signal);
        self::assertSame('5.0.0', JsonPath::stringAt(JsonPath::decoded($signal->data()), ['admits_newest', 'version']));
    }

    public function testNoReleaseAboveTheInstalledOneAdmitsTheNewestPhpAndOneBelowDoesNotCount(): void
    {
        $signal = self::rule()->evaluate(self::facts('2.0.0', '^7.0', [['0.6.0', '>=5.4'], ['1.0.0', '>=5.5'], ['2.0.0', '^7.0'], ['2.1.0', '^7.0'], ['2.2.0', null]]));

        self::assertNotNull($signal);
        self::assertNull(JsonPath::decoded($signal->data())['admits_newest']);
    }

    public function testTheProjectsLowestPhpOutsideTheReleaseBlocksItAsProject(): void
    {
        $signal = self::rule(null, new PhpFloor('8.4', '>=7.4'))->evaluate(self::facts('4.7.1', '>=7.4, <8.2', [['4.17.0', '~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0']]));

        self::assertNotNull($signal);
        self::assertSame('project', JsonPath::stringAt(JsonPath::decoded($signal->data()), ['admits_newest', 'blocking']));
    }

    public function testATargetOutsideTheReleaseBlocksItAsTarget(): void
    {
        $signal = self::rule(null, new PhpFloor('7.4'))->evaluate(self::facts('4.7.1', '>=7.4, <8.2', [['4.17.0', '~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0']]));

        self::assertNotNull($signal);
        self::assertSame('target', JsonPath::stringAt(JsonPath::decoded($signal->data()), ['admits_newest', 'blocking']));
    }

    /** Above an `X.Y.x-dev` snapshot the releases count from `X.Y.0`: 4.6.0 is a downgrade. */
    public function testAboveABranchAliasSnapshotTheReleasesCountFromItsLine(): void
    {
        $signal = self::rule()->evaluate(self::facts('4.7.x-dev', '>=7.4, <8.2', [['4.6.0', '>=8.1'], ['4.7.0', '>=7.4, <8.2'], ['4.17.0', '~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0']]));

        self::assertNotNull($signal);
        self::assertSame('4.17.0', JsonPath::stringAt(JsonPath::decoded($signal->data()), ['admits_newest', 'version']));
    }

    /** Above a `dev-<name>` snapshot the releases count from the lock entry's `time`. */
    public function testAboveADevSnapshotTheReleasesDatedAfterTheLockEntryCount(): void
    {
        $releases = [['3.0.0', '>=8.1', '2024-01-01'], ['3.1.0', '~8.2.0 || ~8.3.0', '2024-08-01'], ['3.2.0', '~8.4.0 || ~8.5.0', '2025-03-01']];

        $before = self::rule()->evaluate(self::facts('dev-main', '>=8.1, <8.3', $releases, '2024-06-01T00:00:00+00:00'));
        $after = self::rule()->evaluate(self::facts('dev-main', '>=8.1, <8.3', $releases, '2025-06-01T00:00:00+00:00'));

        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertSame('3.2.0', JsonPath::stringAt(JsonPath::decoded($before->data()), ['admits_newest', 'version']));
        self::assertNull(JsonPath::decoded($after->data())['admits_newest'], 'every release that admits 8.5 predates the snapshot');
    }

    /** 4.6.0 lies below the 4.7 line of a `4.7.x-dev` snapshot, so it is no move up even though it admits 8.5. */
    public function testAboveABranchAliasSnapshotAReleaseBelowItsLineDoesNotCount(): void
    {
        $signal = self::rule()->evaluate(self::facts('4.7.x-dev', '>=7.4, <8.2', [['4.6.0', '>=8.1'], ['4.7.0', '>=7.4, <8.2']]));

        self::assertNotNull($signal);
        self::assertNull(JsonPath::decoded($signal->data())['admits_newest']);
    }

    public function testAboveABranchAliasSnapshotAPatchOfItsLineCounts(): void
    {
        $signal = self::rule()->evaluate(self::facts('4.7.x-dev', '>=7.4, <8.2', [['4.7.5', '~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0']]));

        self::assertNotNull($signal);
        self::assertSame('4.7.5', JsonPath::stringAt(JsonPath::decoded($signal->data()), ['admits_newest', 'version']));
    }

    /** Above a `4.x-dev` snapshot the releases count from 4.0.0. */
    public function testAboveAMajorSnapshotTheReleasesCountFromItsMajor(): void
    {
        $signal = self::rule()->evaluate(self::facts('4.x-dev', '>=7.4, <8.2', [['3.9.0', '>=8.1'], ['4.5.0', '>=8.1']]));

        self::assertNotNull($signal);
        self::assertSame('4.5.0', JsonPath::stringAt(JsonPath::decoded($signal->data()), ['admits_newest', 'version']));
    }

    /** A `dev-main` snapshot with a `4.7.x-dev` branch alias counts from the alias's line, not from its time. */
    public function testAboveADevSnapshotWithABranchAliasTheReleasesCountFromTheAliasLine(): void
    {
        $signal = self::rule()->evaluate(self::facts('dev-main', '>=7.4, <8.2', [['4.6.0', '>=8.1'], ['4.17.0', '>=8.1']], null, ['branch-alias' => ['dev-main' => '4.7.x-dev']]));

        self::assertNotNull($signal);
        self::assertSame('4.17.0', JsonPath::stringAt(JsonPath::decoded($signal->data()), ['admits_newest', 'version']));
    }

    /** A `dev-<name>` snapshot sits at or above 4.x, the branch of 4.13.0, so the 3.6.0 backport is a downgrade (case `capped-snapshot-backport‡`). */
    public function testAboveADevSnapshotABackportBelowTheBranchOfTheNewestEarlierReleaseDoesNotCount(): void
    {
        $signal = self::rule()->evaluate(self::facts('dev-main', '>=7.4, <8.2', [['3.6.0', '>=7.4', '2025-02-01'], ['4.13.0', '>=7.4, <8.2', '2024-03-01'], ['4.17.0', '~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0', '2025-05-01']], '2024-06-01T00:00:00+00:00'));

        self::assertNotNull($signal);
        self::assertSame(['version' => '4.17.0', 'branch' => '4.x', 'blocking' => null], JsonPath::decoded($signal->data())['admits_newest']);
    }

    /** 3.5.0 is dated after 4.13.0, but the higher version sets the snapshot's branch. */
    public function testAboveADevSnapshotTheHighestEarlierReleaseSetsTheBranch(): void
    {
        $signal = self::rule()->evaluate(self::facts('dev-main', '>=7.4, <8.2', [['3.5.0', '>=7.4, <8.2', '2024-05-01'], ['3.6.0', '>=7.4', '2025-02-01'], ['4.13.0', '>=7.4, <8.2', '2024-03-01'], ['4.17.0', '~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0', '2025-05-01']], '2024-06-01T00:00:00+00:00'));

        self::assertNotNull($signal);
        self::assertSame('4.17.0', JsonPath::stringAt(JsonPath::decoded($signal->data()), ['admits_newest', 'version']));
    }

    public function testAboveADevSnapshotWithNoEarlierReleaseEveryLaterReleaseCounts(): void
    {
        $signal = self::rule()->evaluate(self::facts('dev-main', '>=7.4, <8.2', [['3.6.0', '>=7.4', '2025-02-01'], ['4.17.0', '~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0', '2025-05-01']], '2024-06-01T00:00:00+00:00'));

        self::assertNotNull($signal);
        self::assertSame('3.6.0', JsonPath::stringAt(JsonPath::decoded($signal->data()), ['admits_newest', 'version']));
    }

    public function testAboveADevSnapshotWithoutATimeNoReleaseCounts(): void
    {
        $signal = self::rule()->evaluate(self::facts('dev-main', '>=8.1, <8.3', [['3.2.0', '~8.4.0 || ~8.5.0', '2025-03-01']]));

        self::assertNotNull($signal);
        self::assertNull(JsonPath::decoded($signal->data())['admits_newest']);
    }

    public function testAReleaseDatedAtTheSnapshotsOwnTimeDoesNotCount(): void
    {
        $signal = self::rule()->evaluate(self::facts('dev-main', '>=8.1, <8.3', [['3.2.0', '~8.4.0 || ~8.5.0', '2025-03-01']], '2025-03-01T00:00:00+00:00'));

        self::assertNotNull($signal);
        self::assertNull(JsonPath::decoded($signal->data())['admits_newest']);
    }

    public function testUnreadMetadataLeavesAdmitsNewestNull(): void
    {
        $signal = self::rule()->evaluate(self::facts('4.7.1', '>=7.4, <8.2'));

        self::assertNotNull($signal);
        self::assertNull(JsonPath::decoded($signal->data())['admits_newest']);
    }

    private static function rule(?PhpCap $cap = null, ?PhpFloor $floor = null): CappedRule
    {
        return new CappedRule($cap ?? new PhpCap(self::KNOWN), $floor ?? new PhpFloor('8.4'));
    }

    /**
     * a/b at $version with $php, beside its releases. Null releases leave the metadata unread.
     *
     * @param list<array{0: string, 1: ?string, 2?: string}>|null $releases [version, require.php, date]
     * @param array<string, mixed>                                $extra    the lock entry's `extra`
     */
    private static function facts(string $version, ?string $php, ?array $releases = null, ?string $time = null, array $extra = []): PackageFacts
    {
        $entry = ['name' => 'a/b', 'version' => $version, 'notification-url' => 'https://packagist.org/downloads/'] + ($php === null ? [] : ['require' => ['php' => $php]]) + ($time === null ? [] : ['time' => $time]) + ($extra === [] ? [] : ['extra' => $extra]);
        $locked = LockFile::fromArray(['packages' => [$entry]])->find('a/b');
        self::assertNotNull($locked);
        if ($releases === null) {
            return new PackageFacts($locked, null, null, []);
        }
        $packages = [];
        foreach ($releases as $release) {
            $package = (new ArrayLoader())->load(['name' => 'a/b', 'version' => $release[0], 'time' => ($release[2] ?? '2024-01-01').'T00:00:00+00:00'] + ($release[1] === null ? [] : ['require' => ['php' => $release[1]]]));
            self::assertInstanceOf(BasePackage::class, $package);
            $packages[] = $package;
        }

        return new PackageFacts($locked, PackageMetadata::fromPackages('a/b', $packages, new \DateTimeImmutable(self::NOW), $version), null, []);
    }
}
