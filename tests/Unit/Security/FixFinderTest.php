<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Security;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockedPackage;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Security\BranchFixes;
use Lockrot\Security\Fix;
use Lockrot\Security\FixFinder;
use Lockrot\Security\Holder;
use Lockrot\Security\LinkIndex;
use Lockrot\Security\PackageFixes;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
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
            $gets->phpCheck()->toArray()
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
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', null], ['1.2.0', null]],
            ['A' => '<1.2.0'],
            new PhpFloor('8.4', null),
            [['name' => 'c/conflicting', 'version' => '3.0.0', 'conflict' => ['a/b' => '>=1.2']]]
        );

        $fix = $fixes->forAdvisory('A');
        self::assertSame(Fix::UPGRADE, $fix->kind());
        self::assertSame([['source' => Holder::PACKAGE, 'package' => 'c/conflicting', 'version' => '3.0.0', 'link' => Holder::CONFLICT, 'constraint' => '>=1.2']], self::holders($fix->heldBy()));
    }

    /** The links come from packages and packages-dev, whatever --dev says: composer update reads both. */
    public function testADevPackageHoldsAProdPackage(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', null], ['2.0.0', null]],
            ['A' => '<2.0.0'],
            new PhpFloor('8.4', null),
            [],
            [['name' => 'd/tool', 'version' => '1.0.0', 'require' => ['a/b' => '^1.0']]]
        );

        $fix = $fixes->forAdvisory('A');
        self::assertSame(Fix::UPGRADE, $fix->kind());
        self::assertSame([['source' => Holder::PACKAGE, 'package' => 'd/tool', 'version' => '1.0.0', 'link' => Holder::REQUIRE, 'constraint' => '^1.0']], self::holders($fix->heldBy()));
    }

    public function testTheRootRequireDevAndRootConflictHoldTheReleaseToo(): void
    {
        $fixes = $this->find(
            ['name' => 'a/b', 'version' => '1.0.0'],
            [['1.0.0', null], ['2.0.0', null]],
            ['A' => '<2.0.0'],
            new PhpFloor('8.4', null),
            [],
            [],
            ['require-dev' => ['a/b' => '^1.0'], 'conflict' => ['A/B' => '>=2']]
        );

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
        self::assertSame(['requires' => null, 'project_allows' => null, 'target_runs' => null, 'raise_to' => null, 'raise_size' => null], $gets->phpCheck()->toArray());
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

    /** guzzle-5: wallabag's guzzlehttp/guzzle 5.3.4. The root requires ^5.3, and 6.x fixes it. */
    public function testGuzzleFiveIsAnUpgradeHeldByTheRoot(): void
    {
        $fixes = $this->find(
            ['name' => 'guzzlehttp/guzzle', 'version' => '5.3.4'],
            [['5.3.4', '>=5.4.0'], ['6.5.7', '>=5.5'], ['6.5.8', '>=5.5'], ['7.4.5', '^7.2.5 || ^8.0'], ['7.10.0', '^7.2.5 || ^8.0']],
            ['CVE-2022-31042' => '<6.5.8 || >=7.0.0,<7.4.5'],
            new PhpFloor('8.4', '>=8.2'),
            [],
            [],
            ['require' => ['guzzlehttp/guzzle' => '^5.3']]
        );

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
        foreach ([$this->twigInPhpbb()] as $fixes) {
            foreach ($fixes->fixes() as $fix) {
                if ($fix->kind() === Fix::UPGRADE) {
                    self::assertNotSame([], $fix->heldBy());
                }
            }
            foreach ($fixes->branches() as $row) {
                $candidate = $row->candidate();
                if ($candidate !== null && $candidate->kind() === Fix::UPGRADE) {
                    self::assertNotSame([], $candidate->heldBy());
                }
            }
        }
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
     */
    private function find(array $package, array $releases, array $ranges, PhpFloor $floor, array $packages = [], array $devPackages = [], array $root = [], array $extra = []): PackageFixes
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

        return (new FixFinder($floor, LinkIndex::of($lock, ProjectConfig::fromArray($root))))->find(new PackageFacts($locked, $metadata, null, $advisories));
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
        return array_map(static fn (Holder $holder): array => $holder->toArray(), $holders);
    }
}
