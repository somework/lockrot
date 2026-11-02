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
use Lockrot\Verdict\MoveChecks;
use Lockrot\Verdict\MoveContext;
use Lockrot\Verdict\MoveSubject;
use PHPUnit\Framework\TestCase;

final class MoveContextTest extends TestCase
{
    private const NOW = '2026-10-08T00:00:00+00:00';

    /** The context counts two years back from its now. */
    public function testALineReleasesOnlyAfterTheWarningYearsBeforeNow(): void
    {
        $context = self::context();

        self::assertFalse($context->isReleasing(new \DateTimeImmutable('2024-10-08T00:00:00+00:00')));
        self::assertTrue($context->isReleasing(new \DateTimeImmutable('2024-10-08T00:00:01+00:00')));
        self::assertFalse($context->isReleasing(null));
    }

    public function testWithChecksLeavesTheOriginalContextWithoutChecks(): void
    {
        $context = self::context();

        $checked = $context->withChecks(MoveChecks::of(new UncheckedMoves(MoveCheck::OFFLINE)));

        self::assertNull($context->checks());
        self::assertNotNull($checked->checks());
    }

    public function testAConstraintThatDoesNotParseAdmitsNoReleaseOfARowThatHasOne(): void
    {
        $release = (new ArrayLoader())->load(['name' => 'a/b', 'version' => '2.0.0', 'time' => '2026-01-01T00:00:00+00:00']);
        self::assertInstanceOf(BasePackage::class, $release);
        $locked = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]])->find('a/b');
        self::assertNotNull($locked);
        $metadata = PackageMetadata::fromPackages('a/b', [$release], new \DateTimeImmutable(self::NOW), '1.0.0.0');
        $row = BranchRow::all(new PackageFacts($locked, $metadata, null, []), new PhpFloor('8.4'), Clock::fixed(self::NOW))[0];

        self::assertSame([null, 'not a constraint'], self::context($metadata)->lowestAdmitted($row, 'not a constraint'));
    }

    private static function context(?PackageMetadata $metadata = null): MoveContext
    {
        $finding = (new FindingBuilder())->withPackage('a/b')->withVersion('1.0.0')->build();
        $lock = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0']]]);

        return new MoveContext($finding, new MoveSubject('a/b', '1.0.0', true, [], '8.4', null), $metadata, [], null, new FixFinder(new PhpFloor('8.4'), LinkIndex::of($lock, ProjectConfig::empty())), new PhpFloor('8.4'), new \DateTimeImmutable(self::NOW), 2, null, [], [], true);
    }
}
