<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analysis;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Libyears;
use Lockrot\Clock;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\InstalledRelease;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Explain\Explanation;
use Lockrot\Lock\LockedPackage;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\MemoisingMetadataLoader;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * A finding's `libyears_unmeasured` against everything else that says why a package was not
 * measured, on every fixture lock: the report's `libyears.unmeasured` block (the counts are the
 * findings' codes counted, which is wiring — both read one stored value), the facts the analysis was
 * decided on, read here without going through {@see Libyears::measure()}, the rule 0.12 used to file
 * each finding off its note and version, which proves the block's counts did not move, and the
 * `--explain` document's copy of the finding.
 */
final class LibyearsUnmeasuredAgreementTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/';
    private const NOW = '2026-09-14T00:00:00+00:00';

    /** @var array<string, int> every code the findings gave, so the check is seen to bite */
    private array $seen = [];

    /**
     * Every fixture lock served at once, as BranchFloorAgreementTest serves them, in a process of
     * its own for the same memory reason.
     *
     * @runInSeparateProcess
     */
    #[RunInSeparateProcess]
    public function testEveryFindingSaysWhyItWasNotMeasuredAndTheBlockCountsExactlyThat(): void
    {
        $dirs = ['mini'];
        foreach (['apps', 'skeletons'] as $group) {
            foreach ((array) glob(self::FIXTURES.$group.'/*/composer.lock') as $lock) {
                $dirs[] = $group.'/'.basename(\dirname((string) $lock));
            }
        }
        sort($dirs);
        self::assertGreaterThanOrEqual(20, \count($dirs), 'the fixture locks');
        $server = FixtureRepositoryServer::fromLockFiles(array_map(static fn (string $dir): string => self::FIXTURES.$dir.'/composer.lock', $dirs));
        $server->start();
        try {
            $loader = new MemoisingMetadataLoader(new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::NOW)));
            foreach ($dirs as $dir) {
                $this->agree($this->analysis($loader, $dir), $dir);
            }
        } finally {
            $server->stop();
        }

        foreach ([Libyears::NOT_FROM_COMPOSER_REPOSITORY, Libyears::BRANCH_SNAPSHOT, Libyears::NO_STABLE_RELEASE_DATE, 'measured'] as $code) {
            self::assertArrayHasKey($code, $this->seen, 'the fixtures give '.$code.', or the agreement proves little: '.json_encode($this->seen));
        }
    }

    private function agree(Analysis $analysis, string $dir): void
    {
        $report = $analysis->report();
        $document = $report->toArray();
        $block = JsonPath::arrayAt($document, ['libyears', 'unmeasured']);
        self::assertSame(Libyears::REASONS, array_keys($block), $dir.': the block\'s keys');
        $counted = array_fill_keys(array_keys($block), 0);
        $measured = 0;
        $findings = JsonPath::arrayAt($document, ['findings']);
        foreach ($findings as $at => $row) {
            self::assertIsArray($row);
            $package = JsonPath::stringAt($document, ['findings', $at, 'package']);
            $what = $dir.' '.$package;
            self::assertArrayHasKey('libyears_unmeasured', $row, $what.': every finding carries the key');
            $code = $row['libyears_unmeasured'];
            self::assertSame($row['libyears'] === null, $code !== null, $what.': null exactly where libyears is a number');
            if ($code === null) {
                ++$measured;
                $this->seen['measured'] = ($this->seen['measured'] ?? 0) + 1;
            } else {
                self::assertIsString($code, $what);
                self::assertArrayHasKey($code, $block, $what.': a key of the block');
                ++$counted[$code];
                $this->seen[$code] = ($this->seen[$code] ?? 0) + 1;
                self::assertSame(self::rule012($row), $code, $what.': the reason 0.12 filed it under');
            }

            $finding = $analysis->finding($package);
            $facts = $analysis->facts($package);
            self::assertNotNull($finding, $what);
            self::assertNotNull($facts, $what);
            self::assertSame(self::fromFacts($facts->package(), $facts->metadata() !== null, $row['libyears'] === null), $code, $what.': the facts the analysis was decided on');
            if ($code === Libyears::NO_STABLE_RELEASE_DATE) {
                $metadata = $facts->metadata();
                self::assertNotNull($metadata, $what);
                self::assertTrue(InstalledRelease::of($facts->package(), $metadata)->at() === null || $metadata->lastStableReleaseAt() === null, $what.': one end has no trusted date');
            }
            $explained = (new Explanation($finding, $facts, new Thresholds(), '8.4', $report))->toArray();
            self::assertSame($code, JsonPath::arrayAt($explained, ['finding'])['libyears_unmeasured'], $what.': the explanation\'s finding');
        }

        self::assertSame($block, $counted, $dir.': the block is the findings\' codes counted');
        self::assertSame(JsonPath::arrayAt($document, ['libyears'])['measured'], $measured, $dir.': measured');
        self::assertSame(\count($findings), $measured + array_sum($counted), $dir.': every finding is measured or counted once');
    }

    /**
     * The reason from the lock entry and whether metadata came, with no call into the rule: exact
     * for the first three, and the fourth is what is left of an unmeasured package.
     */
    private static function fromFacts(LockedPackage $package, bool $hasMetadata, bool $unmeasured): ?string
    {
        if (!$package->isFromComposerRepository()) {
            return Libyears::NOT_FROM_COMPOSER_REPOSITORY;
        }
        if (!$hasMetadata) {
            return Libyears::METADATA_UNAVAILABLE;
        }
        if ($package->isBranchSnapshot()) {
            return Libyears::BRANCH_SNAPSHOT;
        }

        return $unmeasured ? Libyears::NO_STABLE_RELEASE_DATE : null;
    }

    /**
     * How 0.12 filed an unmeasured finding, off what the finding says: the note, then the version.
     *
     * @param array<mixed, mixed> $row
     */
    private static function rule012(array $row): string
    {
        if ($row['note'] === Finding::NOTE_NOT_IN_REPOSITORY) {
            return Libyears::NOT_FROM_COMPOSER_REPOSITORY;
        }
        if ($row['note'] !== null) {
            return Libyears::METADATA_UNAVAILABLE;
        }
        self::assertIsString($row['version']);

        return LockedPackage::isSnapshotVersion($row['version']) ? Libyears::BRANCH_SNAPSHOT : Libyears::NO_STABLE_RELEASE_DATE;
    }

    private function analysis(MetadataLoaderInterface $loader, string $dir): Analysis
    {
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $analyzer = new Analyzer(
            $loader,
            new ActivityClient(new RecordedHttpClient(self::FIXTURES.'http/github'), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            true
        );
        $lock = LockFile::fromFile(self::FIXTURES.$dir.'/composer.lock');

        return $analyzer->analyzeWithFacts($lock->packages(false), $lock, ProjectConfig::fromFile(self::FIXTURES.$dir.'/composer.json'), false);
    }
}
