<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Clock;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Security\BranchFixes;
use Lockrot\Security\FixFinder;
use Lockrot\Security\LinkIndex;
use Lockrot\Security\PackageFixes;
use Lockrot\Signal\BranchRow;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\RecordedMoveChecker;
use Lockrot\Verdict\MoveChecks;
use Lockrot\Verdict\MoveContext;
use Lockrot\Verdict\MoveSubject;
use Lockrot\Verdict\NextStep;
use PHPUnit\Framework\TestCase;

/** a/b 1.0.0 in the lock, with a 2.x line, and a finding that names no move of its own. */
final class NextStepRowsTest extends TestCase
{
    private const NOW = '2026-10-08T00:00:00+00:00';

    /** The installed branch fixes an advisory with no candidate, and the 2.x candidate fixes none: neither row names a move. */
    public function testARowThatFixesNoAdvisoryOrHasNoCandidateGetsNoScoreAndNoCheck(): void
    {
        [$rows, $metadata] = self::rows();
        $plain = self::context($rows, $metadata, null);
        [$candidate] = $plain->lowestAdmitted($rows[0], '^2.0');
        $fixes = new PackageFixes([], [new BranchFixes('2.x', false, 0, 1, $candidate, '2.0.0', []), BranchFixes::installedFromRows('1.x', ['GHSA-1'], 0, 1)], null, null, null);

        $moves = NextStep::plan(self::context($rows, $metadata, $fixes), []);

        self::assertSame([null, null, null, null], [$moves->rowIfApplied('2.x'), $moves->rowCheck('2.x'), $moves->rowIfApplied('1.x'), $moves->rowCheck('1.x')]);
    }

    /** @return array{0: list<BranchRow>, 1: PackageMetadata} */
    private static function rows(): array
    {
        $releases = [];
        foreach (['1.0.0' => '2025-01-01', '2.0.0' => '2026-01-01'] as $version => $at) {
            $release = (new ArrayLoader())->load(['name' => 'a/b', 'version' => $version, 'time' => $at.'T00:00:00+00:00']);
            self::assertInstanceOf(BasePackage::class, $release);
            $releases[] = $release;
        }
        $locked = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]])->find('a/b');
        self::assertNotNull($locked);
        $facts = new PackageFacts($locked, PackageMetadata::fromPackages('a/b', $releases, new \DateTimeImmutable(self::NOW), '1.0.0.0'), null, []);
        $metadata = $facts->metadata();
        self::assertNotNull($metadata);

        return [BranchRow::all($facts, new PhpFloor('8.4'), Clock::fixed(self::NOW)), $metadata];
    }

    /** @param list<BranchRow> $rows */
    private static function context(array $rows, PackageMetadata $metadata, ?PackageFixes $fixes): MoveContext
    {
        $finding = (new FindingBuilder())->withPackage('a/b')->withVersion('1.0.0')->build();
        $lock = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0']]]);

        return (new MoveContext($finding, new MoveSubject('a/b', '1.0.0', true, [], '8.4', null), $metadata, $rows, $fixes, new FixFinder(new PhpFloor('8.4'), LinkIndex::of($lock, ProjectConfig::empty())), new PhpFloor('8.4'), new \DateTimeImmutable(self::NOW), 2, null, [], [], true))
            ->withChecks(new MoveChecks(new RecordedMoveChecker(['*' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]], []), true));
    }
}
