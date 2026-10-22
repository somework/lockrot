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
use Lockrot\Signal\PhpCap;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Latest;
use Lockrot\Verdict\Move;
use Lockrot\Verdict\MoveBuilder;
use Lockrot\Verdict\MoveContext;
use Lockrot\Verdict\MoveDraft;
use Lockrot\Verdict\MoveSubject;
use PHPUnit\Framework\TestCase;

/** What a move says about `capped`: it clears it, or it lands on a release capped below R (docs/verdicts.md#capped). */
final class CappedMoveTest extends TestCase
{
    private const NOW = '2026-10-08T00:00:00+00:00';
    private const KNOWN = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];
    private const CAPPED_PHP = '>=7.4, <8.5';

    public function testAnUpdateToAReleaseCappedBelowTheNewestPhpAddsCapped(): void
    {
        [$row, $context] = self::context([], ['1.1.0' => self::CAPPED_PHP]);
        [$lower] = $context->lowestAdmitted($row, '^1.1.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::UPDATE, FlagSet::LEFT_BEHIND)->to($row, $lower, null), [], null, $context);

        self::assertSame([['kind' => 'flag', 'id' => 'capped', 'basis' => 'stops_below_newest_php']], JsonPath::decoded($move->adds()));
        self::assertStringContainsString('; it stops below the newest PHP, so capped would count', $move->text());
    }

    public function testARequireMoveToAReleaseCappedBelowTheNewestPhpAddsCapped(): void
    {
        [$row, $context] = self::context([], ['2.0.0' => self::CAPPED_PHP]);
        [$lower, $constraint] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::LEFT_BEHIND)->to($row, $lower, $constraint), [], null, $context);

        self::assertSame([['kind' => 'flag', 'id' => 'capped', 'basis' => 'stops_below_newest_php']], JsonPath::decoded($move->adds()));
        self::assertStringStartsWith('require a/b ^2.0; it stops below the newest PHP, so capped would count; 2.x needs php >=7.4, <8.5;', $move->text());
    }

    public function testATagMoveToAReleaseCappedBelowTheNewestPhpAddsCapped(): void
    {
        [$row, $context] = self::context([[Signal::S6, []]], ['2.0.0' => self::CAPPED_PHP], 'dev-main');

        $move = MoveBuilder::build(MoveDraft::of(Move::TAG, FlagSet::PINNED)->withLatest(new Latest('2.0.0', '2026-01-01T00:00:00+00:00', false, null, null), $row), [], null, $context);

        self::assertContains(['kind' => 'flag', 'id' => 'capped', 'basis' => 'stops_below_newest_php'], JsonPath::decoded($move->adds()));
        self::assertStringEndsWith('; it stops below the newest PHP, so capped would count', $move->text());
    }

    public function testATagMoveToAReleaseThatAdmitsTheNewestPhpClearsCappedBesidePinned(): void
    {
        [$row, $context] = self::context([[Signal::S6, []], [Signal::S11, []]], ['2.0.0' => '>=8.1'], 'dev-main');

        $move = MoveBuilder::build(MoveDraft::of(Move::TAG, FlagSet::PINNED)->withLatest(new Latest('2.0.0', '2026-01-01T00:00:00+00:00', false, null, null), $row), [], null, $context);

        self::assertContains(['kind' => 'flag', 'id' => 'capped', 'basis' => 'admits_newest_php'], JsonPath::decoded($move->clears()));
        self::assertContains(['kind' => 'flag', 'id' => 'pinned', 'basis' => 'tags_only'], JsonPath::decoded($move->clears()));
    }

    /** The tag's own undated risk and the release's missing php both stay unverified. */
    public function testATagMoveKeepsItsOwnUnverifiedFactsBesideCapped(): void
    {
        [$row, $context] = self::context([[Signal::S6, []], [Signal::S11, []]], ['2.0.0' => null], 'dev-main');

        $move = MoveBuilder::build(MoveDraft::of(Move::TAG, FlagSet::PINNED)->withLatest(new Latest('2.0.0', null, false, null, null), $row), [], null, $context);

        self::assertSame([['kind' => 'flag', 'id' => 'old-promise', 'reason' => 'undated'], ['kind' => 'flag', 'id' => 'capped', 'reason' => 'no_release_data']], JsonPath::decoded($move->unverified()));
    }

    /** `old-promise` and `capped` never fire together on a lock, so the finding is built with both to reach the merge. */
    public function testARowMoveKeepsTheUnverifiedFactsOfEachFlagItClears(): void
    {
        [$row, $context] = self::context([[Signal::S5, []], [Signal::S11, []]], ['2.0.0' => null]);
        [$lower, $constraint] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::CAPPED)->to($row, $lower, $constraint), [], null, $context);

        self::assertSame([['kind' => 'flag', 'id' => 'old-promise', 'reason' => 'undated'], ['kind' => 'flag', 'id' => 'capped', 'reason' => 'no_release_data']], JsonPath::decoded($move->unverified()));
    }

    public function testWithPhpCapLeavesTheContextItCopiesUnchanged(): void
    {
        [, $context] = self::context([], ['2.0.0' => null]);

        $none = $context->withPhpCap(PhpCap::none());

        self::assertSame(['8.5', null], [$context->phpCap()->newest(), $none->phpCap()->newest()]);
    }

    public function testAMoveAddsNoCappedThatAlreadyFired(): void
    {
        [$row, $context] = self::context([[Signal::S11, []]], ['2.0.0' => self::CAPPED_PHP]);
        [$lower, $constraint] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::CAPPED)->to($row, $lower, $constraint), [], null, $context);

        self::assertSame([], $move->adds());
    }

    public function testAMoveToAReleaseThatAdmitsTheNewestPhpClearsCapped(): void
    {
        [$row, $context] = self::context([[Signal::S11, []]], ['2.0.0' => '>=8.1']);
        [$lower, $constraint] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::CAPPED)->to($row, $lower, $constraint), [], null, $context);

        self::assertSame([['kind' => 'flag', 'id' => 'capped', 'basis' => 'admits_newest_php']], JsonPath::decoded($move->clears()));
        self::assertStringStartsWith('require a/b ^2.0 — clears capped;', $move->text());
    }

    /** A release that states no php is no answer: the move cannot clear `capped`. */
    public function testAMoveToAReleaseThatStatesNoPhpLeavesCappedUnverified(): void
    {
        [$row, $context] = self::context([[Signal::S11, []]], ['2.0.0' => null]);
        [$lower, $constraint] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of(Move::REQUIRE, FlagSet::CAPPED)->to($row, $lower, $constraint), [], null, $context);

        self::assertSame([['kind' => 'flag', 'id' => 'capped', 'reason' => 'no_release_data']], JsonPath::decoded($move->unverified()));
    }

    public function testNoMoveForWantOfAReleaseThatAdmitsTheNewestPhpNamesTheInstalledVersionAndTheNewestPhp(): void
    {
        [, $context] = self::context([[Signal::S11, []]], ['2.0.0' => self::CAPPED_PHP]);

        $move = MoveBuilder::build(MoveDraft::of(Move::NO_MOVE, FlagSet::CAPPED)->withReason('no_release_admits'), [], null, $context);

        self::assertSame('no move: no release above 1.0.0 admits PHP 8.5', $move->text());
    }

    /**
     * a/b at $installed with the signals given, its release rows, and the move's context, at target 8.4 and with R at 8.5.
     *
     * @param list<array{string, array<string, mixed>}> $signals  each signal's id and data
     * @param array<string, ?string>                     $releases by version, the php that each requires
     *
     * @return array{0: BranchRow, 1: MoveContext}
     */
    private static function context(array $signals, array $releases, string $installed = '1.0.0'): array
    {
        $packages = [];
        foreach ($releases as $version => $php) {
            $release = (new ArrayLoader())->load(['name' => 'a/b', 'version' => (string) $version, 'time' => '2026-01-01T00:00:00+00:00'] + ($php === null ? [] : ['require' => ['php' => $php]]));
            self::assertInstanceOf(BasePackage::class, $release);
            $packages[] = $release;
        }
        $lock = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => $installed, 'notification-url' => 'https://packagist.org/downloads/']]]);
        $locked = $lock->find('a/b');
        self::assertNotNull($locked);
        $facts = new PackageFacts($locked, PackageMetadata::fromPackages('a/b', $packages, new \DateTimeImmutable(self::NOW), $installed), null, []);
        $floor = new PhpFloor('8.4');
        $raised = array_map(static fn (array $s): Signal => new Signal(Signal::LEVEL_WARN, $s[0], SignalDataStub::of($s[0], $s[1])), $signals);
        $finding = (new FindingBuilder())->withPackage('a/b')->withVersion($installed)->withSignals($raised)->withFlags(FlagSet::fromSignals($raised, null, []))->build();
        $rows = BranchRow::all($facts, $floor, Clock::fixed(self::NOW));
        $cap = new PhpCap(self::KNOWN);
        $context = (new MoveContext($finding, new MoveSubject('a/b', $installed, true, [], '8.4', null, $cap->newest()), $facts->metadata(), $rows, null, new FixFinder($floor, LinkIndex::of($lock, ProjectConfig::empty())), $floor, new \DateTimeImmutable(self::NOW), 2, null, [], [], true))
            ->withPhpCap($cap);
        self::assertNotSame([], $rows);

        return [$rows[0], $context];
    }
}
