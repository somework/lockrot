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
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\MaintenanceMove;
use Lockrot\Verdict\MoveContext;
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
}
