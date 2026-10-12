<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Clock;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockFile;
use Lockrot\Security\Fix;
use Lockrot\Signal\BranchRow;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Latest;
use Lockrot\Verdict\Move;
use Lockrot\Verdict\MoveDraft;
use Lockrot\Verdict\Replacement;
use PHPUnit\Framework\TestCase;

final class MoveDraftTest extends TestCase
{
    private const NOW = '2026-10-08T00:00:00+00:00';

    public function testEveryCopyLeavesTheDraftItCameFrom(): void
    {
        $draft = MoveDraft::of(Move::REQUIRE, FlagSet::LEFT_BEHIND);
        $row = self::row();

        $copies = [
            $draft->to($row, null, '^2.0'),
            $draft->withFixKind(Fix::UPGRADE),
            $draft->withReason('no_higher_release'),
            $draft->withReplacement(new Replacement('a/b', 'c/d', 'repository', null)),
            $draft->withLatest(new Latest('2.0.0', null, false, null, null), $row),
        ];

        self::assertSame([null, null, null, null, null, null], [$draft->row(), $draft->constraint(), $draft->fixKind(), $draft->reason(), $draft->replacement(), $draft->latest()]);
        self::assertSame(['^2.0', Fix::UPGRADE, 'no_higher_release', 'c/d', '2.0.0'], [$copies[0]->constraint(), $copies[1]->fixKind(), $copies[2]->reason(), $copies[3]->replacement() === null ? null : $copies[3]->replacement()->suggestion(), $copies[4]->latest() === null ? null : $copies[4]->latest()->version()]);
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
