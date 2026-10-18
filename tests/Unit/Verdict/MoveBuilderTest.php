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
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\UncheckedMoves;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Move;
use Lockrot\Verdict\MoveBuilder;
use Lockrot\Verdict\MoveChecks;
use Lockrot\Verdict\MoveContext;
use Lockrot\Verdict\MoveDraft;
use Lockrot\Verdict\MoveSubject;
use PHPUnit\Framework\TestCase;

final class MoveBuilderTest extends TestCase
{
    private const NOW = '2026-10-08T00:00:00+00:00';

    public function testARequireMoveToARowWithNoReleaseReadIsNotCheckedForThatReason(): void
    {
        $row = self::row();
        $finding = (new FindingBuilder())->withPackage('a/b')->withVersion('1.0.0')->build();
        $lock = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0']]]);
        $context = (new MoveContext($finding, new MoveSubject('a/b', '1.0.0', true, [], '8.4', null), null, [$row], null, new FixFinder(new PhpFloor('8.4'), LinkIndex::of($lock, ProjectConfig::empty())), new PhpFloor('8.4'), new \DateTimeImmutable(self::NOW), 2, null, [], [], true))
            ->withChecks(MoveChecks::of(new UncheckedMoves(MoveCheck::OFFLINE)));

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::LEFT_BEHIND)->to($row, null, '^2.0'), [], null, $context);
        $check = $move->check();

        self::assertNull($move->version());
        self::assertNotNull($check);
        self::assertSame(['result' => MoveCheck::NOT_CHECKED, 'form' => null, 'reason' => MoveCheck::NO_RELEASE_READ], $check->jsonSerialize());
    }

    private static function row(): BranchRow
    {
        $release = (new ArrayLoader())->load(['name' => 'a/b', 'version' => '2.0.0', 'time' => '2026-01-01T00:00:00+00:00']);
        self::assertInstanceOf(BasePackage::class, $release);
        $locked = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]])->find('a/b');
        self::assertNotNull($locked);
        $facts = new PackageFacts($locked, PackageMetadata::fromPackages('a/b', [$release], new \DateTimeImmutable(self::NOW), '1.0.0.0'), null, []);

        return BranchRow::all($facts, new PhpFloor('8.4'), Clock::fixed(self::NOW))[0];
    }
}
