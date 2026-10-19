<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use Lockrot\Clock;
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
use Lockrot\Solver\UncheckedMoves;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\RecordedMoveChecker;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Move;
use Lockrot\Verdict\MoveBuilder;
use Lockrot\Verdict\MoveChecks;
use Lockrot\Verdict\MoveContext;
use Lockrot\Verdict\MoveDraft;
use Lockrot\Verdict\MoveFact;
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

    public function testARefusedMoveKeepsTheHoldsOfItsReleaseAddsTheRefusalsAndPrintsNoStep(): void
    {
        $refusal = ['source' => 'package', 'package' => 'c/d', 'version' => '1.0.0', 'link' => 'conflict', 'target' => null, 'constraint' => '>=2.0', 'holder' => null];
        [$row, $context] = self::checked(['*' => ['result' => 'refused', 'form' => null, 'reason' => null, 'holds' => [$refusal]]], true, true, null, ['a/b' => '^1.0']);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::UPDATE, FlagSet::LEFT_BEHIND)->to($row, $lower, null), [], null, $context);

        self::assertSame(
            [['source' => 'root', 'package' => null, 'version' => null, 'link' => 'require', 'target' => null, 'constraint' => '^1.0', 'holder' => null], $refusal],
            array_map(static fn (Holder $holder): array => $holder->jsonSerialize(), $move->heldBy())
        );
        self::assertSame([], $move->commands());
    }

    /** composer.json holds the release at ^1.0, and Composer's check of the move settles that hold. */
    public function testAResolvedMoveAssumesNoSolveAndItsStepCarriesTheFormOption(): void
    {
        [$row, $context] = self::checked(['update a/b ^2.0.0 ' => ['result' => 'resolves', 'form' => 'with-dependencies', 'reason' => null, 'holds' => []]], true, true, null, ['a/b' => '^1.0']);
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

    /** The project's php point 7.4 does not run the release, so the request raises php too. */
    public function testARaisePhpMoveAsksTheRequireThatRaisesPhp(): void
    {
        [$row, $context] = self::checked(['require a/b ^2.0 >=8.1' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]], true, true, '>=8.1', [], '>=7.4');
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::RAISE_PHP, FlagSet::LEFT_BEHIND)->to($row, $lower, '^2.0'), [], null, $context);
        $check = $move->check();

        self::assertNotNull($check);
        self::assertSame(MoveCheck::RESOLVES, $check->result());
        self::assertSame([['composer', 'require', 'php:>=8.1', '--no-update'], ['composer', 'require', 'a/b:^2.0']], $move->commands());
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
        [$row, $context] = self::checked(['*' => ['result' => 'not_checked', 'form' => null, 'reason' => 'offline', 'holds' => []]], true, true, null, ['a/b' => '^1.0']);
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
        [$row, $context] = self::checked([], true, true, null, [], null, ['GHSA-out' => (new VersionParser())->parseConstraints('<1.5')]);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        [$clears] = MoveBuilder::clearsFor($row, $lower, [], ['GHSA-unread', 'GHSA-out'], $context);

        self::assertSame([['kind' => 'advisory', 'id' => 'GHSA-out', 'basis' => 'outside_range']], array_map(static fn (MoveFact $fact): array => $fact->jsonSerialize(), $clears));
    }

    /** Composer 2.2 and 2.3 refuse an inline constraint on a package that composer.json does not require. */
    public function testATransitiveRequireGetsNoStepOnAComposerWithoutInlineConstraints(): void
    {
        [$row, $context] = self::checked(['update a/b ^2.0 ' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]], false, false);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::LEFT_BEHIND)->to($row, $lower, '^2.0'), [], null, $context);

        self::assertSame([], $move->commands());
    }

    public function testATransitiveRequireThatIsNotCheckedGetsNoStep(): void
    {
        [$row, $context] = self::checked(['*' => ['result' => 'not_checked', 'form' => null, 'reason' => 'offline', 'holds' => []]], false);
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::LEFT_BEHIND)->to($row, $lower, '^2.0'), [], null, $context);

        self::assertSame([], $move->commands());
    }

    /**
     * a/b 1.0.0 in the lock, its 2.x row and the move's context.
     *
     * @param array<string, array<mixed, mixed>> $checks the recorded checks ({@see RecordedMoveChecker})
     * @param array<string, string>              $require composer.json's `require`
     * @param array<string, ConstraintInterface> $ranges  each advisory's affected range, by id
     *
     * @return array{0: BranchRow, 1: MoveContext}
     */
    private static function checked(array $checks, bool $direct = true, bool $inlineOnAnyPackage = true, ?string $php = null, array $require = [], ?string $projectPhp = null, array $ranges = []): array
    {
        $facts = self::facts($php);
        $floor = new PhpFloor('8.4', $projectPhp);
        $row = BranchRow::all($facts, $floor, Clock::fixed(self::NOW))[0];
        $finding = (new FindingBuilder())->withPackage('a/b')->withVersion('1.0.0')->build();
        $lock = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0']]]);
        $project = ProjectConfig::fromArray(['require' => $require]);
        $context = (new MoveContext($finding, new MoveSubject('a/b', '1.0.0', $direct, $direct ? [] : ['c/d'], '8.4', null), $facts->metadata(), [$row], null, new FixFinder($floor, LinkIndex::of($lock, $project)), $floor, new \DateTimeImmutable(self::NOW), 2, null, $ranges, [], true))
            ->withChecks(new MoveChecks(new RecordedMoveChecker($checks, []), $inlineOnAnyPackage));

        return [$row, $context];
    }

    private static function row(): BranchRow
    {
        return BranchRow::all(self::facts(null), new PhpFloor('8.4'), Clock::fixed(self::NOW))[0];
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
