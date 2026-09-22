<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Allowlist\Allowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Report;
use Lockrot\Clock;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\MonorepoParents;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FakeHttpClient;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\TestCase;

/**
 * The analyzer's metadata stage dates split packages by their monorepo parent: a parent in the
 * lock is used as loaded, a listed one outside it is fetched once, and the findings then read the
 * parent's dates. What is asked of the loader is what these tests watch.
 */
final class AnalyzerSplitPackagesTest extends TestCase
{
    /** @var list<list<string>> every load() call's names, in order */
    private array $asked = [];

    /**
     * @param list<MetadataBatch> $answers one batch per load() call, by call index; a call past the end answers "not found"
     */
    private function loader(array $answers): MetadataLoaderInterface
    {
        $this->asked = [];
        $asked = &$this->asked;

        return new class ($answers, $asked) implements MetadataLoaderInterface {
            /** @var list<MetadataBatch> */
            private array $answers;
            /** @var list<list<string>> */
            private array $asked;

            /**
             * @param list<MetadataBatch>  $answers
             * @param list<list<string>>   $asked
             */
            public function __construct(array $answers, array &$asked)
            {
                $this->answers = $answers;
                $this->asked = &$asked;
            }

            public function load(array $names): MetadataBatch
            {
                $this->asked[] = $names;
                $call = \count($this->asked) - 1;

                return $this->answers[$call] ?? new MetadataBatch([], $names, []);
            }
        };
    }

    private function analyzer(MetadataLoaderInterface $loader, MonorepoParents $parents, Thresholds $thresholds): Analyzer
    {
        $clock = Clock::fixed(F::NOW);
        $auth = ForgeAuth::withTokens(new Tokens(null, null));

        return new Analyzer(
            $loader,
            new ActivityClient(new FakeHttpClient([]), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            new Allowlist([]),
            SignalSet::default($clock, $thresholds, '8.4', PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            false,
            null,
            $parents
        );
    }

    private function lock(string ...$names): LockFile
    {
        $packages = [];
        foreach ($names as $name) {
            [$package, $version] = explode('@', $name);
            $packages[] = ['name' => $package, 'version' => $version, 'notification-url' => 'https://packagist.org/downloads/'];
        }

        return LockFile::fromArray(['packages' => $packages]);
    }

    /** @return array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable}, php: ?string} */
    private static function branch(string $version, ?string $at): array
    {
        $date = $at === null ? null : new \DateTimeImmutable($at);

        return ['version' => 'v'.$version, 'at' => $date, 'highest' => ['normalized' => $version.'.0', 'pretty' => 'v'.$version, 'at' => $date], 'php' => null];
    }

    /** contracts as Packagist has it: 8.x and 13.x on shared commits (undated), 7.x on its own. */
    private static function contracts(): PackageMetadata
    {
        return new PackageMetadata('illuminate/contracts', false, null, true, null, null, 3, 'https://github.com/illuminate/contracts.git', 'library', new \DateTimeImmutable(F::NOW), [
            '13' => self::branch('13.24.0', null),
            '8' => self::branch('8.83.27', null),
            '7' => self::branch('7.30.6', '2021-11-17T15:00:14+00:00'),
        ]);
    }

    private static function framework(): PackageMetadata
    {
        return new PackageMetadata('laravel/framework', false, null, true, new \DateTimeImmutable('2026-09-01T00:00:00+00:00'), 'v13.24.0', 3, 'https://github.com/laravel/framework.git', 'library', new \DateTimeImmutable(F::NOW), [
            '13' => self::branch('13.24.0', '2026-09-01T00:00:00+00:00'),
            '8' => self::branch('8.83.29', '2024-11-20T15:55:41+00:00'),
        ], ['illuminate/contracts', 'illuminate/support']);
    }

    private static function finding(Report $report, string $package): Finding
    {
        foreach ($report->findings() as $finding) {
            if ($finding->package() === $package) {
                return $finding;
            }
        }
        self::fail($package.' is not in the report');
    }

    /**
     * The notes about repository metadata; the activity stage adds its own (no GitHub token here).
     *
     * @return list<string>
     */
    private static function metadataNotes(Report $report): array
    {
        return array_values(array_filter($report->notes(), static fn (string $note): bool => strpos($note, 'Repository metadata') === 0));
    }

    private static function signal(Finding $finding, string $id): ?Signal
    {
        foreach ($finding->signals() as $signal) {
            if ($signal->id() === $id) {
                return $signal;
            }
        }

        return null;
    }

    public function testAListedParentOutsideTheLockIsFetchedOnceAndDatesTheChild(): void
    {
        $loader = $this->loader([
            new MetadataBatch(['illuminate/contracts' => self::contracts()], [], []),
            new MetadataBatch(['laravel/framework' => self::framework()], [], []),
        ]);
        // 8.x last released 2024-11-20, 1.8 years before F::NOW: under a one-year threshold S8 is high.
        $report = $this->analyzer($loader, new MonorepoParents(['laravel/framework' => ['illuminate/contracts']]), new Thresholds(1, 1))
            ->analyze($this->lock('illuminate/contracts@v8.83.27'), ProjectConfig::fromArray(['require' => ['illuminate/contracts' => '^8.0']]), false);

        self::assertSame([['illuminate/contracts'], ['laravel/framework']], $this->asked, 'the lock first, then the parent');
        $finding = self::finding($report, 'illuminate/contracts');
        self::assertSame(Verdict::LEFT_BEHIND, $finding->verdict());
        $s8 = self::signal($finding, Signal::S8);
        self::assertNotNull($s8);
        self::assertSame('branch 8.x last released 2024-11-20 (1.8 years ago, dated by laravel/framework); 13.x released v13.24.0 (2026-09-01)', $s8->summary());
        self::assertSame('laravel/framework', $s8->data()['dated_by']);
        self::assertSame(1, $report->packagesChecked(), 'the parent is not a package of the lock');
        self::assertSame([], self::metadataNotes($report));
        self::assertFalse($report->hadNetworkFailures());
    }

    public function testAParentInTheLockIsUsedAsLoadedAndNothingMoreIsFetched(): void
    {
        $loader = $this->loader([
            new MetadataBatch(['illuminate/contracts' => self::contracts(), 'laravel/framework' => self::framework()], [], []),
        ]);
        $report = $this->analyzer($loader, new MonorepoParents(['laravel/framework' => ['illuminate/contracts']]), new Thresholds(1, 1))
            ->analyze($this->lock('illuminate/contracts@v8.83.27', 'laravel/framework@v8.83.29'), ProjectConfig::empty(), false);

        self::assertSame([['illuminate/contracts', 'laravel/framework']], $this->asked);
        $s8 = self::signal(self::finding($report, 'illuminate/contracts'), Signal::S8);
        self::assertNotNull($s8);
        self::assertSame('laravel/framework', $s8->data()['dated_by']);
        self::assertSame(2, $report->packagesChecked());
    }

    public function testALockWithNothingToDateNeverAsksForAParent(): void
    {
        $own = new PackageMetadata('vendor/pkg', false, null, true, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), '1.0.0', 1, null, 'library', new \DateTimeImmutable(F::NOW), ['1' => self::branch('1.0.0', '2026-01-01T00:00:00+00:00')]);
        $loader = $this->loader([new MetadataBatch(['vendor/pkg' => $own], [], [])]);

        $this->analyzer($loader, new MonorepoParents(['laravel/framework' => ['illuminate/contracts']]), new Thresholds())
            ->analyze($this->lock('vendor/pkg@1.0.0'), ProjectConfig::empty(), false);

        self::assertSame([['vendor/pkg']], $this->asked);
    }

    public function testAParentTheRepositoriesDoNotListIsNoFailure(): void
    {
        $loader = $this->loader([
            new MetadataBatch(['illuminate/contracts' => self::contracts()], [], []),
            new MetadataBatch([], ['laravel/framework'], []),
        ]);
        $report = $this->analyzer($loader, new MonorepoParents(['laravel/framework' => ['illuminate/contracts']]), new Thresholds(1, 1))
            ->analyze($this->lock('illuminate/contracts@v8.83.27'), ProjectConfig::empty(), false);

        self::assertSame(Verdict::OK, self::finding($report, 'illuminate/contracts')->verdict(), 'undated as before: not measured');
        self::assertSame([], self::metadataNotes($report));
        self::assertFalse($report->hadNetworkFailures());
    }

    public function testAParentTheRepositoriesCouldNotDeliverIsANoteAndANetworkFailure(): void
    {
        $loader = $this->loader([
            new MetadataBatch(['illuminate/contracts' => self::contracts()], [], []),
            new MetadataBatch([], [], ['laravel/framework' => 'HTTP 503 from repo.packagist.org']),
        ]);
        $report = $this->analyzer($loader, new MonorepoParents(['laravel/framework' => ['illuminate/contracts']]), new Thresholds(1, 1))
            ->analyze($this->lock('illuminate/contracts@v8.83.27'), ProjectConfig::empty(), false);

        self::assertSame(['Repository metadata unavailable for laravel/framework, which dates the packages split out of it: HTTP 503 from repo.packagist.org'], self::metadataNotes($report));
        self::assertTrue($report->hadNetworkFailures());
    }

    public function testAnExhaustedInstallTimeBudgetSkipsTheParentSilently(): void
    {
        $loader = $this->loader([
            new MetadataBatch(['illuminate/contracts' => self::contracts()], [], []),
            new MetadataBatch(['laravel/framework' => self::framework()], [], []),
        ]);
        $analyzer = $this->analyzer($loader, new MonorepoParents(['laravel/framework' => ['illuminate/contracts']]), new Thresholds(1, 1))
            ->withDeadline(Deadline::inSeconds(0.0, static fn (): float => 0.0));
        $lock = $this->lock('illuminate/contracts@v8.83.27');

        $report = $analyzer->analyzePackages($lock->packages(false), $lock, ProjectConfig::empty(), false);

        self::assertSame([['illuminate/contracts']], $this->asked, 'no second request once the budget is gone');
        self::assertNull(self::signal(self::finding($report, 'illuminate/contracts'), Signal::S8));
        self::assertNotContains('Repository metadata unavailable for laravel/framework, which dates the packages split out of it: '.MetadataLoaderInterface::BUDGET_REASON, $report->notes());
    }

    public function testABudgetThatRunsOutDuringTheParentRequestIsDroppedSilently(): void
    {
        $loader = $this->loader([
            new MetadataBatch(['illuminate/contracts' => self::contracts()], [], []),
            new MetadataBatch([], [], ['laravel/framework' => MetadataLoaderInterface::BUDGET_REASON]),
        ]);
        $report = $this->analyzer($loader, new MonorepoParents(['laravel/framework' => ['illuminate/contracts']]), new Thresholds(1, 1))
            ->analyze($this->lock('illuminate/contracts@v8.83.27'), ProjectConfig::empty(), false);

        self::assertSame([], self::metadataNotes($report));
        self::assertFalse($report->hadNetworkFailures());
    }
}
