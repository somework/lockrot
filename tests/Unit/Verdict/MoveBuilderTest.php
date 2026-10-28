<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use Lockrot\Clock;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Score\IfApplied;
use Lockrot\Security\FixFinder;
use Lockrot\Security\Holder;
use Lockrot\Security\LinkIndex;
use Lockrot\Signal\BranchRow;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveQuery;
use Lockrot\Solver\UncheckedMoves;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\RecordedMoveChecker;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Latest;
use Lockrot\Verdict\Move;
use Lockrot\Verdict\MoveBuilder;
use Lockrot\Verdict\MoveChecks;
use Lockrot\Verdict\MoveContext;
use Lockrot\Verdict\MoveDraft;
use Lockrot\Verdict\MoveFact;
use Lockrot\Verdict\MoveSubject;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame(['result' => MoveCheck::NOT_CHECKED, 'form' => null, 'reason' => MoveCheck::NO_RELEASE_READ, 'installs' => null], $check->jsonSerialize());
    }

    public function testARefusedMoveKeepsTheHoldsOfItsReleaseAddsTheRefusalsAndPrintsNoStep(): void
    {
        $refusal = ['source' => 'package', 'package' => 'c/d', 'version' => '1.0.0', 'link' => 'conflict', 'target' => null, 'constraint' => '>=2.0', 'holder' => null];
        [$row, $context] = self::checked(['*' => ['result' => 'refused', 'form' => null, 'reason' => null, 'holds' => [$refusal]]], true, null, ['a/b' => '^1.0']);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::UPDATE, FlagSet::LEFT_BEHIND)->to($row, $lower, null), [], null, $context);

        self::assertSame(
            [['source' => 'root', 'package' => null, 'version' => null, 'link' => 'require', 'target' => null, 'constraint' => '^1.0', 'holder' => null], $refusal],
            array_map(static fn (Holder $holder): array => $holder->jsonSerialize(), $move->heldBy())
        );
        self::assertSame([['composer', 'why-not', 'a/b', '2.0.0', '--locked']], $move->commands());
    }

    /** composer.json holds the release at ^1.0, and Composer's check of the move settles that hold. */
    public function testAResolvedMoveAssumesNoSolveAndItsStepCarriesTheFormOption(): void
    {
        [$row, $context] = self::checked(['update a/b ^2.0.0 ' => ['result' => 'resolves', 'form' => 'with-dependencies', 'reason' => null, 'holds' => []]], true, null, ['a/b' => '^1.0']);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::UPDATE, FlagSet::LEFT_BEHIND)->to($row, $lower, null), [], null, $context);
        $ifApplied = $move->ifApplied();

        self::assertNotNull($ifApplied);
        self::assertNotContains(IfApplied::COMPOSER_RESOLVES, $ifApplied->assumes());
        self::assertSame([['composer', 'update', 'a/b:^2.0.0', '--with-dependencies']], $move->commands());
    }

    public function testARequireStepCarriesTheFormOption(): void
    {
        [$row, $context] = self::checked(['require a/b ^2.0 ' => ['result' => 'resolves', 'form' => 'with-all-dependencies', 'reason' => null, 'holds' => []]]);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::LEFT_BEHIND)->to($row, $lower, '^2.0'), [], null, $context);

        self::assertSame([['composer', 'require', 'a/b:^2.0', '--with-all-dependencies']], $move->commands());
    }

    /**
     * SPEC 5.6, `version` and `newest`: h/h `<1.3` holds the move's own 1.3.0, so it does not lower
     * `newest`, though the row's candidate 1.1.0 reads 1.2.0.
     */
    public function testARequireOnTheInstalledRowNeverReadsANewestBelowItsVersion(): void
    {
        $releases = [];
        foreach (['1.0.0', '1.1.0', '1.2.0', '1.3.0'] as $version) {
            $release = (new ArrayLoader())->load(['name' => 'a/b', 'version' => $version, 'time' => '2026-01-01T00:00:00+00:00']);
            self::assertInstanceOf(BasePackage::class, $release);
            $releases[] = $release;
        }
        $lock = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'], ['name' => 'h/h', 'version' => '1.0.0', 'require' => ['a/b' => '<1.3']]]]);
        $locked = $lock->find('a/b');
        self::assertNotNull($locked);
        $facts = new PackageFacts($locked, PackageMetadata::fromPackages('a/b', $releases, new \DateTimeImmutable(self::NOW), '1.0.0.0'), null, [new Advisory('A', null, null, null, 'high', null, (new VersionParser())->parseConstraints('<1.1.0'))]);
        $floor = new PhpFloor('8.4');
        $finder = new FixFinder($floor, LinkIndex::of($lock, ProjectConfig::empty()));
        $fixes = $finder->find($facts, $facts->advisories());
        $finding = (new FindingBuilder())->withPackage('a/b')->withVersion('1.0.0')->build();
        $context = new MoveContext($finding, new MoveSubject('a/b', '1.0.0', true, [], '8.4', null), $facts->metadata(), BranchRow::all($facts, $floor, Clock::fixed(self::NOW), null, $fixes), $fixes, $finder, $floor, new \DateTimeImmutable(self::NOW), 2, null, [], [], true);
        $row = $context->installedRow();
        self::assertNotNull($row);
        [$lower, $constraint] = $context->lowestAdmitted($row, '^1.3.0');
        self::assertNotNull($lower);
        self::assertNotNull($constraint);
        $installedRow = $fixes->installedBranch();
        self::assertNotNull($installedRow);

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::OLD_PROMISE)->to($row, $lower, $constraint), [], null, $context);

        self::assertSame(['1.1.0', '1.2.0'], [$installedRow->lowest(), $installedRow->newest()], 'the row');
        self::assertSame(['1.3.0', '1.3.0'], [$move->version(), $move->newest()], 'the move');
        self::assertSame('1.2.0', $context->rowTarget($row), 'a move without its own version reads the row');
    }

    /** The project's php point 7.4 does not run the release, so the request raises php too. */
    public function testARaisePhpMoveAsksTheRequireThatRaisesPhp(): void
    {
        [$row, $context] = self::checked(['require a/b ^2.0 >=8.1' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]], true, '>=8.1', [], '>=7.4');
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::RAISE_PHP, FlagSet::LEFT_BEHIND)->to($row, $lower, '^2.0'), [], null, $context);
        $check = $move->check();

        self::assertNotNull($check);
        self::assertSame(MoveCheck::RESOLVES, $check->result());
        self::assertSame([['composer', 'require', 'php:>=8.1', '--no-update'], ['composer', 'require', 'a/b:^2.0']], $move->commands());
    }

    /** SPEC §5.6: `php_check` reads the newest release on the move's branch, not the lowest one that the constraint admits. */
    public function testThePhpCheckOfARaiseReadsTheNewestReleaseOnTheBranch(): void
    {
        $facts = self::factsOf([['2.0.0', '2026-01-01', '>=8.2'], ['2.1.0', '2026-02-01', '>=8.0']]);
        $floor = new PhpFloor('8.4', '>=7.4');
        $row = BranchRow::all($facts, $floor, Clock::fixed(self::NOW))[0];
        $finding = (new FindingBuilder())->withPackage('a/b')->withVersion('1.0.0')->build();
        $lock = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0']]]);
        $context = new MoveContext($finding, new MoveSubject('a/b', '1.0.0', true, [], '8.4', null), $facts->metadata(), [$row], null, new FixFinder($floor, LinkIndex::of($lock, ProjectConfig::empty())), $floor, new \DateTimeImmutable(self::NOW), 2, null, [], [], true);
        [$lower] = $context->lowestAdmitted($row, '^2.0');
        self::assertNotNull($lower);

        $move = MoveBuilder::build(MoveDraft::of(Move::RAISE_PHP, FlagSet::LEFT_BEHIND)->to($row, $lower, '^2.0'), [], null, $context);
        $check = $move->phpCheck();

        self::assertSame('2.0.0', $move->version());
        self::assertNotNull($check);
        self::assertSame(['>=8.0', '>=8.0'], [$check->requires(), $check->raiseTo()]);
        self::assertSame(['composer', 'require', 'php:>=8.0', '--no-update'], $move->commands()[0]);
    }

    /** A later release of an older minor (an LTS line, as composer/composer 2.2 beside 2.10) is not the release that the move installs. */
    public function testThePhpCheckReadsTheHighestReleaseOnTheBranchNotTheLatestDatedOne(): void
    {
        $facts = self::factsOf([['2.0.0', '2026-01-01', '>=8.2'], ['2.5.0', '2026-02-01', '>=8.0'], ['2.1.9', '2026-03-01', '>=5.3']]);
        $floor = new PhpFloor('8.4', '>=7.4');
        $row = BranchRow::all($facts, $floor, Clock::fixed(self::NOW))[0];
        $finding = (new FindingBuilder())->withPackage('a/b')->withVersion('1.0.0')->build();
        $lock = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0']]]);
        $context = new MoveContext($finding, new MoveSubject('a/b', '1.0.0', true, [], '8.4', null), $facts->metadata(), [$row], null, new FixFinder($floor, LinkIndex::of($lock, ProjectConfig::empty())), $floor, new \DateTimeImmutable(self::NOW), 2, null, [], [], true);
        [$lower] = $context->lowestAdmitted($row, '^2.0');
        self::assertNotNull($lower);

        $check = MoveBuilder::build(MoveDraft::of(Move::RAISE_PHP, FlagSet::LEFT_BEHIND)->to($row, $lower, '^2.0'), [], null, $context)->phpCheck();

        self::assertNotNull($check);
        self::assertSame(['>=8.0', '>=8.0'], [$check->requires(), $check->raiseTo()]);
    }

    /** The update installs 2.5.0 on the target, which needs a higher PHP than require.php admits: the raise comes first. */
    public function testAnUpdateWhoseNewestReleaseNeedsAHigherPhpRaisesItFirst(): void
    {
        $facts = self::factsOf([['2.0.0', '2026-01-01', '>=7.4'], ['2.1.0', '2026-02-01', '>=7.4'], ['2.5.0', '2026-03-01', '>=8.1']]);
        $floor = new PhpFloor('8.4', '>=7.4');
        $row = BranchRow::all($facts, $floor, Clock::fixed(self::NOW))[0];
        $finding = (new FindingBuilder())->withPackage('a/b')->withVersion('1.0.0')->build();
        $lock = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0']]]);
        $context = (new MoveContext($finding, new MoveSubject('a/b', '1.0.0', true, [], '8.4', '7.4.0'), $facts->metadata(), [$row], null, new FixFinder($floor, LinkIndex::of($lock, ProjectConfig::empty())), $floor, new \DateTimeImmutable(self::NOW), 2, null, [], [], true))
            ->withChecks(MoveChecks::of(new RecordedMoveChecker(['update a/b ^2.1.0 >=8.1' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]], [])));
        [$lower] = $context->lowestAdmitted($row, '^2.1');
        self::assertNotNull($lower);

        $move = MoveBuilder::build(MoveDraft::of(Move::UPDATE, FlagSet::VULNERABLE)->to($row, $lower, null), [], null, $context);
        $check = $move->check();

        self::assertNotNull($check);
        self::assertSame(MoveCheck::RESOLVES, $check->result(), 'the query sends the raise');
        self::assertSame([['composer', 'require', 'php:>=8.1', '--no-update'], ['composer', 'update', 'a/b:^2.1.0']], $move->commands());
        self::assertStringStartsWith('raise require.php to >=8.1, then composer update \'a/b:^2.1.0\' (gets 2.5.0)', $move->text());
    }

    /** The tag needs a higher PHP than require.php admits: as on every kind, the raise comes first. */
    public function testATagMoveWhoseTagNeedsAHigherPhpRaisesItFirst(): void
    {
        $facts = self::factsOf([['2.0.0', '2026-01-01', '>=8.1']]);
        $floor = new PhpFloor('8.4', '>=7.4');
        $row = BranchRow::all($facts, $floor, Clock::fixed(self::NOW))[0];
        $finding = (new FindingBuilder())->withPackage('a/b')->withVersion('1.0.0')->build();
        $lock = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0']]]);
        $context = (new MoveContext($finding, new MoveSubject('a/b', '1.x-dev', true, [], '8.4', '7.4.0'), $facts->metadata(), [$row], null, new FixFinder($floor, LinkIndex::of($lock, ProjectConfig::empty())), $floor, new \DateTimeImmutable(self::NOW), 2, null, [], [], true))
            ->withChecks(MoveChecks::of(new RecordedMoveChecker(['require a/b ^2.0.0 >=8.1' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]], [])));

        $move = MoveBuilder::build(MoveDraft::of(Move::TAG, FlagSet::PINNED)->withLatest(new Latest('2.0.0', '2026-01-01T00:00:00+00:00', false, 'newer', null), $row), [], null, $context);
        $check = $move->check();

        self::assertNotNull($check);
        self::assertSame(MoveCheck::RESOLVES, $check->result(), 'the query sends the raise');
        self::assertSame([['composer', 'require', 'php:>=8.1', '--no-update'], ['composer', 'require', 'a/b:^2.0.0']], $move->commands());
        self::assertStringStartsWith('raise require.php to >=8.1, then require a tag — the newest is 2.0.0', $move->text());
    }

    /** A package that the project does not require gets the raise before its tag too. */
    public function testATransitiveTagMoveWhoseTagNeedsAHigherPhpRaisesItFirst(): void
    {
        $facts = self::factsOf([['2.0.0', '2026-01-01', '>=8.1']]);
        $floor = new PhpFloor('8.4', '>=7.4');
        $row = BranchRow::all($facts, $floor, Clock::fixed(self::NOW))[0];
        $finding = (new FindingBuilder())->withPackage('a/b')->withVersion('1.0.0')->build();
        $lock = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0']]]);
        $context = new MoveContext($finding, new MoveSubject('a/b', '1.x-dev', false, ['c/d'], '8.4', '7.4.0'), $facts->metadata(), [$row], null, new FixFinder($floor, LinkIndex::of($lock, ProjectConfig::empty())), $floor, new \DateTimeImmutable(self::NOW), 2, null, [], [], true);

        $move = MoveBuilder::build(MoveDraft::of(Move::TAG, FlagSet::PINNED)->withLatest(new Latest('2.0.0', '2026-01-01T00:00:00+00:00', false, 'newer', null), $row), [], null, $context);

        self::assertStringStartsWith('raise require.php to >=8.1, then move it to a tag — the newest is 2.0.0, through c/d', $move->text());
    }

    /** 2.0.0, the release that the move takes, needs PHP 8.2, which the target 8.1 does not run: a later release on the branch does not move that PHP. */
    public function testABlockedMoveNamesThePhpOfTheReleaseThatBlocksIt(): void
    {
        $facts = self::factsOf([['2.0.0', '2026-01-01', '>=8.2'], ['2.1.0', '2026-02-01', '>=8.0']]);
        $floor = new PhpFloor('8.1', '>=7.4');
        $row = BranchRow::all($facts, $floor, Clock::fixed(self::NOW))[0];
        $finding = (new FindingBuilder())->withPackage('a/b')->withVersion('1.0.0')->build();
        $lock = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0']]]);
        $context = new MoveContext($finding, new MoveSubject('a/b', '1.0.0', true, [], '8.1', '7.4.0'), $facts->metadata(), [$row], null, new FixFinder($floor, LinkIndex::of($lock, ProjectConfig::empty())), $floor, new \DateTimeImmutable(self::NOW), 2, null, [], [], true);
        [$lower] = $context->lowestAdmitted($row, '^2.0');
        self::assertNotNull($lower);

        $move = MoveBuilder::build(MoveDraft::of(Move::BLOCKED, FlagSet::VULNERABLE)->to($row, $lower, null), [], null, $context);
        $check = $move->phpCheck();

        self::assertNotNull($check);
        self::assertSame(['>=8.2', false], [$check->requires(), $check->targetRuns()]);
        self::assertStringStartsWith('no move runs on the target: 2.x needs php >=8.2 and the target php 8.1 does not run it', $move->text());
    }

    /** composer.json does not require a/b: a resolved request becomes `composer update` with the constraint inline. */
    public function testATransitiveRequireThatComposerResolvesBecomesAnInlineUpdate(): void
    {
        [$row, $context] = self::checked(['update a/b ^2.0 ' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]], false);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::LEFT_BEHIND)->to($row, $lower, '^2.0'), [], null, $context);

        self::assertSame([['composer', 'update', 'a/b:^2.0']], $move->commands());
    }

    public function testAMoveThatComposerDidNotCheckAssumesThatComposerResolvesItsHold(): void
    {
        [$row, $context] = self::checked(['*' => ['result' => 'not_checked', 'form' => null, 'reason' => 'offline', 'holds' => []]], true, null, ['a/b' => '^1.0']);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $ifApplied = MoveBuilder::build(MoveDraft::of(Move::UPDATE, FlagSet::LEFT_BEHIND)->to($row, $lower, null), [], null, $context)->ifApplied();

        self::assertNotNull($ifApplied);
        self::assertContains(IfApplied::COMPOSER_RESOLVES, $ifApplied->assumes());
    }

    public function testATransitiveRequireStepCarriesTheFormOption(): void
    {
        [$row, $context] = self::checked(['update a/b ^2.0 ' => ['result' => 'resolves', 'form' => 'with-dependencies', 'reason' => null, 'holds' => []]], false);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::LEFT_BEHIND)->to($row, $lower, '^2.0'), [], null, $context);

        self::assertSame([['composer', 'update', 'a/b:^2.0', '--with-dependencies']], $move->commands());
    }

    /** An advisory with no range read is left out, and the next one is still judged. */
    public function testAMoveClearsAnAdvisoryOutsideItsRangeAfterOneWithNoRange(): void
    {
        [$row, $context] = self::checked([], true, null, [], null, ['GHSA-out' => (new VersionParser())->parseConstraints('<1.5')]);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        [$clears] = MoveBuilder::clearsFor($row, $lower, [], ['GHSA-unread', 'GHSA-out'], $context);

        self::assertSame([['kind' => 'advisory', 'id' => 'GHSA-out', 'basis' => 'outside_range']], array_map(static fn (MoveFact $fact): array => $fact->jsonSerialize(), $clears));
    }

    public function testATransitiveRequireThatIsNotCheckedGetsOnlyItsWhyNotStep(): void
    {
        [$row, $context] = self::checked(['*' => ['result' => 'not_checked', 'form' => null, 'reason' => 'offline', 'holds' => []]], false);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::LEFT_BEHIND)->to($row, $lower, '^2.0'), [], null, $context);

        self::assertSame([['composer', 'why-not', 'a/b', '2.0.0', '--locked']], $move->commands());
    }

    /** monica's firebase/php-jwt: Composer refuses the move alone, and one request of the package and its holder h/x resolves it. */
    public function testARootRequirementThatAJointRequestResolvesWritesItThenUpdatesThePackageWithItsHolders(): void
    {
        [$row, $context] = self::checked(self::refusedThenJoint('require a/b ^2.0 '), true, null, ['a/b' => '^1.0']);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::LEFT_BEHIND)->to($row, $lower, '^2.0'), [], null, $context);
        $check = $move->check();

        self::assertNotNull($check);
        self::assertSame(
            [MoveCheck::RESOLVES, [], [['package' => 'h/x', 'installed_version' => '1.0.0', 'version' => '2.1.0']], [['composer', 'require', 'a/b:^2.0', '--no-update'], ['composer', 'update', 'a/b', 'h/x']]],
            [$check->result(), $move->heldBy(), $move->movesWith(), $move->commands()]
        );
    }

    public function testAnUpdateThatAJointRequestResolvesNamesItsHoldersInTheSameStep(): void
    {
        [$row, $context] = self::checked(self::refusedThenJoint('update a/b ^2.0.0 '), false);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::UPDATE, FlagSet::LEFT_BEHIND)->to($row, $lower, null), [], null, $context);

        self::assertSame([['composer', 'update', 'a/b:^2.0.0', 'h/x']], $move->commands());
    }

    public function testAPackageThatOnlyRequireDevListsGetsTheRequireCommandOfThatSection(): void
    {
        [$row, $context] = self::checked(['require a/b ^2.0 ' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]], false, null, [], null, [], ['require-dev']);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::LEFT_BEHIND)->to($row, $lower, '^2.0'), [], null, $context);

        $check = $move->check();

        self::assertNotNull($check);
        self::assertSame([MoveCheck::RESOLVES, [['composer', 'require', 'a/b:^2.0', '--dev']]], [$check->result(), $move->commands()]);
    }

    public function testANameThatBothSectionsListGetsNoInteractionAndTheSpellingOfItsRequireDevKey(): void
    {
        $keys = [ProjectConfig::REQUIRE => 'a/b', ProjectConfig::REQUIRE_DEV => 'A/b'];
        [$row, $context] = self::checked(['require a/b ^2.0 ' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]], true, null, [], null, [], [ProjectConfig::REQUIRE, ProjectConfig::REQUIRE_DEV], $keys);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::LEFT_BEHIND)->to($row, $lower, '^2.0'), [], null, $context);

        self::assertSame([['composer', 'require', 'A/b:^2.0', '--no-interaction']], $move->commands());
    }

    public function testANameThatBothSectionsListWritesItsRequirementBeforeTheUpdateWithItsHolders(): void
    {
        $keys = [ProjectConfig::REQUIRE => 'a/b', ProjectConfig::REQUIRE_DEV => 'a/b'];
        [$row, $context] = self::checked(self::refusedThenJoint('require a/b ^2.0 '), true, null, [], null, [], [ProjectConfig::REQUIRE, ProjectConfig::REQUIRE_DEV], $keys);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::LEFT_BEHIND)->to($row, $lower, '^2.0'), [], null, $context);

        self::assertSame([['composer', 'require', 'a/b:^2.0', '--no-interaction', '--no-update'], ['composer', 'update', 'a/b', 'h/x']], $move->commands());
    }

    public function testAPhpRaiseThatRequireDevListsTakesNoInteractionAndTheSpellingOfThatKey(): void
    {
        $phpKeys = [ProjectConfig::REQUIRE => 'php', ProjectConfig::REQUIRE_DEV => 'PHP'];
        [$row, $context] = self::checked(['require a/b ^2.0 >=8.1' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]], true, '>=8.1', [], '>=7.4', [], [ProjectConfig::REQUIRE], [ProjectConfig::REQUIRE => 'a/b'], $phpKeys);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::RAISE_PHP, FlagSet::LEFT_BEHIND)->to($row, $lower, '^2.0'), [], null, $context);

        self::assertSame([['composer', 'require', 'PHP:>=8.1', '--no-interaction', '--no-update'], ['composer', 'require', 'a/b:^2.0']], $move->commands());
    }

    /**
     * The section that the solved root writes a/b in is the section that the printed step writes it
     * in: `--dev` alone moves it to require-dev, and `--no-interaction` keeps it in require.
     *
     * @dataProvider listings
     *
     * @param array<string, string> $keys
     * @param list<string>          $options
     * @param array<string, string> $devChanges
     */
    #[DataProvider('listings')]
    public function testTheCheckedRequestWritesTheSectionThatThePrintedStepWrites(array $keys, array $options, array $devChanges): void
    {
        [$row, $context, $checker] = self::checked(['require a/b ^2.0 ' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]], true, null, [], null, [], array_keys($keys), $keys);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::LEFT_BEHIND)->to($row, $lower, '^2.0'), [], null, $context);

        self::assertSame([array_merge(['composer', 'require', 'a/b:^2.0'], $options)], $move->commands());
        self::assertNotSame([], $checker->queries());
        self::assertSame(array_fill(0, \count($checker->queries()), $devChanges), array_map(static fn (MoveQuery $query): array => $query->request()->devChanges(), $checker->queries()));
    }

    /** @return iterable<string, array{array<string, string>, list<string>, array<string, string>}> */
    public static function listings(): iterable
    {
        yield 'require only' => [[ProjectConfig::REQUIRE => 'a/b'], [], []];
        yield 'require-dev only' => [[ProjectConfig::REQUIRE_DEV => 'a/b'], ['--dev'], ['a/b' => 'a/b']];
        yield 'both sections' => [[ProjectConfig::REQUIRE => 'a/b', ProjectConfig::REQUIRE_DEV => 'a/b'], ['--no-interaction'], []];
    }

    /** A literal require-dev key chooses `--dev`, though `named_in` holds `require` through a provided name. */
    public function testALiteralRequireDevKeyChoosesDevOverTheNamedInOfAProvidedName(): void
    {
        [$row, $context] = self::checked(['require a/b ^2.0 ' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]], false, null, [], null, [], [ProjectConfig::REQUIRE], [ProjectConfig::REQUIRE_DEV => 'a/b']);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::LEFT_BEHIND)->to($row, $lower, '^2.0'), [], null, $context);

        self::assertSame([['composer', 'require', 'a/b:^2.0', '--dev']], $move->commands());
    }

    /** SPEC 5.6: `transitive` reads `direct`, so a require-dev name in a run without --dev keeps it (case require-dev-name). */
    public function testARequireDevNameThatTheRunDoesNotReachDirectlyMovesBecauseItIsTransitive(): void
    {
        [$row, $context] = self::checked(['require a/b ^2.0 ' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]], false, null, [], null, [], ['require-dev']);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::LEFT_BEHIND)->to($row, $lower, '^2.0'), [], null, $context);

        self::assertContains(Move::TRANSITIVE, $move->composerMovesBecause());
    }

    public function testAJointSolveThatChangesNoHolderKeepsTheMoveRefusedWithOnlyTheWhyNotStep(): void
    {
        $checks = self::refusedThenJoint('update a/b ^2.0.0 ');
        $checks['joint update a/b ^2.0.0 with h/x']['installs'] = ['a/b' => '2.0.0'];
        [$row, $context] = self::checked($checks, false);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::UPDATE, FlagSet::LEFT_BEHIND)->to($row, $lower, null), [], null, $context);
        $check = $move->check();

        self::assertNotNull($check);
        self::assertSame(
            [MoveCheck::REFUSED, ['h/x'], [], [['composer', 'why-not', 'a/b', '2.0.0', '--locked']]],
            [$check->result(), array_map(static fn (Holder $holder): ?string => $holder->package(), $move->heldBy()), $move->movesWith(), $move->commands()]
        );
    }

    /** `transitive` stays a fact of the move: the through note explains the joint request. */
    public function testATransitiveMoveThatTheJointRequestResolvesKeepsItsTransitiveReason(): void
    {
        [$row, $context] = self::checked(self::refusedThenJoint('update a/b ^2.0 '), false);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::LEFT_BEHIND)->to($row, $lower, '^2.0'), [], null, $context);

        self::assertSame([[['package' => 'h/x', 'installed_version' => '1.0.0', 'version' => '2.1.0']], [Move::CROSSES_MAJOR, Move::TRANSITIVE]], [$move->movesWith(), $move->composerMovesBecause()]);
    }

    /** @return array<string, array<string, mixed>> the refusal of $key by the holder h/x 1.0.0, and the joint request that moves h/x to 2.1.0 */
    private static function refusedThenJoint(string $key): array
    {
        return [
            $key => [
                'result' => 'refused', 'form' => null, 'reason' => null,
                'holds' => [['source' => 'package', 'package' => 'h/x', 'version' => '1.0.0', 'link' => 'require', 'target' => null, 'constraint' => '^1.0', 'holder' => null]],
            ],
            'joint '.rtrim($key).' with h/x' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'installs' => ['a/b' => '2.0.0', 'h/x' => '2.1.0'], 'holds' => []],
        ];
    }

    /**
     * a/b 1.0.0 in the lock, its 2.x row and the move's context.
     *
     * @param array<string, array<mixed, mixed>> $checks the recorded checks ({@see RecordedMoveChecker})
     * @param array<string, string>              $require composer.json's `require`
     * @param array<string, ConstraintInterface> $ranges  each advisory's affected range, by id
     * @param list<string>                       $namedIn the composer.json sections that list a/b
     * @param array<string, string>              $keys    the literal keys of a/b and of php, by section ({@see ProjectConfig::listedIn()})
     * @param array<string, string>              $phpKeys
     *
     * @return array{0: BranchRow, 1: MoveContext, 2: RecordedMoveChecker}
     */
    private static function checked(array $checks, bool $direct = true, ?string $php = null, array $require = [], ?string $projectPhp = null, array $ranges = [], array $namedIn = [], array $keys = [], array $phpKeys = []): array
    {
        $facts = self::facts($php);
        $floor = new PhpFloor('8.4', $projectPhp);
        $row = BranchRow::all($facts, $floor, Clock::fixed(self::NOW))[0];
        $finding = (new FindingBuilder())->withPackage('a/b')->withVersion('1.0.0')->withVerdict(Verdict::LEFT_BEHIND)->build();
        $lock = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0']]]);
        $project = ProjectConfig::fromArray(['require' => $require]);
        $checker = new RecordedMoveChecker($checks, []);
        $context = (new MoveContext($finding, (new MoveSubject('a/b', '1.0.0', $direct, $direct ? [] : ['c/d'], '8.4', null))->withNamedIn($namedIn)->withKeys($keys, $phpKeys), $facts->metadata(), [$row], null, new FixFinder($floor, LinkIndex::of($lock, $project)), $floor, new \DateTimeImmutable(self::NOW), 2, null, $ranges, [], true))
            ->withChecks(MoveChecks::of($checker));

        return [$row, $context, $checker];
    }

    private static function row(): BranchRow
    {
        return BranchRow::all(self::facts(null), new PhpFloor('8.4'), Clock::fixed(self::NOW))[0];
    }

    /** @param list<array{string, string, string}> $releases each release of a/b: its version, date and php */
    private static function factsOf(array $releases): PackageFacts
    {
        $packages = [];
        foreach ($releases as [$version, $date, $php]) {
            $release = (new ArrayLoader())->load(['name' => 'a/b', 'version' => $version, 'time' => $date.'T00:00:00+00:00', 'require' => ['php' => $php]]);
            self::assertInstanceOf(BasePackage::class, $release);
            $packages[] = $release;
        }
        $locked = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]])->find('a/b');
        self::assertNotNull($locked);

        return new PackageFacts($locked, PackageMetadata::fromPackages('a/b', $packages, new \DateTimeImmutable(self::NOW), '1.0.0.0'), null, []);
    }

    /** @param ?string $php the php that release 2.0.0 requires */
    private static function facts(?string $php): PackageFacts
    {
        $release = (new ArrayLoader())->load(['name' => 'a/b', 'version' => '2.0.0', 'time' => '2026-01-01T00:00:00+00:00'] + ($php === null ? [] : ['require' => ['php' => $php]]));
        self::assertInstanceOf(BasePackage::class, $release);
        $locked = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]])->find('a/b');
        self::assertNotNull($locked);

        return new PackageFacts($locked, PackageMetadata::fromPackages('a/b', [$release], new \DateTimeImmutable(self::NOW), '1.0.0.0'), null, []);
    }
}
