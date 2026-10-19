<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Security\FixFinder;
use Lockrot\Security\LinkIndex;
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

    private static function context(): MoveContext
    {
        $finding = (new FindingBuilder())->withPackage('a/b')->withVersion('1.0.0')->build();
        $lock = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0']]]);

        return new MoveContext($finding, new MoveSubject('a/b', '1.0.0', true, [], '8.4', null), null, [], null, new FixFinder(new PhpFloor('8.4'), LinkIndex::of($lock, ProjectConfig::empty())), new PhpFloor('8.4'), new \DateTimeImmutable(self::NOW), 2, null, [], [], true);
    }
}
