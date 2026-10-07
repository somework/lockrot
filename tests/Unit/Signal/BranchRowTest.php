<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Semver\VersionParser;
use Lockrot\Clock;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockedPackage;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Security\Fix;
use Lockrot\Security\FixFinder;
use Lockrot\Security\LinkIndex;
use Lockrot\Signal\BranchRow;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Signal;
use PHPUnit\Framework\TestCase;

/** The details block's branch rows, as values: report-1 writes none of the new keys. */
final class BranchRowTest extends TestCase
{
    private const NOW = '2026-09-14T00:00:00+00:00';

    public function testEachRowCarriesItsFloorAnswersAndTheAgeOfItsNewestRelease(): void
    {
        $floor = new PhpFloor('8.2', '>=8.2');
        $rows = BranchRow::all($this->facts(), $floor, Clock::fixed(self::NOW));

        self::assertSame(['3.x', '2.x'], array_map(static fn (BranchRow $row): string => $row->branch(), $rows));
        [$three, $two] = $rows;
        self::assertSame('v3.1.0', $three->highest());
        self::assertSame('8.2.0', $three->php());
        self::assertTrue($three->admitsProjectPhp(), 'the floor point: >=8.2 admits a branch that needs 8.2.0');
        self::assertTrue($three->admitsTargetPhp());
        self::assertNull($three->phpBlockedBy());
        self::assertNull($three->missesProjectPhp());
        self::assertSame(1.0, $three->releasedYears());
        self::assertTrue($two->installed());
        self::assertSame('>=7.1', $two->php());
        self::assertTrue($two->admitsProjectPhp());
        self::assertSame(3.7, $two->releasedYears());
        foreach ($rows as $row) {
            self::assertSame($floor->admitsProject($row->php()), $row->admitsProjectPhp());
            self::assertSame($floor->missesProject($row->php()), $row->missesProjectPhp());
            self::assertSame($floor->admitsTarget($row->php()), $row->admitsTargetPhp());
            self::assertSame($floor->missesTarget($row->php()), $row->missesTargetPhp());
            self::assertSame($floor->blocking($row->php()), $row->phpBlockedBy());
            self::assertNull($row->fixes(), 'no release scan was given');
        }
    }

    /** With no trusted date on its highest tag, a row takes S8's date for the same branch. */
    public function testAnUndatedBranchTakesS8sDateForTheSameBranch(): void
    {
        $shared = ['type' => 'git', 'url' => 'https://example.org/a/b.git', 'reference' => 'same'];
        $versions = [
            self::release(['version' => 'v1.0.0', 'time' => '2019-01-01T00:00:00+00:00']),
            self::release(['version' => 'v2.0.0', 'time' => '2025-09-14T00:00:00+00:00', 'source' => $shared]),
            self::release(['version' => 'v2.0.1', 'time' => '2025-09-14T00:00:00+00:00', 'source' => $shared]),
            self::release(['version' => 'v2.0.2', 'time' => '2025-09-14T00:00:00+00:00', 'source' => $shared]),
        ];
        $facts = new PackageFacts(self::locked('v1.0.0'), PackageMetadata::fromPackages('a/b', $versions, new \DateTimeImmutable(self::NOW), '1.0.0.0'), null);
        $s8 = new Signal(Signal::S8, Signal::LEVEL_HIGH, 'x', ['newest_branch' => '2.x', 'newest_release' => '2025-09-14T00:00:00+00:00']);
        $clock = Clock::fixed(self::NOW);

        $rows = BranchRow::all($facts, new PhpFloor('8.4'), $clock, $s8);
        self::assertNull($rows[0]->highestReleased());
        self::assertSame(1.0, $rows[0]->releasedYears());
        self::assertSame(7.7, $rows[1]->releasedYears());

        self::assertNull(BranchRow::all($facts, new PhpFloor('8.4'), $clock)[0]->releasedYears(), 'no S8, no date');
        $other = new Signal(Signal::S8, Signal::LEVEL_HIGH, 'x', ['newest_branch' => '3.x', 'newest_release' => '2025-09-14T00:00:00+00:00']);
        self::assertNull(BranchRow::all($facts, new PhpFloor('8.4'), $clock, $other)[0]->releasedYears(), 'S8 dates another branch');
    }

    public function testEachRowTakesTheReleaseScansFixesForItsBranch(): void
    {
        $facts = $this->facts([new Advisory('A', null, null, null, 'high', null, (new VersionParser())->parseConstraints('<3.0.0'))]);
        $floor = new PhpFloor('8.2', '>=8.2');
        $fixes = (new FixFinder($floor, LinkIndex::of(LockFile::fromArray([]), ProjectConfig::empty())))->find($facts);

        $rows = BranchRow::all($facts, $floor, Clock::fixed(self::NOW), null, $fixes);

        $three = $rows[0]->fixes();
        self::assertNotNull($three);
        self::assertSame([1, 'v3.0.0', Fix::UPDATE], [$three->fixed(), $three->lowest(), $three->fixKind()]);
        $two = $rows[1]->fixes();
        self::assertNotNull($two);
        self::assertSame(0, $two->fixed());
    }

    public function testNoMetadataGivesNoRows(): void
    {
        self::assertSame([], BranchRow::all(new PackageFacts(self::locked('v2.0.0'), null, null), new PhpFloor('8.4'), Clock::fixed(self::NOW)));
    }

    /** @param list<Advisory> $advisories */
    private function facts(array $advisories = []): PackageFacts
    {
        $versions = [
            self::release(['version' => 'v2.0.0', 'time' => '2023-01-01T00:00:00+00:00', 'require' => ['php' => '>=7.1']]),
            self::release(['version' => 'v3.0.0', 'time' => '2025-01-01T00:00:00+00:00', 'require' => ['php' => '8.2.0']]),
            self::release(['version' => 'v3.1.0', 'time' => '2025-09-14T00:00:00+00:00', 'require' => ['php' => '8.2.0']]),
        ];

        return new PackageFacts(self::locked('v2.0.0'), PackageMetadata::fromPackages('a/b', $versions, new \DateTimeImmutable(self::NOW), '2.0.0.0'), null, $advisories);
    }

    private static function locked(string $version): LockedPackage
    {
        $locked = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => $version, 'notification-url' => 'https://packagist.org/downloads/']]])->find('a/b');
        self::assertNotNull($locked);

        return $locked;
    }

    /** @param array<string, mixed> $config */
    private static function release(array $config): BasePackage
    {
        $package = (new ArrayLoader())->load(['name' => 'a/b'] + $config);
        self::assertInstanceOf(BasePackage::class, $package);

        return $package;
    }
}
