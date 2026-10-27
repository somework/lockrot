<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Security;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Data\Repository\StableRelease;
use Lockrot\Lock\LockedPackage;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Security\BranchFixes;
use Lockrot\Security\Candidate;
use Lockrot\Security\Fix;
use Lockrot\Security\FixFinder;
use Lockrot\Security\Holder;
use Lockrot\Security\LinkIndex;
use Lockrot\Security\PackageFixes;
use Lockrot\Security\RefusedMoves;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FixFinderTest extends TestCase
{
    private const NOW = '2026-09-14T00:00:00+00:00';
    private const PACKAGIST = 'https://packagist.org/downloads/';

    /** The release scan compares two ranges with it, on every Composer version that lockrot supports. */
    public function testComposersIntervalsCanCompareTwoRanges(): void
    {
        self::assertTrue((new \ReflectionClass(Intervals::class))->hasMethod('haveIntersections'));
    }

    public function testEveryOtherVersionRangeRaisesTheWindow(): void
    {
        $counted = self::advisory('A', '<1.2.0');

        self::assertCount(2, FixFinder::otherVersionRanges([$counted], [$counted, self::advisory('B', '>=2.0'), self::advisory('C', '>=3.0')]));
    }

    /**
     * phpBB: the root requires twig ^2.0, and symfony/twig-bridge v3.4.47 requires ^1.41|^2.10.
     * Only 3.x fixes the advisories, and 3.x needs php >=8.1 above the project's 7.2.
     */
    public function testTwigInPhpbbIsARaisePhpHeldByTheRootAndByTwigBridge(): void
    {
        $fixes = $this->twigInPhpbb();

        foreach (['PKSA-8zx5', 'PKSA-fbvq'] as $id) {
            $fix = $fixes->forAdvisory($id);
            self::assertSame(Fix::RAISE_PHP, $fix->kind(), $id);
            self::assertSame('3.x', $fix->toBranch());
            self::assertSame('v3.27.0', $fix->version(), 'the lowest release outside the range: <=3.26.0 names no exclusive bound');
            self::assertSame('v3.28.0', $fix->newest());
            self::assertFalse($fix->onInstalledBranch());
            self::assertSame('>=8.1', $fix->php());
            self::assertNull($fix->reason());
            self::assertSame([
                ['source' => Holder::ROOT, 'package' => 'phpbb/phpbb', 'version' => null, 'link' => Holder::REQUIRE, 'constraint' => '^2.0'],
                ['source' => Holder::PACKAGE, 'package' => 'symfony/twig-bridge', 'version' => 'v3.4.47', 'link' => Holder::REQUIRE, 'constraint' => '^1.41|^2.10'],
            ], self::holders($fix->heldBy()));
        }
    }

    /** Every branch keeps its own way out, the installed one and the lower one included. */
    public function testTwigInPhpbbKeepsARowPerBranch(): void
    {
        $rows = $this->twigInPhpbb()->branches();

        self::assertSame(['3.x', '2.x', '1.x'], array_map(static fn (BranchFixes $row): string => $row->branch(), $rows));
        [$three, $two, $one] = $rows;

        self::assertFalse($three->installed());
        self::assertSame([2, 0, 2], [$three->fixed(), $three->unknown(), $three->of()]);
        self::assertSame(Fix::RAISE_PHP, $three->fixKind());
        self::assertSame('v3.27.0', $three->lowest());
        self::assertSame('v3.28.0', $three->newest());
        $candidate = $three->candidate();
        self::assertNotNull($candidate);
        self::assertSame(Fix::RAISE_PHP, $candidate->kind());
        self::assertSame('>=8.1', $candidate->php());
        self::assertSame(['phpbb/phpbb', 'symfony/twig-bridge'], array_map(static fn (Holder $holder): ?string => $holder->package(), $candidate->heldBy()));

        self::assertTrue($two->installed());
        self::assertSame([0, 0, 2], [$two->fixed(), $two->unknown(), $two->of()]);
        self::assertNull($two->fixKind());
        self::assertNull($two->lowest());
        self::assertNull($two->candidate());
        self::assertSame('v2.16.1', $two->newest());

        self::assertFalse($one->installed());
        self::assertSame(0, $one->fixed(), 'a branch below the installed one is no candidate');
        self::assertSame('v1.44.8', $one->newest());
        $installed = $this->twigInPhpbb()->installedBranch();
        self::assertNotNull($installed);
        self::assertSame('2.x', $installed->branch());
    }

    public function testTwigInPhpbbHasNoUpdateToGetAndNoPartialFix(): void
    {
        $fixes = $this->twigInPhpbb();

        self::assertNull($fixes->gets(), 'no newer release on the installed branch');
        self::assertNull($fixes->partial());
        $move = $fixes->move();
        self::assertNotNull($move);
        self::assertSame('v3.27.0', $move->release()->pretty());
        self::assertSame(Fix::RAISE_PHP, $move->kind());
    }

    /** prestashop-v1: twig v1.43.1 under require.php >=7.1.3. v1.44.8 needs >=7.2.5. */
    public function testComposerUpdateIgnoresTheProjectFloorAndSaysWhatTheReleaseNeeds(): void
    {
        $fixes = $this->find(
            ['name' => 'twig/twig', 'version' => 'v1.43.1'],
            [
                ['v1.43.1', '>=5.5.0'], ['v1.44.7', '>=7.2.5'], ['v1.44.8', '>=7.2.5'], ['v2.16.1', '>=7.1.3'], ['v3.27.0', '>=8.1'],
            ],
            ['A' => '<1.44.7', 'B' => '<1.44.8', 'C' => '<3.27.0'],
            new PhpFloor('8.4', '>=7.1.3')
        );

        $gets = $fixes->gets();
        self::assertNotNull($gets);
        self::assertSame('v1.44.8', $gets->version());
        self::assertSame(['A', 'B'], $gets->clears());
        self::assertFalse($gets->clearsAll());
        self::assertSame(
            ['requires' => '>=7.2.5', 'project_allows' => false, 'target_runs' => true, 'raise_to' => '>=7.2.5', 'raise_size' => 'minor'],
            $gets->phpCheck()->jsonSerialize()
        );
        self::assertSame(Fix::UPDATE, $fixes->forAdvisory('A')->kind(), 'v2.16.1 needs >=7.1.3, which the project admits: easier than v1.44.7');
        self::assertSame('v2.16.1', $fixes->forAdvisory('A')->version());
        self::assertFalse($fixes->forAdvisory('A')->onInstalledBranch());
        self::assertSame(Fix::RAISE_PHP, $fixes->forAdvisory('C')->kind());
        self::assertSame('v3.27.0', $fixes->forAdvisory('C')->version());
        $one = $fixes->installedBranch();
        self::assertNotNull($one);
        self::assertSame(['1.x', 2, 'v1.44.8', Fix::RAISE_PHP], [$one->branch(), $one->fixed(), $one->lowest(), $one->fixKind()]);
        self::assertNull($fixes->partial(), 'the installed branch\'s newest release is raise-php, not update');
    }

    /** The fix carries the branch rows' two floor answers, asked of its own php, so a page parses no constraint (SPEC §7.9.19). */
    public function testTheFixSaysWhetherTheTargetAndTheProjectFloorAdmitItsPhp(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', '>=7.1'], ['1.1.0', '>=7.1.3'], ['2.0.0', '>=8.1'], ['3.0.0', '~8.0.0']],
            ['free' => '<1.1.0', 'raise' => '<2.0.0', 'blocked' => '<3.0.0'],
            new PhpFloor('8.4', '>=7.1.3')
        );

        $answers = static function (Fix $fix): array {
            $json = $fix->jsonSerialize();

            return [$fix->php(), $json['admits_target_php'], $json['admits_project_php']];
        };
        self::assertSame(['>=7.1.3', true, true], $answers($fixes->forAdvisory('free')));
        self::assertSame(['>=8.1', true, false], $answers($fixes->forAdvisory('raise')));
        self::assertSame(['~8.0.0', false, false], $answers($fixes->forAdvisory('blocked')));
        self::assertSame(
            ['kind', 'to_branch', 'version', 'newest', 'on_installed_branch', 'php', 'admits_target_php', 'admits_project_php', 'held_by', 'reason'],
            array_keys($fixes->forAdvisory('free')->jsonSerialize())
        );
    }

    /** Null is no answer, never admitted: no php on the release, no project floor, or a php Composer cannot parse. */
    public function testAFixWithoutAComparablePhpOrFloorAnswersNull(): void
    {
        $answers = static function (Fix $fix): array {
            $json = $fix->jsonSerialize();

            return [$json['admits_target_php'], $json['admits_project_php']];
        };
        $declaresNone = $this->find(['name' => 'a/b', 'version' => '1.0.0'], [['1.0.0', null], ['1.1.0', null]], ['A' => '<1.1.0'], new PhpFloor('8.4', '>=7.4'));
        self::assertSame([null, null], $answers($declaresNone->forAdvisory('A')));

        $noFloor = $this->find(['name' => 'a/b', 'version' => '1.0.0'], [['1.0.0', '>=7.4'], ['1.1.0', '>=8.1']], ['A' => '<1.1.0'], new PhpFloor('8.4', null));
        self::assertSame([true, null], $answers($noFloor->forAdvisory('A')));

        $unparsable = Fix::to(new Candidate(new StableRelease('1.1.0.0', '1.1.0', null, '>=7.4 <<', false), '1', Fix::UPDATE, []), '1.1.0', '1', new PhpFloor('8.4', '>=7.4'));
        self::assertSame([null, null], $answers($unparsable));
    }

    /** joomla/filter 2.0.6 and laminas-diactoros 2.26.0 need a php the target does not run. */
    public function testATargetThatCannotRunTheReleaseGetsNothing(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '2.0.0'],
            [['2.0.0', '>=7.2'], ['2.0.6', '~8.0.0']],
            ['A' => '<2.0.6'],
            new PhpFloor('8.4', '>=7.2.5')
        );

        $gets = $fixes->gets();
        self::assertNotNull($gets);
        self::assertNull($gets->version());
        self::assertSame([], $gets->clears());
        self::assertFalse($gets->clearsAll());
        self::assertFalse($gets->phpCheck()->targetRuns());
        self::assertSame('~8.0.0', $gets->phpCheck()->requires());
        $fix = $fixes->forAdvisory('A');
        self::assertSame(Fix::BLOCKED, $fix->kind());
        self::assertSame(Fix::BLOCKED_BY_TARGET, $fix->blockedBy());
        self::assertSame('2.x', $fixes->installedBranch() === null ? null : $fixes->installedBranch()->branch());
    }

    /** Composer resolves against the target, so it installs the highest release that the target runs. */
    public function testComposerUpdateSkipsAReleaseTheTargetCannotRun(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '2.0.0'],
            [['2.0.0', '>=7.2'], ['2.0.5', '>=7.2'], ['2.0.6', '~8.0.0']],
            ['A' => '<2.0.5'],
            new PhpFloor('8.4', '>=7.2.5')
        );

        $gets = $fixes->gets();
        self::assertNotNull($gets);
        self::assertSame('2.0.5', $gets->version());
        self::assertSame(['A'], $gets->clears());
        self::assertTrue($gets->phpCheck()->targetRuns());
    }

    public function testPhpThatRisesInsideAMajorGivesEachAdvisoryItsOwnClass(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '2.9.0'],
            [['2.9.0', '>=7.2'], ['3.0.0', '>=7.2'], ['3.5.0', '>=7.4'], ['3.9.0', '>=8.1']],
            ['early' => '<3.5.0', 'late' => '<3.9.0'],
            new PhpFloor('8.4', '^7.4 || ^8.0')
        );

        self::assertSame(Fix::UPDATE, $fixes->forAdvisory('early')->kind());
        self::assertSame('3.5.0', $fixes->forAdvisory('early')->version());
        self::assertSame('>=7.4', $fixes->forAdvisory('early')->php());
        self::assertSame(Fix::RAISE_PHP, $fixes->forAdvisory('late')->kind());
        self::assertSame('3.9.0', $fixes->forAdvisory('late')->version());
        $three = $fixes->branches()[0];
        self::assertSame('3.x', $three->branch());
        self::assertSame('3.9.0', $three->lowest(), 'the branch fixes both from 3.9.0');
        self::assertSame(Fix::RAISE_PHP, $three->fixKind());
    }

    /** For a branch snapshot, every stable release is a candidate. */
    public function testABranchSnapshotTakesEveryStableReleaseAsACandidate(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => 'dev-main'],
            [['1.0.0', null], ['1.1.0', null], ['2.0.0', null]],
            ['A' => '<1.1.0'],
            new PhpFloor('8.4', '>=8.1')
        );

        $fix = $fixes->forAdvisory('A');
        self::assertSame(Fix::UPDATE, $fix->kind());
        self::assertSame('1.1.0', $fix->version());
        self::assertSame('1.x', $fix->toBranch());
        self::assertFalse($fix->onInstalledBranch());
        self::assertNull($fixes->installedBranch());
        self::assertNull($fixes->gets());
        self::assertNull($fix->php(), 'the release declares no php');
    }

    public function testAConflictHoldsTheRelease(): void
    {
        $fix = $this->conflicting()->forAdvisory('A');
        self::assertSame(Fix::UPGRADE, $fix->kind());
        self::assertSame([['source' => Holder::PACKAGE, 'package' => 'c/conflicting', 'version' => '3.0.0', 'link' => Holder::CONFLICT, 'constraint' => '>=1.2']], self::holders($fix->heldBy()));
    }

    /** A link in the fix's own `held_by` does not lower `newest`: the move clears that hold (SPEC 5.3, S9 row). */
    public function testTheLinkThatHoldsTheFixDoesNotLowerItsNewest(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', null], ['1.1.0', null], ['1.2.0', null], ['1.3.0', null], ['1.4.0', null]],
            ['A' => '<1.3.0'],
            new PhpFloor('8.4', null),
            [['name' => 'c/c', 'version' => '1.0.0', 'conflict' => ['a/b' => '>=1.3.0']]]
        );
        $fix = $fixes->forAdvisory('A');
        $row = $fixes->installedBranch();
        self::assertNotNull($row);

        self::assertSame(['1.3.0', '1.4.0'], [$fix->version(), $fix->newest()], 'the fix');
        self::assertSame('1.4.0', $row->newest(), 'installed_branch_fixes');
        self::assertSame('1.2.0', $fixes->newestOnInstalledBranch(), 'an update with no hold');
    }

    /** A link that the fix's `held_by` does not hold still lowers `newest`. */
    public function testALinkOutsideTheFixHoldsLowersItsNewest(): void
    {
        $fix = $this->find(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', null], ['1.3.0', null], ['1.4.0', null]],
            ['A' => '<1.3.0'],
            new PhpFloor('8.4', null),
            [['name' => 'c/c', 'version' => '1.0.0', 'conflict' => ['a/b' => '>=1.3.0']], ['name' => 'd/d', 'version' => '1.0.0', 'conflict' => ['a/b' => '1.4.0']]]
        )->forAdvisory('A');

        self::assertSame(['1.3.0', '1.3.0'], [$fix->version(), $fix->newest()]);
    }

    /** No fixing release runs on the target PHP, so no allowed release lies at or above the fix: `newest` is the branch's newest. */
    public function testAFixThatTheTargetPhpCannotRunKeepsItsNewestAtOrAboveItsVersion(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', null], ['1.2.0', null], ['1.3.0', '>=9.0'], ['1.4.0', '>=9.0']],
            ['A' => '<1.3.0'],
            new PhpFloor('8.4', null)
        );
        $fix = $fixes->forAdvisory('A');
        $row = $fixes->installedBranch();
        self::assertNotNull($row);

        self::assertSame(['1.3.0', '1.4.0'], [$fix->version(), $fix->newest()], 'the fix');
        self::assertSame(['1.3.0', '1.4.0'], [$row->lowest(), $row->newest()], 'installed_branch_fixes');
    }

    /** The links come from packages and packages-dev, whatever --dev says: composer update reads both. */
    public function testADevPackageHoldsAProdPackage(): void
    {
        $fix = $this->heldByADevPackage()->forAdvisory('A');
        self::assertSame(Fix::UPGRADE, $fix->kind());
        self::assertSame([['source' => Holder::PACKAGE, 'package' => 'd/tool', 'version' => '1.0.0', 'link' => Holder::REQUIRE, 'constraint' => '^1.0']], self::holders($fix->heldBy()));
    }

    public function testTheRootRequireDevAndRootConflictHoldTheReleaseToo(): void
    {
        $fixes = $this->heldByTheRootDevAndConflict();

        self::assertSame([
            ['source' => Holder::ROOT, 'package' => null, 'version' => null, 'link' => Holder::REQUIRE_DEV, 'constraint' => '^1.0'],
            ['source' => Holder::ROOT, 'package' => null, 'version' => null, 'link' => Holder::CONFLICT, 'constraint' => '>=2'],
        ], self::holders($fixes->forAdvisory('A')->heldBy()));
    }

    /** The class reads only PHP. The links fill held_by for every class, blocked included. */
    public function testATargetBlockKeepsItsHolders(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', '>=7.1'], ['2.0.0', '>=8.1']],
            ['A' => '<2.0.0'],
            new PhpFloor('7.4', '>=7.1'),
            [],
            [],
            ['require' => ['a/b' => '^1.0']]
        );

        $fix = $fixes->forAdvisory('A');
        self::assertSame(Fix::BLOCKED, $fix->kind());
        self::assertSame(Fix::BLOCKED_BY_TARGET, $fix->blockedBy());
        self::assertCount(1, $fix->heldBy());
        self::assertSame(Fix::BLOCKED, $fixes->branches()[0]->fixKind());
    }

    public function testARefusedMoveHoldsTheReleaseOnlyInTheScanThatTakesIt(): void
    {
        [$lock, $facts] = $this->facts(['name' => 'a/b', 'version' => '1.0.0'], [['1.0.0', null], ['1.1.0', null]], ['A' => '<1.1.0']);
        $finder = new FixFinder(new PhpFloor(null, null), LinkIndex::of($lock, ProjectConfig::empty()));

        $held = $finder->withRefused(RefusedMoves::none()->with('^1.1', [new Holder(Holder::ROOT, 'acme/app', null, Holder::REQUIRE, '<1.1')]));

        self::assertSame([['source' => Holder::ROOT, 'package' => 'acme/app', 'version' => null, 'link' => Holder::REQUIRE, 'constraint' => '<1.1']], self::holders($held->find($facts)->forAdvisory('A')->heldBy()));
        self::assertSame([], $finder->find($facts)->forAdvisory('A')->heldBy());
    }

    /**
     * Both candidates are `upgrade`: a conflict holds 1.1.0, and the root's `^1.0` holds 2.0.0.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function refusalsOfTwoUpgrades(): iterable
    {
        yield 'a refusal admits the lower release, so the higher one leads' => ['^1.1', '2.0.0'];
        yield 'no refusal admits a candidate, so the lowest release leads' => ['^3.0', '1.1.0'];
    }

    /**
     * @dataProvider refusalsOfTwoUpgrades
     */
    #[DataProvider('refusalsOfTwoUpgrades')]
    public function testARefusedReleaseComesAfterTheOthersOfItsEaseStep(string $refused, string $leads): void
    {
        [$lock, $facts] = $this->facts(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', null], ['1.1.0', null], ['2.0.0', null]],
            ['A' => '<1.1.0'],
            [['name' => 'c/c', 'version' => '1.0.0', 'conflict' => ['a/b' => '1.1.0']]]
        );
        $finder = (new FixFinder(new PhpFloor(null, null), LinkIndex::of($lock, ProjectConfig::fromArray(['require' => ['a/b' => '^1.0']]))))
            ->withRefused(RefusedMoves::none()->with($refused, [new Holder(Holder::ROOT, null, null, Holder::REQUIRE, '^1.0')]));

        $fixes = $finder->find($facts);

        self::assertSame(Fix::UPGRADE, $fixes->forAdvisory('A')->kind());
        self::assertSame($leads, $fixes->forAdvisory('A')->version());
        $move = $fixes->move();
        self::assertNotNull($move);
        self::assertSame($leads, $move->release()->pretty());
        self::assertSame(['2.0.0', '1.1.0'], array_map(static fn (BranchFixes $row): ?string => $row->lowest(), $fixes->branches()), 'a row with one candidate keeps it');
    }

    /** Within the 1.x row, both releases are `upgrade` and a refusal admits only the lower one. */
    public function testTheRowCandidateSkipsARefusedRelease(): void
    {
        [$lock, $facts] = $this->facts(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', null], ['1.1.0', null], ['1.2.0', null]],
            ['A' => '<1.1.0'],
            [['name' => 'c/c', 'version' => '1.0.0', 'conflict' => ['a/b' => '1.1.0 || 1.2.0']]]
        );
        $finder = (new FixFinder(new PhpFloor(null, null), LinkIndex::of($lock, ProjectConfig::empty())))
            ->withRefused(RefusedMoves::none()->with('~1.1.0', [new Holder(Holder::ROOT, null, null, Holder::REQUIRE, '^1.0')]));

        $fixes = $finder->find($facts);

        self::assertSame('1.2.0', $fixes->branches()[0]->lowest());
        self::assertSame('1.2.0', $fixes->forAdvisory('A')->version());
        $unrefused = (new FixFinder(new PhpFloor(null, null), LinkIndex::of($lock, ProjectConfig::empty())))->find($facts)->forAdvisory('A');
        self::assertSame(['1.1.0', Fix::UPGRADE], [$unrefused->version(), $unrefused->kind()], 'the same links with no refusal: the lowest release');
    }

    /**
     * Installed 1.0.0, advisory A `<1.1.0`, target PHP 8.4 and `require.php` `>=7.4`. Each refusal names the root's `^1.0`,
     * unless its holds are empty.
     *
     * @return iterable<string, array{0: list<array{0: string, 1: ?string}>, 1: array<string, string>, 2: array<string, bool>, 3: string, 4: string}>
     */
    public static function refusalsAcrossEaseSteps(): iterable
    {
        yield 'a refused upgrade beats a free raise-php on another branch' => [
            [['1.0.0', '>=7.4'], ['1.1.0', '>=7.4'], ['2.0.0', '>=8.1']], [], ['^1.1' => true], '1.1.0', Fix::UPGRADE,
        ];
        yield 'every candidate refused on two branches: the lowest release' => [
            [['1.0.0', null], ['1.1.0', null], ['2.0.0', null]], [], ['^1.1' => true, '^2.0' => true], '1.1.0', Fix::UPGRADE,
        ];
        yield 'a refused held raise-php comes after an unrefused held one' => [
            [['1.0.0', '>=7.4'], ['1.1.0', '>=8.1'], ['1.2.0', '>=8.1']], ['a/b' => '1.2.0'], ['~1.1.0' => true], '1.2.0', Fix::RAISE_PHP,
        ];
        yield 'a release refused with no hold comes after one that only the target blocks' => [
            [['1.0.0', null], ['1.1.0', null], ['2.0.0', '>=9.0']], [], ['^1.1' => false], '2.0.0', Fix::BLOCKED,
        ];
        yield 'a refusal that names no hold blocks a release that a link holds' => [
            [['1.0.0', null], ['1.1.0', null]], ['a/b' => '1.1.0'], ['^1.1' => false], '1.1.0', Fix::BLOCKED,
        ];
    }

    /**
     * @dataProvider refusalsAcrossEaseSteps
     *
     * @param list<array{0: string, 1: ?string}> $releases [version, php]
     * @param array<string, string>              $conflict the conflict of c/c
     * @param array<string, bool>                $refusals refused constraint => whether the refusal names a hold
     */
    #[DataProvider('refusalsAcrossEaseSteps')]
    public function testTheMoveAndTheFixFollowTheEaseOrderWithRefusals(array $releases, array $conflict, array $refusals, string $version, string $kind): void
    {
        [$lock, $facts] = $this->facts(['name' => 'a/b', 'version' => '1.0.0'], $releases, ['A' => '<1.1.0'], [['name' => 'c/c', 'version' => '1.0.0', 'conflict' => $conflict]]);
        $refused = RefusedMoves::none();
        foreach ($refusals as $constraint => $held) {
            $refused = $refused->with($constraint, $held ? [new Holder(Holder::ROOT, null, null, Holder::REQUIRE, '^1.0')] : []);
        }
        $finder = (new FixFinder(new PhpFloor('8.4', '>=7.4'), LinkIndex::of($lock, ProjectConfig::fromArray(['require' => ['a/b' => '^1.0']]))))->withRefused($refused);

        $fixes = $finder->find($facts);

        self::assertSame([$version, $kind], [$fixes->forAdvisory('A')->version(), $fixes->forAdvisory('A')->kind()]);
        $move = $fixes->move();
        self::assertNotNull($move);
        self::assertSame([$version, $kind], [$move->release()->pretty(), $move->kind()]);
    }

    /**
     * A conflict of the lock holds 1.3.0. The refusal of `^1.2` lowers neither the newest release of the
     * branch nor `gets`: no solve installs 1.1.0 (SPEC §5.3, ruling R2-1).
     */
    public function testTheNewestOfTheInstalledBranchAndGetsAreWhatTheLinksAllowWhateverTheSolverRefuses(): void
    {
        [$lock, $facts] = $this->facts(['name' => 'a/b', 'version' => '1.0.0'], [['1.0.0', '>=7.4'], ['1.1.0', '>=7.4'], ['1.2.0', '>=7.4'], ['1.3.0', '>=7.4']], ['A' => '<1.1.0'], [['name' => 'c/c', 'version' => '1.0.0', 'conflict' => ['a/b' => '>=1.3.0']]]);
        $refused = RefusedMoves::none()->with('^1.2', [new Holder(Holder::ROOT, null, null, Holder::REQUIRE, '<1.2')]);
        $finder = (new FixFinder(new PhpFloor('8.4', '>=7.4'), LinkIndex::of($lock, ProjectConfig::fromArray(['require' => ['a/b' => '^1.0']]))))->withRefused($refused);

        $fixes = $finder->find($facts);
        $gets = $fixes->gets();

        self::assertNotNull($gets);
        self::assertSame(['1.2.0', '1.2.0'], [$gets->version(), $fixes->newestOnInstalledBranch()]);
    }

    /** SPEC §5.3 (case mc-link-newest‡): the S9 fix and the installed row read the newest release that the lock's links allow. */
    public function testTheFixAndTheInstalledRowNameTheNewestReleaseThatTheLinksAllow(): void
    {
        [$lock, $facts] = $this->facts(['name' => 'a/b', 'version' => '1.0.0'], [['1.0.0', '>=7.4'], ['1.1.0', '>=7.4'], ['1.2.0', '>=7.4'], ['1.3.0', '>=7.4'], ['2.0.0', '>=7.4']], ['A' => '<1.1.0'], [['name' => 'c/c', 'version' => '1.0.0', 'conflict' => ['a/b' => '>=1.3.0 <2.0']]]);
        $finder = new FixFinder(new PhpFloor('8.4', '>=7.4'), LinkIndex::of($lock, ProjectConfig::fromArray(['require' => ['a/b' => '^1.0']])));

        $fixes = $finder->find($facts);
        $installed = $fixes->installedBranch();

        self::assertNotNull($installed);
        self::assertSame(['1.2.0', '1.2.0', '2.0.0'], [$fixes->forAdvisory('A')->newest(), $installed->newest(), $fixes->branches()[0]->newest()]);
    }

    public function testNoFloorAdmitsEveryRelease(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', '>=7.1'], ['2.0.0', '>=9.0']],
            ['A' => '<2.0.0'],
            new PhpFloor(null, null)
        );

        self::assertSame(Fix::UPDATE, $fixes->forAdvisory('A')->kind());
        $gets = $fixes->gets();
        self::assertNull($gets, 'no newer release on 1.x');
    }

    /** Ease first, then the lowest release: a held 1.5.0 loses to a free 2.0.0. */
    public function testTheEaseOrderPicksTheCandidateBeforeTheVersion(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', '>=7.4'], ['1.5.0', '>=7.4'], ['2.0.0', '>=8.1'], ['2.1.0', '>=8.1'], ['3.0.0', '>=7.4']],
            ['A' => '<1.5.0', 'B' => '<2.0.0'],
            new PhpFloor('8.4', '>=7.4'),
            [['name' => 'c/c', 'version' => '1.0.0', 'conflict' => ['a/b' => '1.5.0']]]
        );

        self::assertSame('3.0.0', $fixes->forAdvisory('A')->version(), 'update 3.0.0 is easier than upgrade 1.5.0 and raise-php 2.0.0');
        self::assertSame(Fix::UPDATE, $fixes->forAdvisory('A')->kind());
        self::assertSame('3.0.0', $fixes->forAdvisory('B')->version());
        self::assertSame(
            [0, 1, 2, 3, 4],
            [
                Fix::easeRank(Fix::UPDATE, false),
                Fix::easeRank(Fix::UPGRADE, true),
                Fix::easeRank(Fix::RAISE_PHP, false),
                Fix::easeRank(Fix::RAISE_PHP, true),
                Fix::easeRank(Fix::BLOCKED, false),
            ]
        );
        self::assertSame(Fix::easeRank(Fix::BLOCKED, false), Fix::easeRank(Fix::BLOCKED, true));
    }

    public function testAMoveThatIsNotAnUpdateLeavesAPartialUpdate(): void
    {
        $fixes = $this->find(
            ['name' => 'geoip2/geoip2', 'version' => 'v2.12.0'],
            [['v2.12.0', '>=7.2'], ['v2.13.0', '>=7.2'], ['v3.4.0', '>=8.1']],
            ['medium' => '<2.13.0', 'high' => '<3.0.0'],
            new PhpFloor('8.4', '>=7.2.5')
        );

        $partial = $fixes->partial();
        self::assertNotNull($partial);
        self::assertSame('v2.13.0', $partial->version());
        self::assertSame(['medium'], $partial->clears());
        $move = $fixes->move();
        self::assertNotNull($move);
        self::assertSame(Fix::RAISE_PHP, $move->kind());
        self::assertSame('v3.4.0', $move->release()->pretty());
    }

    public function testAnUpdateMoveLeavesNoPartial(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '2.0.0'],
            [['2.0.0', null], ['2.1.0', null], ['2.2.0', null]],
            ['A' => '<2.1.0', 'B' => '<2.2.0'],
            new PhpFloor('8.4', '>=7.2')
        );

        self::assertNull($fixes->partial());
        $gets = $fixes->gets();
        self::assertNotNull($gets);
        self::assertSame('2.2.0', $gets->version());
        self::assertSame(['A', 'B'], $gets->clears());
        self::assertTrue($gets->clearsAll());
        self::assertSame(['requires' => null, 'project_allows' => null, 'target_runs' => null, 'raise_to' => null, 'raise_size' => null], $gets->phpCheck()->jsonSerialize());
    }

    /** composer update installs the highest release on the branch that the lock's links allow. */
    public function testComposerUpdateGetsTheHighestReleaseTheLinksAllow(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '2.0.0'],
            [['2.0.0', null], ['2.1.0', null], ['2.2.0', null]],
            ['A' => '<2.1.0'],
            new PhpFloor('8.4', null),
            [['name' => 'c/c', 'version' => '1.0.0', 'require' => ['a/b' => '~2.1.0']]]
        );

        $gets = $fixes->gets();
        self::assertNotNull($gets);
        self::assertSame('2.1.0', $gets->version());

        $above = $this->find(
            ['name' => 'a/b', 'version' => '2.0.0'],
            [['2.0.0', null], ['2.1.0', null], ['2.2.0', null]],
            ['A' => '<2.1.0'],
            new PhpFloor('8.4', null),
            [['name' => 'c/c', 'version' => '1.0.0', 'conflict' => ['a/b' => '2.1.0']]]
        )->gets();
        self::assertNotNull($above);
        self::assertSame('2.2.0', $above->version(), 'a held release below a free one');
    }

    public function testWhatTheScanCannotJudgeIsUnknownWithItsReason(): void
    {
        $floor = new PhpFloor('8.4', null);
        $noRange = new Advisory('PKSA-norange', null, null, null, 'high', null, null);
        $ranged = self::advisory('A', '<2.0.0');

        $unread = $this->finder($floor, [])->find(new PackageFacts(self::locked(['name' => 'a/b', 'version' => '1.0.0', 'notification-url' => self::PACKAGIST]), null, null, [$ranged]));
        self::assertSame(Fix::UNKNOWN, $unread->forAdvisory('A')->kind());
        self::assertSame(Fix::RELEASES_UNKNOWN, $unread->forAdvisory('A')->reason());
        self::assertSame([], $unread->branches(), 'unread releases give no branch rows');
        self::assertNull($unread->gets());

        $unparsed = PackageMetadata::fromPackages('a/b', [], new \DateTimeImmutable(self::NOW), null);
        $listless = $this->finder($floor, [])->find(new PackageFacts(self::locked(['name' => 'a/b', 'version' => '1.0.0', 'notification-url' => self::PACKAGIST]), $unparsed, null, [$ranged]));
        self::assertSame(Fix::RELEASES_UNKNOWN, $listless->forAdvisory('A')->reason(), 'metadata with no release list');

        $vcs = self::locked(['name' => 'a/b', 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => 'https://example.org/a/b.git', 'reference' => 'x']]);
        self::assertFalse($vcs->isFromComposerRepository());
        $outside = $this->finder($floor, [])->find(new PackageFacts($vcs, null, null, [$ranged]));
        self::assertSame(Fix::NOT_FROM_COMPOSER_REPOSITORY, $outside->forAdvisory('A')->reason());

        $fixes = $this->find(['name' => 'a/b', 'version' => '1.0.0'], [['1.0.0', null], ['1.1.0', null]], ['A' => '<9.0.0'], $floor, [], [], [], [$noRange]);
        self::assertSame(Fix::UNKNOWN, $fixes->forAdvisory('PKSA-norange')->kind());
        self::assertSame(Fix::AFFECTED_RANGE_UNKNOWN, $fixes->forAdvisory('PKSA-norange')->reason());
        self::assertSame(Fix::NONE, $fixes->forAdvisory('A')->kind());
        self::assertSame(Fix::NO_RELEASE_OUTSIDE_RANGE, $fixes->forAdvisory('A')->reason());
        foreach (['PKSA-norange', 'A'] as $id) {
            $fix = $fixes->forAdvisory($id);
            self::assertSame([null, null, null, null, null], [$fix->toBranch(), $fix->version(), $fix->newest(), $fix->php(), $fix->onInstalledBranch()]);
            self::assertSame([null, null], [$fix->jsonSerialize()['admits_target_php'], $fix->jsonSerialize()['admits_project_php']]);
            self::assertSame([], $fix->heldBy());
        }
        $row = $fixes->installedBranch();
        self::assertNotNull($row);
        self::assertSame([0, 1, 2], [$row->fixed(), $row->unknown(), $row->of()]);
        self::assertNull($fixes->move());
    }

    /** A branch whose newest release is still inside the range fixes nothing, whatever it holds. */
    public function testABranchThatFixesNothingHasNoClassAndNoPartial(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', null], ['1.1.0', null], ['2.0.0', null], ['3.0.0', '>=8.1']],
            ['A' => '<3.0.0'],
            new PhpFloor('8.4', '>=7.2')
        );

        $two = $fixes->branches()[1];
        self::assertSame('2.x', $two->branch());
        self::assertSame([0, null, null, null], [$two->fixed(), $two->fixKind(), $two->lowest(), $two->candidate()]);
        $move = $fixes->move();
        self::assertNotNull($move);
        self::assertSame(Fix::RAISE_PHP, $move->kind());
        self::assertNull($fixes->partial(), '1.1.0 is an update that clears nothing');
    }

    /** A range that comes back: the branch fixes it only from the release after the last affected one. */
    public function testABranchsLowerBoundLiesAboveTheLastAffectedRelease(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '2.3.0'],
            [['2.3.0', null], ['2.4.0', null], ['2.5.0', null], ['2.6.0', null], ['2.7.0', null]],
            ['A' => '<2.4.0 || >=2.5.0,<2.7.0'],
            new PhpFloor('8.4', '>=7.2')
        );

        $row = $fixes->installedBranch();
        self::assertNotNull($row);
        self::assertSame('2.7.0', $row->lowest(), '2.5.0 and 2.6.0 are affected again');
        $move = $fixes->move();
        self::assertNotNull($move);
        self::assertSame(['2.7.0', Fix::UPDATE], [$move->release()->pretty(), $move->kind()], 'the move is the branch lower bound, as its row');
    }

    /** The move takes each branch's lower bound, then the ease order: 2.4.0 is no lower bound, since ^2.4.0 admits 2.5.0. */
    public function testTheMoveIsTheEasiestBranchLowerBound(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '2.3.0'],
            [['2.3.0', '>=7.2'], ['2.4.0', '>=7.2'], ['2.5.0', '>=7.2'], ['2.6.0', '>=7.2'], ['2.7.0', '>=8.1'], ['3.0.0', '>=7.2']],
            ['A' => '<2.4.0 || >=2.5.0,<2.7.0'],
            new PhpFloor('8.4', '>=7.2'),
            [],
            [],
            ['require' => ['a/b' => '^2.3']]
        );

        $move = $fixes->move();
        self::assertNotNull($move);
        self::assertSame(['3.0.0', Fix::UPGRADE], [$move->release()->pretty(), $move->kind()], 'upgrade 3.0.0 beats raise-php 2.7.0');
        $two = $fixes->installedBranch();
        self::assertNotNull($two);
        self::assertSame(['2.7.0', Fix::RAISE_PHP], [$two->lowest(), $two->fixKind()]);
    }

    /** Two branch lower bounds at one ease: the lower release leads. */
    public function testATieInEaseGoesToTheLowerBranch(): void
    {
        $move = $this->find(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', null], ['2.0.0', null], ['3.0.0', null]],
            ['A' => '<2.0.0'],
            new PhpFloor('8.4', null)
        )->move();

        self::assertNotNull($move);
        self::assertSame(['2.0.0', Fix::UPDATE], [$move->release()->pretty(), $move->kind()]);
    }

    /** A row's class is its lower bound's class, so the row, the move to its branch and the fix of that release agree. */
    public function testARowsClassIsItsLowerBoundsClass(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', null], ['2.0.0', null]],
            ['A' => '<2.0.0'],
            new PhpFloor('8.4', null),
            [],
            [],
            ['require' => ['a/b' => '^1.0']]
        );

        $two = $fixes->branches()[0];
        $candidate = $two->candidate();
        self::assertNotNull($candidate);
        self::assertSame([Fix::UPGRADE, Fix::UPGRADE, Fix::UPGRADE], [$two->fixKind(), $candidate->kind(), $fixes->forAdvisory('A')->kind()]);
        self::assertCount(1, $candidate->heldBy());
    }

    /**
     * A row's `fixed` counts the advisories that the branch's newest release lies outside: the
     * branch has a lower bound for them. One that a later release reintroduces is not fixed on it,
     * and no release below the reintroduction fixes it either.
     */
    public function testARowFixesOnlyWhatItsNewestReleaseClears(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '2.3.0'],
            [['2.3.0', null], ['2.4.0', null], ['2.5.0', null], ['2.7.0', null]],
            ['A' => '<2.4.0 || >=2.7.0'],
            new PhpFloor('8.4', null)
        );

        $row = $fixes->installedBranch();
        self::assertNotNull($row);
        self::assertSame([0, null, null], [$row->fixed(), $row->lowest(), $row->fixKind()]);
        self::assertSame(Fix::NONE, $fixes->forAdvisory('A')->kind(), '2.7.0 brings the advisory back, so 2.4.0 fixes nothing');
    }

    /** An advisory's fix is the easiest release from which the rest of its branch lies outside the range. */
    public function testAnAdvisoryThatALaterReleaseReintroducesIsFixedOnTheNextBranch(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '2.3.0'],
            [['2.3.0', null], ['2.4.0', null], ['2.7.0', null], ['3.0.0', null]],
            ['A' => '<2.4.0 || >=2.7.0,<3.0.0'],
            new PhpFloor('8.4', null)
        );

        $fix = $fixes->forAdvisory('A');
        self::assertSame(['3.0.0', '3.x', Fix::UPDATE], [$fix->version(), $fix->toBranch(), $fix->kind()]);
    }

    /**
     * The row's candidate is the easiest release in the branch's safe window, then the lowest.
     * A conflict `<1.4.5` holds 1.4.3 and 1.4.4, and 1.4.5 to 1.4.9 are free.
     */
    public function testTheCandidateIsTheEasiestReleaseOfTheWindow(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '1.4.2'],
            [['1.4.2', null], ['1.4.3', null], ['1.4.4', null], ['1.4.5', null], ['1.4.9', null]],
            ['A' => '<1.4.3'],
            new PhpFloor('8.4', null),
            [['name' => 'c/c', 'version' => '1.0.0', 'conflict' => ['a/b' => '<1.4.5']]]
        );

        $row = $fixes->installedBranch();
        self::assertNotNull($row);
        self::assertSame(['1.4.5', Fix::UPDATE, 1], [$row->lowest(), $row->fixKind(), $row->fixed()]);
        $candidate = $row->candidate();
        self::assertNotNull($candidate);
        self::assertSame([], $candidate->heldBy());
        $move = $fixes->move();
        self::assertNotNull($move);
        self::assertSame(['1.4.5', Fix::UPDATE], [$move->release()->pretty(), $move->kind()], 'the move is the row candidate');
        self::assertSame(['1.4.5', Fix::UPDATE], [$fixes->forAdvisory('A')->version(), $fixes->forAdvisory('A')->kind()]);
    }

    public function testABranchBelowTheInstalledOneFixesNothing(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '2.0.0'],
            [['1.9.0', null], ['2.0.0', null], ['2.1.0', null]],
            ['A' => '>=2.0.0,<2.1.0'],
            new PhpFloor('8.4', null)
        );

        [$two, $one] = $fixes->branches();
        self::assertSame(['2.x', 1], [$two->branch(), $two->fixed()]);
        self::assertSame(['1.x', 0, null], [$one->branch(), $one->fixed(), $one->lowest()]);
    }

    /**
     * The window starts above every advisory on its own versions. prestashop-v1's
     * symfony/http-client 4.4.26: `^5.4.47` admits 5.4.47 to 5.4.52, inside an advisory that 4.4.26
     * does not count.
     */
    public function testTheWindowStartsAboveAnAdvisoryOnItsOwnVersions(): void
    {
        $fixes = $this->find(
            ['name' => 'symfony/http-client', 'version' => 'v4.4.26'],
            [['v4.4.26', null], ['v5.4.47', null], ['v5.4.52', null], ['v5.4.53', null], ['v5.4.55', null]],
            ['A' => '<5.4.47'],
            new PhpFloor('8.4', null),
            [],
            [],
            [],
            [],
            [self::advisory('PKSA-35by', '>=5.4.0,<5.4.53')]
        );

        $five = $fixes->branches()[0];
        self::assertSame(['5.x', 'v5.4.53', 1], [$five->branch(), $five->lowest(), $five->fixed()]);
        self::assertSame('v5.4.53', $fixes->forAdvisory('A')->version());
        $move = $fixes->move();
        self::assertNotNull($move);
        self::assertSame('v5.4.53', $move->release()->pretty());
    }

    /** An advisory on the branch's newest release itself leaves the window where the counted ranges put it. */
    public function testAnAdvisoryOnTheNewestReleaseDoesNotEmptyTheWindow(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', null], ['1.1.0', null], ['1.2.0', null]],
            ['A' => '<1.1.0'],
            new PhpFloor('8.4', null),
            [],
            [],
            [],
            [],
            [self::advisory('B', '>=1.2.0')]
        );

        $row = $fixes->installedBranch();
        self::assertNotNull($row);
        self::assertSame(['1.1.0', 1], [$row->lowest(), $row->fixed()]);
    }

    /** SPEC §5.3: the window still rises past an advisory that a higher release on the branch avoids. */
    public function testAnAdvisoryOnTheNewestReleaseStillLetsTheWindowRisePastTheOthers(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', null], ['1.1.0', null], ['1.2.0', null], ['1.3.0', null]],
            ['A' => '<1.1.0'],
            new PhpFloor('8.4', null),
            [],
            [],
            [],
            [],
            [self::advisory('B', '>=1.1.0,<1.2.0'), self::advisory('C', '>=1.3.0')]
        );

        $row = $fixes->installedBranch();
        self::assertNotNull($row);
        self::assertSame(['1.2.0', 1], [$row->lowest(), $row->fixed()]);
        self::assertSame('1.2.0', $fixes->forAdvisory('A')->version());
        $move = $fixes->move();
        self::assertNotNull($move);
        self::assertSame('1.2.0', $move->release()->pretty());
    }

    /** No row fixes an advisory with no range, so no row fixes every counted advisory and there is no security move. */
    public function testAnAdvisoryWithNoRangeLeavesNoSecurityMove(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', null], ['1.1.0', null]],
            ['A' => '<1.1.0'],
            new PhpFloor('8.4', null),
            [],
            [],
            [],
            [new Advisory('B', null, null, null, 'high', null, null)]
        );

        self::assertNull($fixes->move());
        self::assertSame(Fix::UPDATE, $fixes->forAdvisory('A')->kind());
    }

    /** Among two raise-php candidates, the one that nothing holds is easier. */
    public function testAFreeRaisePhpIsEasierThanAHeldOne(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', '>=7.2'], ['2.0.0', '>=8.1'], ['3.0.0', '>=8.1']],
            ['A' => '<2.0.0'],
            new PhpFloor('8.4', '>=7.2'),
            [['name' => 'c/c', 'version' => '1.0.0', 'conflict' => ['a/b' => '2.0.0']]]
        );

        $fix = $fixes->forAdvisory('A');
        self::assertSame(Fix::RAISE_PHP, $fix->kind());
        self::assertSame('3.0.0', $fix->version());
        self::assertSame([], $fix->heldBy());
    }

    public function testGuzzleFiveIsAnUpgradeHeldByTheRoot(): void
    {
        $fixes = $this->guzzleFive();

        $fix = $fixes->forAdvisory('CVE-2022-31042');
        self::assertSame(Fix::UPGRADE, $fix->kind());
        self::assertSame('6.5.8', $fix->version());
        self::assertSame('6.x', $fix->toBranch());
        self::assertSame(['^5.3'], array_map(static fn (Holder $holder): string => $holder->constraint(), $fix->heldBy()));
        $seven = $fixes->branches()[0];
        self::assertSame(['7.x', '7.4.5', '7.10.0', 1], [$seven->branch(), $seven->lowest(), $seven->newest(), $seven->fixed()]);
    }

    /** symfony/yaml: a backport on the installed branch and a fix on the next major. */
    public function testYamlTakesTheBackportOnItsOwnBranch(): void
    {
        $fixes = $this->find(
            ['name' => 'symfony/yaml', 'version' => 'v6.4.40'],
            [['v6.4.40', '>=8.1'], ['v6.4.41', '>=8.1'], ['v7.3.0', '>=8.2'], ['v7.3.5', '>=8.2']],
            ['A' => '>=6.0.0,<6.4.41 || >=7.0.0,<7.3.5'],
            new PhpFloor('8.4', '>=8.2')
        );

        $fix = $fixes->forAdvisory('A');
        self::assertSame(Fix::UPDATE, $fix->kind());
        self::assertSame('v6.4.41', $fix->version());
        self::assertTrue($fix->onInstalledBranch());
        $gets = $fixes->gets();
        self::assertNotNull($gets);
        self::assertTrue($gets->clearsAll());
        self::assertSame('v7.3.5', $fixes->branches()[0]->lowest(), 'v7.3.0 is inside the range on 7.x');
    }

    /** Upgrade implies a holder. Raise-php and blocked carry holders on their own. */
    public function testEveryUpgradeNamesAHolder(): void
    {
        $upgrades = 0;
        foreach ([$this->twigInPhpbb(), $this->conflicting(), $this->heldByADevPackage(), $this->heldByTheRootDevAndConflict(), $this->guzzleFive()] as $fixes) {
            foreach ($fixes->fixes() as $fix) {
                if ($fix->kind() === Fix::UPGRADE) {
                    ++$upgrades;
                    self::assertNotSame([], $fix->heldBy());
                }
            }
            foreach ($fixes->branches() as $row) {
                $candidate = $row->candidate();
                if ($candidate !== null && $candidate->kind() === Fix::UPGRADE) {
                    ++$upgrades;
                    self::assertNotSame([], $candidate->heldBy());
                }
            }
        }
        self::assertGreaterThan(0, $upgrades);
    }

    private function conflicting(): PackageFixes
    {
        return $this->find(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', null], ['1.2.0', null]],
            ['A' => '<1.2.0'],
            new PhpFloor('8.4', null),
            [['name' => 'c/conflicting', 'version' => '3.0.0', 'conflict' => ['a/b' => '>=1.2']]]
        );
    }

    private function heldByADevPackage(): PackageFixes
    {
        return $this->find(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', null], ['2.0.0', null]],
            ['A' => '<2.0.0'],
            new PhpFloor('8.4', null),
            [],
            [['name' => 'd/tool', 'version' => '1.0.0', 'require' => ['a/b' => '^1.0']]]
        );
    }

    private function heldByTheRootDevAndConflict(): PackageFixes
    {
        return $this->find(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', null], ['2.0.0', null]],
            ['A' => '<2.0.0'],
            new PhpFloor('8.4', null),
            [],
            [],
            ['require-dev' => ['a/b' => '^1.0'], 'conflict' => ['A/B' => '>=2']]
        );
    }

    /** guzzle-5: wallabag's guzzlehttp/guzzle 5.3.4. The root requires ^5.3, and 6.x fixes it. */
    private function guzzleFive(): PackageFixes
    {
        return $this->find(
            ['name' => 'guzzlehttp/guzzle', 'version' => '5.3.4'],
            [['5.3.4', '>=5.4.0'], ['6.5.7', '>=5.5'], ['6.5.8', '>=5.5'], ['7.4.5', '^7.2.5 || ^8.0'], ['7.10.0', '^7.2.5 || ^8.0']],
            ['CVE-2022-31042' => '<6.5.8 || >=7.0.0,<7.4.5'],
            new PhpFloor('8.4', '>=8.2'),
            [],
            [],
            ['require' => ['guzzlehttp/guzzle' => '^5.3']]
        );
    }

    private function twigInPhpbb(): PackageFixes
    {
        return $this->find(
            ['name' => 'twig/twig', 'version' => 'v2.16.1'],
            [
                ['v1.44.7', '>=7.2.5'], ['v1.44.8', '>=7.2.5'],
                ['v2.16.0', '>=7.1.3'], ['v2.16.1', '>=7.1.3'],
                ['v3.26.0', '>=8.1'], ['v3.27.0', '>=8.1'], ['v3.28.0', '>=8.1'],
            ],
            ['PKSA-8zx5' => '<=3.26.0', 'PKSA-fbvq' => '<3.27.0'],
            new PhpFloor('8.4', '^7.2 || ^8.0'),
            [['name' => 'symfony/twig-bridge', 'version' => 'v3.4.47', 'require' => ['twig/twig' => '^1.41|^2.10']]],
            [],
            ['name' => 'phpbb/phpbb', 'require' => ['twig/twig' => '^2.0']]
        );
    }

    /**
     * @param array<string, mixed>                $package     the lock entry of the package the scan judges
     * @param list<array{0: string, 1: ?string}>  $releases    [version, php]
     * @param array<string, string>               $ranges      advisory id => affected range
     * @param list<array<string, mixed>>          $packages    other lock entries
     * @param list<array<string, mixed>>          $devPackages packages-dev entries
     * @param array<string, mixed>                $root        composer.json
     * @param list<Advisory>                      $extra       advisories with no range
     * @param list<Advisory>                      $own         advisories on other versions of the package, not counted on the installed one
     */
    private function find(array $package, array $releases, array $ranges, PhpFloor $floor, array $packages = [], array $devPackages = [], array $root = [], array $extra = [], array $own = []): PackageFixes
    {
        [$lock, $facts] = $this->facts($package, $releases, $ranges, $packages, $devPackages, $extra);

        return (new FixFinder($floor, LinkIndex::of($lock, ProjectConfig::fromArray($root))))->find($facts, array_merge($facts->advisories(), $own));
    }

    /**
     * @param array<string, mixed>                $package
     * @param list<array{0: string, 1: ?string}> $releases
     * @param array<string, string>               $ranges
     * @param list<array<string, mixed>>          $packages
     * @param list<array<string, mixed>>          $devPackages
     * @param list<Advisory>                      $extra
     *
     * @return array{LockFile, PackageFacts}
     */
    private function facts(array $package, array $releases, array $ranges, array $packages = [], array $devPackages = [], array $extra = []): array
    {
        $package += ['notification-url' => self::PACKAGIST];
        $lock = LockFile::fromArray(['packages' => array_merge([$package], $packages), 'packages-dev' => $devPackages]);
        $name = $package['name'];
        self::assertIsString($name);
        $locked = $lock->find($name);
        self::assertNotNull($locked);
        $versions = [];
        foreach ($releases as $i => [$version, $php]) {
            $loaded = (new ArrayLoader())->load([
                'name' => $name,
                'version' => $version,
                'time' => (new \DateTimeImmutable('2020-01-01T00:00:00+00:00'))->modify('+'.$i.' days')->format(\DATE_ATOM),
                'require' => $php === null ? [] : ['php' => $php],
            ]);
            self::assertInstanceOf(BasePackage::class, $loaded);
            $versions[] = $loaded;
        }
        $metadata = PackageMetadata::fromPackages($name, $versions, new \DateTimeImmutable(self::NOW), $locked->normalizedVersion());
        $advisories = $extra;
        foreach ($ranges as $id => $range) {
            $advisories[] = self::advisory((string) $id, $range);
        }

        return [$lock, new PackageFacts($locked, $metadata, null, $advisories)];
    }

    /** @param list<array<string, mixed>> $packages */
    private function finder(PhpFloor $floor, array $packages): FixFinder
    {
        return new FixFinder($floor, LinkIndex::of(LockFile::fromArray(['packages' => $packages]), ProjectConfig::empty()));
    }

    /** @param array<string, mixed> $entry */
    private static function locked(array $entry): LockedPackage
    {
        $name = $entry['name'];
        self::assertIsString($name);
        $locked = LockFile::fromArray(['packages' => [$entry]])->find($name);
        self::assertNotNull($locked);

        return $locked;
    }

    private static function advisory(string $id, string $range): Advisory
    {
        return new Advisory($id, null, null, null, 'high', null, (new VersionParser())->parseConstraints($range));
    }

    /**
     * @param list<Holder> $holders
     *
     * @return list<array{source: string, package: ?string, version: ?string, link: string, constraint: string}>
     */
    private static function holders(array $holders): array
    {
        return array_map(static fn (Holder $holder): array => ['source' => $holder->source(), 'package' => $holder->package(), 'version' => $holder->version(), 'link' => $holder->link(), 'constraint' => $holder->constraint()], $holders);
    }
}
