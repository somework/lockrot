<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Clock;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Data\Repository\StableRelease;
use Lockrot\Lock\LockFile;
use Lockrot\Security\Candidate;
use Lockrot\Security\Fix;
use Lockrot\Signal\BranchRow;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Move;
use Lockrot\Verdict\MoveDraft;
use Lockrot\Verdict\NextStep;
use PHPUnit\Framework\TestCase;

final class NextStepTest extends TestCase
{
    private const NOW = '2026-10-08T00:00:00+00:00';

    public function testAMergeKeepsTheLeadReleaseWhenTheOtherMoveNamesNoRelease(): void
    {
        $row = self::row();
        $candidate = new Candidate(new StableRelease('2.0.0.0', '2.0.0', null, null, false), '2.x', Fix::UPGRADE, []);
        $security = MoveDraft::of(Move::REQUIRE, FlagSet::VULNERABLE)->to($row, $candidate, '^2.0.0')->withFixKind(Fix::UPGRADE);
        $maintenance = MoveDraft::of(Move::REQUIRE, FlagSet::LEFT_BEHIND)->to($row, null, '^2.5');

        $merged = NextStep::merge($security, $maintenance);
        $lower = $merged->lower();

        self::assertNotNull($lower);
        self::assertSame(['2.0.0', Move::REQUIRE, '^2.0.0'], [$lower->release()->pretty(), $merged->kind(), $merged->constraint()]);
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
