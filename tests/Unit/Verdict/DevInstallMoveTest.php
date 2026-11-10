<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackage;
use Composer\Repository\PlatformRepository;
use Composer\Semver\VersionParser;
use Lockrot\Clock;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Score\CountedAdvisory;
use Lockrot\Security\Fix;
use Lockrot\Security\FixFinder;
use Lockrot\Security\LinkIndex;
use Lockrot\Signal\BranchRow;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Signal;
use Lockrot\Solver\ComposerSolver;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveChecker;
use Lockrot\Solver\MoveQuery;
use Lockrot\Solver\SolverChecker;
use Lockrot\Tests\Support\ArrayVersionSource;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\RecordedMoveChecker;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Latest;
use Lockrot\Verdict\Move;
use Lockrot\Verdict\MoveBuilder;
use Lockrot\Verdict\MoveChecks;
use Lockrot\Verdict\MoveContext;
use Lockrot\Verdict\MoveDraft;
use Lockrot\Verdict\MoveSubject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ruling R17-1: a form whose solve keeps the moved package or installs a dev version of it does
 * not resolve the move. At minimum-stability dev without prefer-stable, Composer prefers a branch
 * alias inside the caret over every tag, so each form keeps or installs dev-main.
 */
final class DevInstallMoveTest extends TestCase
{
    private const NOW = '2026-10-08T00:00:00+00:00';
    private const PHP = '8.4.99';
    private const ALIAS = ['extra' => ['branch-alias' => ['dev-main' => '1.x-dev']]];

    /** @return iterable<string, array{string}> */
    public static function remoteCommits(): iterable
    {
        yield 'the lock holds the newest commit, so Composer keeps dev-main' => ['aaaa'];
        yield 'the remote holds a newer commit, so Composer installs dev-main' => ['bbbb'];
    }

    /**
     * acme/other is pinned to dev-main, and the tag move `^1.2.3` keeps or installs dev-main in every
     * form. The move is not checked, and its text says that the command does not reach a release.
     *
     * @dataProvider remoteCommits
     */
    #[DataProvider('remoteCommits')]
    public function testATagMoveThatEveryFormSolvesToTheBranchAliasIsNotCheckedAndNamesTheDevVersion(string $commit): void
    {
        $package = 'acme/other';
        $source = static fn (string $reference): array => ['source' => ['type' => 'git', 'url' => 'https://example.org/other.git', 'reference' => $reference]];
        $lock = LockFile::fromArray(['packages' => [['name' => $package, 'version' => 'dev-main', 'notification-url' => 'https://packagist.org/downloads/'] + $source('aaaa') + self::ALIAS]]);
        $remote = [['version' => '1.2.3', 'time' => '2026-01-01T00:00:00+00:00'] + $source('tttt'), ['version' => 'dev-main', 'time' => '2026-09-01T00:00:00+00:00'] + $source($commit) + self::ALIAS];
        $checker = self::checker([$package => 'dev-main'], $lock, [$package => $remote]);
        $signals = [new Signal(Signal::LEVEL_WARN, 'pinned', SignalDataStub::of(Signal::S6, ['reason' => 'branch_snapshot', 'version' => 'dev-main']))];
        $finding = (new FindingBuilder())->withPackage($package)->withVersion('dev-main')->withSignals($signals)->withFlags(FlagSet::fromSignals($signals, null, []))->build();
        [$context, $row] = self::context($finding, $package, 'dev-main', $lock, $remote, $checker, []);

        $move = MoveBuilder::build(MoveDraft::of(Move::TAG, FlagSet::PINNED)->withLatest(new Latest('1.2.3', '2026-01-01T00:00:00+00:00', false, 'older', '2026-09-01T00:00:00+00:00'), $row), [], null, $context);

        $check = $move->check();
        self::assertNotNull($check);
        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => MoveCheck::INSTALLS_NO_RELEASE, 'installs' => 'dev-main'], $check->jsonSerialize());
        self::assertSame([['composer', 'require', $package.':^1.2.3'], ['composer', 'why-not', $package, '1.2.3', '--locked']], $move->commands());
        self::assertSame("require a tag — the newest is 1.2.3 (2026-01-01), older than your snapshot (2026-09-01); review, or replace; Composer's solver installs dev-main, a dev version, not 1.2.3, so this command does not reach a release", $move->text());
    }

    /**
     * acme/lib 1.0.0 is vulnerable, and its dev-main alias 1.x-dev lies in the caret, so each form of
     * the update installs dev-main, and the text names no "(gets …)" release.
     */
    public function testAnUpdateThatEveryFormSolvesToTheBranchAliasIsNotCheckedAndNamesTheDevVersion(): void
    {
        $package = 'acme/lib';
        $lock = LockFile::fromArray(['packages' => [['name' => $package, 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $remote = [
            ['version' => '1.0.0', 'time' => '2026-01-01T00:00:00+00:00'],
            ['version' => '1.0.9', 'time' => '2026-02-01T00:00:00+00:00'],
            ['version' => 'dev-main', 'time' => '2026-09-01T00:00:00+00:00', 'source' => ['type' => 'git', 'url' => 'https://example.org/lib.git', 'reference' => 'bbbb']] + self::ALIAS,
        ];
        $checker = self::checker([$package => '^1.0'], $lock, [$package => $remote]);
        $advisory = ['id' => 'GHSA-x', 'cve' => null, 'title' => null, 'link' => null, 'reported_at' => null, 'severity' => 'high', 'severity_published' => 'high', 'affected_versions' => '<1.0.5', 'counted' => true, 'points' => 16, 'fix' => ['kind' => 'update'], 'baseline' => null];
        $signals = [new Signal(Signal::LEVEL_HIGH, '1 advisory', SignalDataStub::of(Signal::S9, ['advisories' => [$advisory], 'releases_read' => true, 'complete' => true]))];
        $finding = (new FindingBuilder())->withPackage($package)->withVersion('1.0.0')->withSignals($signals)->withFlags(FlagSet::fromSignals($signals, null, [new CountedAdvisory('GHSA-x', 'high', 'update')]))->build();
        [$context, $row] = self::context($finding, $package, '1.0.0', $lock, $remote, $checker, ['GHSA-x' => '<1.0.5']);
        [$lower] = $context->lowestAdmitted($row, '^1.0.5');

        $move = MoveBuilder::build(MoveDraft::of(Move::UPDATE, FlagSet::VULNERABLE)->to($row, $lower, null), [], Fix::UPDATE, $context);

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => MoveCheck::INSTALLS_NO_RELEASE, 'installs' => 'dev-main'], $checker->check(MoveQuery::update($package, '^1.0.5', '1.0.5', null, '1.0.9'))->check()->jsonSerialize());
        $check = $move->check();
        self::assertNotNull($check);
        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => MoveCheck::INSTALLS_NO_RELEASE, 'installs' => 'dev-main'], $check->jsonSerialize());
        self::assertSame([['composer', 'update', $package.':^1.0.9'], ['composer', 'why-not', $package, '1.0.9', '--locked']], $move->commands());
        self::assertSame("composer update 'acme/lib:^1.0.9' — clears the advisory; Composer's solver installs dev-main, a dev version, not 1.0.9, so this command does not reach a release", $move->text());
    }

    /** @return iterable<string, array{string, string, list<list<string>>}> */
    public static function requireKinds(): iterable
    {
        yield 'require' => [Move::REQUIRE, '>=7.4', [['composer', 'require', 'acme/lib:^2.0'], ['composer', 'why-not', 'acme/lib', '2.0.0', '--locked']]];
        yield 'raise-php' => [Move::RAISE_PHP, '>=8.1', [['composer', 'require', 'php:>=8.1', '--no-update'], ['composer', 'require', 'acme/lib:^2.0'], ['composer', 'why-not', 'acme/lib', '2.0.0', '--locked']]];
    }

    /**
     * A move to 2.x, whose dev-main alias 2.x-dev lies in `^2.0`: each form of the require installs
     * dev-main, with or without the raise of require.php first.
     *
     * @param list<list<string>> $commands
     *
     * @dataProvider requireKinds
     */
    #[DataProvider('requireKinds')]
    public function testARequireThatEveryFormSolvesToTheBranchAliasNamesTheDevVersion(string $kind, string $php, array $commands): void
    {
        $package = 'acme/lib';
        $alias = ['extra' => ['branch-alias' => ['dev-main' => '2.x-dev']]];
        $lock = LockFile::fromArray(['packages' => [['name' => $package, 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $remote = [
            ['version' => '1.0.0', 'time' => '2026-01-01T00:00:00+00:00'],
            ['version' => '2.0.0', 'time' => '2026-02-01T00:00:00+00:00', 'require' => ['php' => $php]],
            ['version' => 'dev-main', 'time' => '2026-09-01T00:00:00+00:00', 'require' => ['php' => $php], 'source' => ['type' => 'git', 'url' => 'https://example.org/lib.git', 'reference' => 'bbbb']] + $alias,
        ];
        $checker = self::checker(['php' => '>=7.4', $package => '^1.0'], $lock, [$package => $remote]);
        $finding = (new FindingBuilder())->withPackage($package)->withVersion('1.0.0')->build();
        [$context, $row] = self::context($finding, $package, '1.0.0', $lock, $remote, $checker, [], '2.x', '>=7.4');
        [$lower] = $context->lowestAdmitted($row, '^2.0');

        $move = MoveBuilder::build(MoveDraft::of($kind, FlagSet::LEFT_BEHIND)->to($row, $lower, '^2.0'), [], null, $context);
        $check = $move->check();

        self::assertNotNull($check);
        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => MoveCheck::INSTALLS_NO_RELEASE, 'installs' => 'dev-main'], $check->jsonSerialize());
        self::assertSame($commands, $move->commands());
        self::assertStringEndsWith("; Composer's solver installs dev-main, a dev version, not 2.0.0, so this command does not reach a release", $move->text());
    }

    /** Every form keeps the locked release 1.0.0: the text says that the command does not reach the newest, and names no dev version. */
    public function testAnUpdateThatEveryFormKeepsAtItsLockedReleaseSaysSo(): void
    {
        $package = 'acme/lib';
        $lock = LockFile::fromArray(['packages' => [['name' => $package, 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $remote = [['version' => '1.0.0', 'time' => '2026-01-01T00:00:00+00:00'], ['version' => '1.0.9', 'time' => '2026-02-01T00:00:00+00:00']];
        $checker = new RecordedMoveChecker(['*' => ['result' => 'not_checked', 'form' => null, 'reason' => MoveCheck::INSTALLS_NO_RELEASE, 'installs' => '1.0.0', 'holds' => []]], []);
        $advisory = ['id' => 'GHSA-x', 'cve' => null, 'title' => null, 'link' => null, 'reported_at' => null, 'severity' => 'high', 'severity_published' => 'high', 'affected_versions' => '<1.0.5', 'counted' => true, 'points' => 16, 'fix' => ['kind' => 'update'], 'baseline' => null];
        $signals = [new Signal(Signal::LEVEL_HIGH, '1 advisory', SignalDataStub::of(Signal::S9, ['advisories' => [$advisory], 'releases_read' => true, 'complete' => true]))];
        $finding = (new FindingBuilder())->withPackage($package)->withVersion('1.0.0')->withSignals($signals)->withFlags(FlagSet::fromSignals($signals, null, [new CountedAdvisory('GHSA-x', 'high', 'update')]))->build();
        [$context, $row] = self::context($finding, $package, '1.0.0', $lock, $remote, $checker, ['GHSA-x' => '<1.0.5']);
        [$lower] = $context->lowestAdmitted($row, '^1.0.5');

        $move = MoveBuilder::build(MoveDraft::of(Move::UPDATE, FlagSet::VULNERABLE)->to($row, $lower, null), [], Fix::UPDATE, $context);

        self::assertSame("composer update 'acme/lib:^1.0.9' — clears the advisory; Composer's solver keeps 1.0.0, not 1.0.9, so this command does not reach it", $move->text());
    }

    /**
     * A recorded check keeps its result: the rule reads only the installs of a live solve, and a
     * recorded answer that names no release is a resolve (SPEC 5.6, `installs`).
     */
    public function testARecordedResolveThatNamesNoReleaseStaysResolved(): void
    {
        $package = 'acme/other';
        $lock = LockFile::fromArray(['packages' => [['name' => $package, 'version' => 'dev-main', 'notification-url' => 'https://packagist.org/downloads/'] + self::ALIAS]]);
        $remote = [['version' => '1.2.3', 'time' => '2026-01-01T00:00:00+00:00'], ['version' => 'dev-main', 'time' => '2026-09-01T00:00:00+00:00'] + self::ALIAS];
        $signals = [new Signal(Signal::LEVEL_WARN, 'pinned', SignalDataStub::of(Signal::S6, ['reason' => 'branch_snapshot', 'version' => 'dev-main']))];
        $finding = (new FindingBuilder())->withPackage($package)->withVersion('dev-main')->withSignals($signals)->withFlags(FlagSet::fromSignals($signals, null, []))->build();
        [$context, $row] = self::context($finding, $package, 'dev-main', $lock, $remote, new RecordedMoveChecker(['*' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]], []), []);

        $move = MoveBuilder::build(MoveDraft::of(Move::TAG, FlagSet::PINNED)->withLatest(new Latest('1.2.3', '2026-01-01T00:00:00+00:00', false, 'older', '2026-09-01T00:00:00+00:00'), $row), [], null, $context);
        $check = $move->check();

        self::assertNotNull($check);
        self::assertSame(['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'installs' => null], $check->jsonSerialize());
        self::assertSame([['composer', 'require', $package.':^1.2.3']], $move->commands());
        self::assertStringEndsWith("; Composer's solver checked it against the lock", $move->text());
    }

    /**
     * @param array<string, string>                     $requires
     * @param array<string, list<array<string, mixed>>> $remote
     */
    private static function checker(array $requires, LockFile $lock, array $remote): SolverChecker
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => $requires], RootPackage::class);
        self::assertInstanceOf(RootPackage::class, $root);
        $root->setMinimumStability('dev');
        $root->setPreferStable(false);
        $solver = new ComposerSolver($root, $lock->lockedRepository(), new PlatformRepository([], ['php' => self::PHP]), new ArrayVersionSource($remote));

        return new SolverChecker($solver, [], self::PHP, false, Deadline::never());
    }

    /**
     * @param list<array<string, mixed>> $remote
     * @param array<string, string>      $ranges advisory id => affected range
     *
     * @return array{0: MoveContext, 1: BranchRow} the context and the row of $branch
     */
    private static function context(Finding $finding, string $package, string $version, LockFile $lock, array $remote, MoveChecker $checker, array $ranges, string $branch = '1.x', ?string $projectPhp = null): array
    {
        $packages = [];
        foreach ($remote as $entry) {
            $release = (new ArrayLoader())->load(['name' => $package] + $entry);
            self::assertInstanceOf(BasePackage::class, $release);
            $packages[] = $release;
        }
        $locked = $lock->find($package);
        self::assertNotNull($locked);
        $parser = new VersionParser();
        $parsed = array_map(static fn (string $range) => $parser->parseConstraints($range), $ranges);
        $advisories = [];
        foreach ($parsed as $id => $range) {
            $advisories[] = new Advisory((string) $id, null, null, null, 'high', null, $range);
        }
        $facts = new PackageFacts($locked, PackageMetadata::fromPackages($package, $packages, new \DateTimeImmutable(self::NOW), $locked->normalizedVersion()), null, $advisories);
        $floor = new PhpFloor('8.4', $projectPhp);
        $finder = new FixFinder($floor, LinkIndex::of($lock, ProjectConfig::fromArray(['require' => [$package => '*']])));
        $fixes = $advisories === [] ? null : $finder->find($facts, $advisories);
        $rows = array_values(array_filter(BranchRow::all($facts, $floor, Clock::fixed(self::NOW), null, $fixes), static fn (BranchRow $row): bool => $row->branch() === $branch));
        $row = $rows[0] ?? null;
        self::assertNotNull($row);
        $subject = (new MoveSubject($package, $version, true, [], '8.4', $projectPhp === null ? null : ltrim($projectPhp, '>=').'.0'))->withNamedIn([ProjectConfig::REQUIRE]);
        $context = (new MoveContext($finding, $subject, $facts->metadata(), $rows, $fixes, $finder, $floor, new \DateTimeImmutable(self::NOW), 3, null, $parsed, [], true))
            ->withChecks(MoveChecks::of($checker));

        return [$context, $row];
    }
}
