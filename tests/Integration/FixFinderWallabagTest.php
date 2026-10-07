<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Repository\AdvisoryProviderInterface;
use Composer\Semver\Comparator;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Security\Fix;
use Lockrot\Security\FixFinder;
use Lockrot\Security\Holder;
use Lockrot\Security\LinkIndex;
use Lockrot\Security\PackageFixes;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FakeHttpClient;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\Golden;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\TestCase;

/**
 * The release scan over the recorded wallabag fixture, whose advisories are recorded too: the
 * invariants of SPEC-0.14 5.3 on every vulnerable package, and each package's classes in a golden
 * file. Re-record the answers with `bin/record-fixtures tests/fixtures/apps/wallabag_wallabag`.
 */
final class FixFinderWallabagTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../fixtures/apps/wallabag_wallabag';
    private const NOW = '2026-10-01T00:00:00+00:00';
    private const TARGET_PHP = '8.4';

    public function testEveryVulnerablePackageKeepsTheReleaseScanInvariants(): void
    {
        $scans = self::scans();
        self::assertNotSame([], $scans);
        $golden = [];
        foreach ($scans as $package => [$installed, $fixes]) {
            foreach ($fixes->fixes() as $id => $fix) {
                self::assertFixIsWhole($fix, $package.' '.$id);
                $version = $fix->candidate() === null ? null : $fix->candidate()->release()->normalized();
                if ($version !== null) {
                    self::assertTrue($installed === null || Comparator::greaterThan($version, $installed), $package.' '.$id.': a fix lies above the installed version');
                }
            }
            foreach ($fixes->branches() as $row) {
                self::assertLessThanOrEqual($row->of(), $row->fixed() + $row->unknown(), $package.' '.$row->branch());
                self::assertSame($row->fixed() === 0, $row->fixKind() === null, $package.' '.$row->branch());
                self::assertSame($row->fixed() === 0, $row->candidate() === null, $package.' '.$row->branch());
                $candidate = $row->candidate();
                if ($candidate !== null && $candidate->kind() === Fix::UPGRADE) {
                    self::assertNotSame([], $candidate->heldBy(), $package.' '.$row->branch().': upgrade implies a holder');
                }
            }
            $golden[$package] = self::summary($fixes);
        }

        Golden::assertMatches('fixfinder-wallabag.json', $golden, 'testEveryVulnerablePackageKeepsTheReleaseScanInvariants');
    }

    private static function assertFixIsWhole(Fix $fix, string $what): void
    {
        if (\in_array($fix->kind(), [Fix::UNKNOWN, Fix::NONE], true)) {
            self::assertNotNull($fix->reason(), $what);
            self::assertSame([null, null, null, null], [$fix->toBranch(), $fix->version(), $fix->newest(), $fix->onInstalledBranch()], $what);

            return;
        }
        self::assertNull($fix->reason(), $what);
        self::assertNotNull($fix->toBranch(), $what);
        self::assertNotNull($fix->onInstalledBranch(), $what);
        if ($fix->kind() === Fix::UPGRADE) {
            self::assertNotSame([], $fix->heldBy(), $what.': upgrade implies a holder');
        }
    }

    /** @return array<string, mixed> */
    private static function summary(PackageFixes $fixes): array
    {
        $byAdvisory = [];
        foreach ($fixes->fixes() as $id => $fix) {
            $byAdvisory[$id] = [$fix->kind(), $fix->version(), array_map(static fn (Holder $holder): string => ($holder->package() ?? 'root').' '.$holder->link(), $fix->heldBy())];
        }
        ksort($byAdvisory);
        $rows = [];
        foreach ($fixes->branches() as $row) {
            $rows[$row->branch()] = [$row->fixed(), $row->of(), $row->lowest(), $row->fixKind(), $row->candidate() === null ? null : $row->candidate()->kind()];
        }
        $gets = $fixes->gets();
        $move = $fixes->move();

        return [
            'fixes' => $byAdvisory,
            'branches' => $rows,
            'gets' => $gets === null ? null : [$gets->version(), $gets->clearsAll()],
            'move' => $move === null ? null : [$move->kind(), $move->release()->pretty()],
            'partial' => $fixes->partial() === null ? null : $fixes->partial()->version(),
        ];
    }

    /** @return array<string, array{0: ?string, 1: PackageFixes}> package => its normalized installed version, and its scan */
    private static function scans(): array
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $server = FixtureRepositoryServer::fromLockFiles([self::FIXTURE.'/composer.lock']);
        $server->withRecordedAdvisoryApi(self::FIXTURE);
        $server->start();
        try {
            $clock = Clock::fixed(self::NOW);
            $project = ProjectConfig::fromFile(self::FIXTURE.'/composer.json');
            $lock = LockFile::fromFile(self::FIXTURE.'/composer.lock');
            $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
            $analyzer = new Analyzer(
                new RepositoryMetadataLoader($server->repositories(), $clock),
                new ActivityClient(new FakeHttpClient(), $auth),
                new ActivityFetchPlanner($auth),
                new RepoLocator(),
                BuiltinAllowlist::load(),
                SignalSet::default($clock, new Thresholds(), self::TARGET_PHP, PhpReleaseDates::load(), $project->requirePhp()),
                new VerdictEngine(),
                $clock,
                false,
                new RepositoryAdvisoryLoader($server->repositories())
            );
            $analysis = $analyzer->analyzeWithFacts($lock->packages(false), $lock, $project, false);
        } finally {
            $server->stop();
        }
        $finder = new FixFinder(new PhpFloor(self::TARGET_PHP, $project->requirePhp()), LinkIndex::of($lock, $project));
        $scans = [];
        foreach ($analysis->report()->findings() as $finding) {
            $facts = $analysis->facts($finding->package());
            self::assertNotNull($facts);
            if ($facts->advisories() !== []) {
                $scans[$finding->package()] = [$facts->package()->normalizedVersion(), $finder->find($facts)];
            }
        }
        ksort($scans);

        return $scans;
    }
}
