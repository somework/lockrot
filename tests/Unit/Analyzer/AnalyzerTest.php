<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Allowlist\Allowlist;
use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Report;
use Lockrot\Clock;
use Lockrot\Data\GitHub\GitHubClient;
use Lockrot\Data\GitHub\GitHubFetchPlanner;
use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\Priority;
use Lockrot\Verdict\Verdict;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\TestCase;

final class AnalyzerTest extends TestCase
{
    /**
     * @param array<string, array{int, string}> $map       url => [status, body]
     * @param \ArrayObject<int, string>|null    $requested receives every URL asked for, in order
     */
    private function http(array $map, ?\ArrayObject $requested = null): HttpClientInterface
    {
        return new class ($map, $requested) implements HttpClientInterface {
            /** @var array<string, array{int, string}> */
            private array $map;
            /** @var \ArrayObject<int, string>|null */
            private ?\ArrayObject $requested;

            /**
             * @param array<string, array{int, string}> $map
             * @param \ArrayObject<int, string>|null    $requested
             */
            public function __construct(array $map, ?\ArrayObject $requested)
            {
                $this->map = $map;
                $this->requested = $requested;
            }
            public function fetchAll(array $urls, array $headers = []): array
            {
                $out = [];
                foreach ($urls as $url) {
                    if ($this->requested !== null) {
                        $this->requested->append($url);
                    }
                    [$status, $body] = $this->map[$url] ?? [404, ''];
                    $out[$url] = new HttpResult($url, $status, $body, new \DateTimeImmutable('2026-09-14T06:00:00+00:00'));
                }
                return $out;
            }
        };
    }

    /**
     * A fake metadata loader that ignores the requested names and always returns the same
     * prepared batch — the analyzer only ever calls load() once per analyze(), so there is nothing
     * to distinguish between calls in these tests.
     *
     * @param array<string, PackageMetadata> $metadata
     * @param list<string>                   $notFound
     * @param array<string, string>          $failed
     */
    private function loader(array $metadata = [], array $notFound = [], array $failed = []): MetadataLoaderInterface
    {
        $batch = new MetadataBatch($metadata, $notFound, $failed);

        return new class ($batch) implements MetadataLoaderInterface {
            private MetadataBatch $batch;

            public function __construct(MetadataBatch $batch)
            {
                $this->batch = $batch;
            }

            public function load(array $names): MetadataBatch
            {
                return $this->batch;
            }
        };
    }

    private function analyzer(MetadataLoaderInterface $metadata, HttpClientInterface $http, bool $token, Allowlist $allowlist, bool $offline = false, int $noTokenBudget = GitHubFetchPlanner::DEFAULT_NO_TOKEN_BUDGET): Analyzer
    {
        $clock = Clock::fixed(F::NOW);
        return new Analyzer(
            $metadata,
            new GitHubClient($http, $token ? 't' : null),
            new GitHubFetchPlanner($token, $noTokenBudget),
            $allowlist,
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            $offline
        );
    }

    public function testOfflineNoteAppearsOnlyWhenOfflineFlagIsSet(): void
    {
        $lock = LockFile::fromArray(['packages' => []]);
        $offlineReport = $this->analyzer($this->loader(), $this->http([]), true, new Allowlist([]), true)
            ->analyze($lock, ProjectConfig::empty(), false);
        $onlineReport = $this->analyzer($this->loader(), $this->http([]), true, new Allowlist([]), false)
            ->analyze($lock, ProjectConfig::empty(), false);

        self::assertContains("offline: repository metadata served from Composer's cache", $offlineReport->notes());
        self::assertNotContains("offline: repository metadata served from Composer's cache", $onlineReport->notes());
    }

    public function testMiniProjectVerdicts(): void
    {
        $dataDate = new \DateTimeImmutable('2026-09-14T06:00:00+00:00');
        $metadata = [
            'vendor/direct' => new PackageMetadata('vendor/direct', false, null, true, new \DateTimeImmutable('2024-01-10T10:00:00+00:00'), '1.2.3', 1, 'https://github.com/vendor/direct.git', 'library', $dataDate),
            'vendor/transitive' => new PackageMetadata('vendor/transitive', false, null, true, new \DateTimeImmutable('2015-11-16T16:30:51+00:00'), '2.0.0', 1, 'https://github.com/vendor/transitive.git', 'library', $dataDate),
            'vendor/snapshot' => new PackageMetadata('vendor/snapshot', false, null, false, null, null, 1, null, 'library', $dataDate),
        ];
        $http = $this->http([
            'https://api.github.com/repos/vendor/transitive' => [200, '{"archived":false,"pushed_at":"2015-11-16T16:31:37Z"}'],
        ]);
        $report = $this->analyzer($this->loader($metadata), $http, false, new Allowlist([]))->analyze(
            LockFile::fromFile(__DIR__.'/../../fixtures/mini/composer.lock'),
            ProjectConfig::fromFile(__DIR__.'/../../fixtures/mini/composer.json'),
            false
        );
        $byName = [];
        foreach ($report->findings() as $finding) {
            $byName[$finding->package()] = $finding;
        }
        // vendor/direct is released 2024-01-10 with an open-ended "php": ">=7.4" constraint, which
        // predates the PHP 8.4 GA (2024-11-21) used as targetPhp below, so S5 fires and the verdict
        // is OLD_PROMISE rather than OK. The mini fixture is shared with
        // LockFileTest/DependencyGraphTest/ProjectConfigTest, so the interaction is documented here
        // instead of edited into the fixture.
        self::assertSame(Verdict::OLD_PROMISE, $byName['vendor/direct']->verdict());
        self::assertSame(Verdict::SILENT, $byName['vendor/transitive']->verdict());
        self::assertSame(['vendor/direct', 'vendor/transitive'], $byName['vendor/transitive']->chain());
        self::assertNotNull($byName['vendor/transitive']->dataDate());
        self::assertSame('2026-09-14T06:00:00+00:00', $byName['vendor/transitive']->dataDate()->format(\DATE_ATOM));
        self::assertSame(Verdict::PINNED, $byName['vendor/snapshot']->verdict());
        self::assertSame(Verdict::UNKNOWN, $byName['private/thing']->verdict());
        self::assertSame('not from a Composer repository, not checked', $byName['private/thing']->evidence());
        self::assertSame(4, $report->packagesChecked());
        self::assertSame(1, $report->notFromComposerRepository());
        self::assertFalse($report->hadNetworkFailures());
        // Sort order is priority desc, then severity desc, then direct first, then name asc. Priority
        // outranks the verdict here: vendor/snapshot is PINNED(40) but nothing in the lock reaches it,
        // so its chain is empty, it counts as transitive and its `high` base drops to `medium` — below
        // vendor/direct, which is only OLD_PROMISE(30) but is a root require and stays `high`.
        self::assertSame(Priority::HIGH, $byName['vendor/transitive']->priority());
        self::assertSame(Priority::HIGH, $byName['vendor/direct']->priority());
        self::assertSame([], $byName['vendor/snapshot']->chain());
        self::assertSame(Priority::MEDIUM, $byName['vendor/snapshot']->priority());
        self::assertSame(Priority::NONE, $byName['private/thing']->priority());
        self::assertSame(['vendor/transitive', 'vendor/direct', 'vendor/snapshot', 'private/thing'], array_map(static fn ($f) => $f->package(), $report->findings()));
        self::assertNotEmpty(array_filter($report->notes(), static fn (string $n): bool => strpos($n, 'GitHub token not set') !== false));
        // Transitive exposure: vendor/direct is the one root and pulls in the silent package, so it
        // carries S7 next to its own S5 — with the verdict and priority it had without it.
        self::assertSame(['vendor/direct'], $byName['vendor/transitive']->directDependents());
        self::assertSame(['vendor/direct'], $byName['vendor/direct']->directDependents());
        self::assertSame([], $byName['vendor/snapshot']->directDependents());
        self::assertSame(['S5', 'S7'], array_map(static fn ($s) => $s->id(), $byName['vendor/direct']->signals()));
        self::assertStringEndsWith('; pulls in 1 flagged package: vendor/transitive (silent)', $byName['vendor/direct']->evidence());
        self::assertSame(['vendor/direct' => 1], $report->exposure());
    }

    public function testADevPackageCarriesTheDevFlagAndGetsALowerPriorityThanTheSameProdPackage(): void
    {
        $dataDate = new \DateTimeImmutable('2026-09-14T06:00:00+00:00');
        $silent = static fn (string $name): PackageMetadata => new PackageMetadata($name, false, null, true, new \DateTimeImmutable('2015-11-16T16:30:51+00:00'), '2.0.0', 1, 'https://github.com/'.$name.'.git', 'library', $dataDate);
        $metadata = ['vendor/prod' => $silent('vendor/prod'), 'vendor/devtool' => $silent('vendor/devtool')];
        $http = $this->http([
            'https://api.github.com/repos/vendor/prod' => [200, '{"archived":false,"pushed_at":"2015-11-16T16:31:37Z"}'],
            'https://api.github.com/repos/vendor/devtool' => [200, '{"archived":false,"pushed_at":"2015-11-16T16:31:37Z"}'],
        ]);
        $lock = LockFile::fromArray([
            'packages' => [['name' => 'vendor/prod', 'version' => '2.0.0', 'notification-url' => 'https://packagist.org/downloads/']],
            'packages-dev' => [['name' => 'vendor/devtool', 'version' => '2.0.0', 'notification-url' => 'https://packagist.org/downloads/']],
        ]);
        $project = ProjectConfig::fromArray(['require' => ['vendor/prod' => '^2.0'], 'require-dev' => ['vendor/devtool' => '^2.0']]);

        $report = $this->analyzer($this->loader($metadata), $http, false, new Allowlist([]))->analyze($lock, $project, true);
        $byName = [];
        foreach ($report->findings() as $finding) {
            $byName[$finding->package()] = $finding;
        }

        self::assertSame(Verdict::SILENT, $byName['vendor/prod']->verdict());
        self::assertSame(Verdict::SILENT, $byName['vendor/devtool']->verdict());
        self::assertFalse($byName['vendor/prod']->isDev());
        self::assertTrue($byName['vendor/devtool']->isDev());
        self::assertSame(Priority::CRITICAL, $byName['vendor/prod']->priority());
        self::assertSame(Priority::HIGH, $byName['vendor/devtool']->priority());
        // Priority orders the report: the prod row comes first even though the two verdicts are equal.
        self::assertSame(['vendor/prod', 'vendor/devtool'], array_map(static fn ($f) => $f->package(), $report->findings()));
        // A dev root is a parent like any other once --dev brings it in.
        self::assertSame(['vendor/devtool'], $byName['vendor/devtool']->directDependents());
        self::assertSame(['vendor/prod'], $byName['vendor/prod']->directDependents());
    }

    public function testAllowlistedIsFinishedAndNotFetchedFromGitHub(): void
    {
        $metadata = [
            'vendor/transitive' => new PackageMetadata('vendor/transitive', false, null, true, new \DateTimeImmutable('2015-11-16T16:30:51+00:00'), '2.0.0', 1, 'https://github.com/vendor/transitive.git', 'library', new \DateTimeImmutable('2026-09-14T06:00:00+00:00')),
        ];
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/transitive', 'version' => '2.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $report = $this->analyzer($this->loader($metadata), $this->http([]), false, new Allowlist([new AllowlistEntry('vendor/*', null, 'complete', null, 'builtin')]))->analyze($lock, ProjectConfig::empty(), false);
        self::assertSame(Verdict::FINISHED, $report->findings()[0]->verdict());
        self::assertSame('complete', $report->findings()[0]->allowlistReason());
    }

    public function testRepositoryMetadataFailureIsUnknownWithNoteAndNetworkFlag(): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/direct', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $report = $this->analyzer($this->loader([], [], ['vendor/direct' => 'HTTP 503']), $this->http([]), true, new Allowlist([]))->analyze($lock, ProjectConfig::empty(), false);
        self::assertSame(Verdict::UNKNOWN, $report->findings()[0]->verdict());
        self::assertTrue($report->hadNetworkFailures());
        self::assertContains('Repository metadata unavailable for 1 package: HTTP 503', $report->notes());
        self::assertSame('Repository metadata unavailable: HTTP 503', $report->findings()[0]->evidence());
    }

    public function testTwoFailedPackagesAreCountedInThePlural(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'vendor/a', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
            ['name' => 'vendor/b', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
        ]]);
        $failed = ['vendor/a' => 'HTTP 503', 'vendor/b' => 'HTTP 503'];

        $report = $this->analyzer($this->loader([], [], $failed), $this->http([]), true, new Allowlist([]))->analyze($lock, ProjectConfig::empty(), false);

        self::assertContains('Repository metadata unavailable for 2 packages: HTTP 503', $report->notes());
    }

    public function testMultipleFailureReasonsAreGroupedWithCountsInsteadOfOnlyNamingTheFirst(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'vendor/a', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
            ['name' => 'vendor/b', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
            ['name' => 'vendor/c', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
            ['name' => 'vendor/d', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
        ]]);
        $failed = [
            'vendor/a' => MetadataLoaderInterface::BUDGET_REASON,
            'vendor/b' => MetadataLoaderInterface::BUDGET_REASON,
            'vendor/c' => MetadataLoaderInterface::BUDGET_REASON,
            'vendor/d' => 'connection refused',
        ];

        $report = $this->analyzer($this->loader([], [], $failed), $this->http([]), true, new Allowlist([]))->analyze($lock, ProjectConfig::empty(), false);

        self::assertContains(
            'Repository metadata unavailable for 4 packages: '.MetadataLoaderInterface::BUDGET_REASON.' (3); connection refused (1)',
            $report->notes()
        );
    }

    public function testTheOfflineReasonIsReportedWithoutASecondPrefix(): void
    {
        // OFFLINE_NOT_FOUND_REASON already reads as a complete statement, so prefixing it would
        // produce "Repository metadata unavailable: offline: not present in Composer's cache".
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/direct', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $failed = ['vendor/direct' => MetadataLoaderInterface::OFFLINE_NOT_FOUND_REASON];

        $report = $this->analyzer($this->loader([], [], $failed), $this->http([]), true, new Allowlist([]), true)->analyze($lock, ProjectConfig::empty(), false);

        self::assertSame(MetadataLoaderInterface::OFFLINE_NOT_FOUND_REASON, $report->findings()[0]->evidence());
    }

    /**
     * One package from a Composer repository with a GitHub source, optionally with a canned
     * GitHub response.
     *
     * @param array{int, string}|null $github status and body for api.github.com/repos/vendor/pkg
     */
    private function singlePackageReport(PackageMetadata $meta, bool $token, ?array $github = null): Report
    {
        $map = [];
        if ($github !== null) {
            $map['https://api.github.com/repos/vendor/pkg'] = $github;
        }
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/pkg', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);

        return $this->analyzer($this->loader(['vendor/pkg' => $meta]), $this->http($map), $token, new Allowlist([]))->analyze($lock, ProjectConfig::empty(), false);
    }

    private function metadataFor(?string $releasedAt): PackageMetadata
    {
        $releaseAt = $releasedAt === null ? null : new \DateTimeImmutable($releasedAt);

        return new PackageMetadata('vendor/pkg', false, null, true, $releaseAt, '1.0.0', 1, 'https://github.com/vendor/pkg.git', 'library', new \DateTimeImmutable(F::NOW));
    }

    /** @return PackageMetadata a release recent enough that S2 never fires (not a candidate) */
    private function recent(): PackageMetadata
    {
        return $this->metadataFor('2026-08-01T00:00:00+00:00');
    }

    /** @return PackageMetadata a release old enough that S2 fires (a candidate for the activity check) */
    private function ancient(): PackageMetadata
    {
        return $this->metadataFor('2015-01-01T00:00:00+00:00');
    }

    public function testWithoutTokenAndNoCandidatesTheSkippedPackagesAreStillReported(): void
    {
        // The package has a GitHub repo but is not a candidate (recent release), so nothing is
        // fetched at all — the note must still say the check did not run.
        $report = $this->singlePackageReport($this->recent(), false);
        self::assertStringContainsString(
            'GitHub token not set: repository activity checked for 0 candidate packages, 1 packages skipped (set GITHUB_TOKEN to check all)',
            implode("\n", $report->notes())
        );
    }

    public function testWithTokenNoTokenNoteIsEmitted(): void
    {
        self::assertSame([], array_values(array_filter(
            $this->singlePackageReport($this->recent(), true)->notes(),
            static fn (string $n): bool => strpos($n, 'GitHub token not set') !== false
        )));
    }

    public function testRateLimitedGitHubIsReportedAsANote(): void
    {
        $report = $this->singlePackageReport($this->ancient(), true, [403, '{"message":"API rate limit exceeded"}']);
        self::assertStringContainsString('GitHub API rate limit reached; repository activity missing for 1 packages', implode("\n", $report->notes()));
        self::assertTrue($report->hadNetworkFailures());
    }

    public function testUnreachableGitHubIsReportedSeparatelyFromRateLimiting(): void
    {
        $report = $this->singlePackageReport($this->ancient(), true, [500, '']);
        $notes = implode("\n", $report->notes());
        self::assertStringContainsString('GitHub unreachable for 1 repositories: HTTP 500', $notes);
        self::assertStringNotContainsString('rate limit', $notes);
        self::assertTrue($report->hadNetworkFailures());
    }

    public function testCountsByVerdictCoverAllVerdicts(): void
    {
        $report = $this->analyzer($this->loader(), $this->http([]), true, new Allowlist([]))->analyze(LockFile::fromArray(['packages' => []]), ProjectConfig::empty(), false);
        self::assertSame(array_fill_keys(Verdict::all(), 0), $report->byVerdict());
    }

    public function testAllowlistAccessorReturnsConstructorValue(): void
    {
        $allowlist = new Allowlist([new AllowlistEntry('vendor/*', null, 'complete', null, 'builtin')]);
        $analyzer = $this->analyzer($this->loader(), $this->http([]), true, $allowlist);
        self::assertSame($allowlist, $analyzer->allowlist());
    }

    /**
     * Install time checks only the packages in the transaction, but the chain still has to be
     * resolved through the whole lock file — the mini fixture's vendor/transitive is pulled in by
     * vendor/direct, which is not part of the subset handed to analyzePackages().
     */
    public function testAnalyzePackagesChecksOnlyTheSubsetButResolvesChainsThroughTheFullLock(): void
    {
        $lock = LockFile::fromFile(__DIR__.'/../../fixtures/mini/composer.lock');
        $project = ProjectConfig::fromFile(__DIR__.'/../../fixtures/mini/composer.json');
        $transitive = $lock->find('vendor/transitive');
        self::assertNotNull($transitive);
        $metadata = [
            'vendor/transitive' => new PackageMetadata('vendor/transitive', false, null, true, new \DateTimeImmutable('2015-11-16T16:30:51+00:00'), '2.0.0', 1, 'https://github.com/vendor/transitive.git', 'library', new \DateTimeImmutable(F::NOW)),
        ];
        $http = $this->http([
            'https://api.github.com/repos/vendor/transitive' => [200, '{"archived":false,"pushed_at":"2015-11-16T16:31:37Z"}'],
        ]);

        $report = $this->analyzer($this->loader($metadata), $http, true, new Allowlist([]))
            ->analyzePackages([$transitive], $lock, $project, false);

        self::assertSame(1, $report->packagesChecked());
        self::assertCount(1, $report->findings());
        self::assertSame('vendor/transitive', $report->findings()[0]->package());
        self::assertSame(['vendor/direct', 'vendor/transitive'], $report->findings()[0]->chain());
        // The parents are resolved through the full lock too, and the exposure line names the
        // root even though it is not in the checked subset — the S7 signal, which needs a finding
        // to sit on, is not invented for it.
        self::assertSame(['vendor/direct'], $report->findings()[0]->directDependents());
        self::assertSame(['vendor/direct' => 1], $report->exposure());
    }

    public function testAnExhaustedDeadlineSkipsTheGitHubCallAndSaysSo(): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/pkg', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $http = new class () implements HttpClientInterface {
            public int $calls = 0;

            public function fetchAll(array $urls, array $headers = []): array
            {
                ++$this->calls;

                return [];
            }
        };
        $analyzer = $this->analyzer($this->loader(['vendor/pkg' => $this->ancient()]), $http, true, new Allowlist([]))
            ->withDeadline(Deadline::inSeconds(0.0, static fn (): float => 0.0));

        $report = $analyzer->analyze($lock, ProjectConfig::empty(), false);

        self::assertSame(0, $http->calls);
        self::assertContains('repository activity not checked: install-time budget exhausted', $report->notes());
    }

    public function testWithDeadlineReturnsAClonedInstance(): void
    {
        $analyzer = $this->analyzer($this->loader(), $this->http([]), true, new Allowlist([]));

        self::assertNotSame($analyzer, $analyzer->withDeadline(Deadline::inSeconds(5.0)));
    }

    public function testTheBudgetReasonIsReportedWithoutASecondPrefix(): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/direct', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $failed = ['vendor/direct' => MetadataLoaderInterface::BUDGET_REASON];

        $report = $this->analyzer($this->loader([], [], $failed), $this->http([]), true, new Allowlist([]))->analyze($lock, ProjectConfig::empty(), false);

        self::assertSame(MetadataLoaderInterface::BUDGET_REASON, $report->findings()[0]->evidence());
    }

    public function testWithAllowlistReturnsClonedInstanceLeavingOriginalUnchanged(): void
    {
        $original = new Allowlist([]);
        $replacement = new Allowlist([new AllowlistEntry('vendor/*', null, 'complete', null, 'builtin')]);
        $analyzer = $this->analyzer($this->loader(), $this->http([]), true, $original);

        $withReplacement = $analyzer->withAllowlist($replacement);

        self::assertNotSame($analyzer, $withReplacement);
        self::assertSame($replacement, $withReplacement->allowlist());
        self::assertSame($original, $analyzer->allowlist());
    }

    /** @return array<string, mixed> a lock entry that a Composer repository serves, with the lock's own source URL when given */
    private static function locked(string $name, ?string $sourceUrl = null): array
    {
        $entry = ['name' => $name, 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'];
        if ($sourceUrl !== null) {
            $entry['source'] = ['type' => 'git', 'url' => $sourceUrl, 'reference' => 'a'];
        }

        return $entry;
    }

    /** @return array<string, mixed> a lock entry from a VCS repository, so not checked */
    private static function unlocked(string $name, string $sourceUrl): array
    {
        return ['name' => $name, 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => $sourceUrl, 'reference' => 'a']];
    }

    private function metadataNamed(string $name, ?string $sourceUrl, string $releasedAt = '2015-01-01T00:00:00+00:00', string $dataDate = F::NOW): PackageMetadata
    {
        return new PackageMetadata($name, false, null, true, new \DateTimeImmutable($releasedAt), '1.0.0', 1, $sourceUrl, 'library', new \DateTimeImmutable($dataDate));
    }

    /** @return array<string, \Lockrot\Verdict\Finding> */
    private static function byName(Report $report): array
    {
        $byName = [];
        foreach ($report->findings() as $finding) {
            $byName[$finding->package()] = $finding;
        }

        return $byName;
    }

    /** @param list<array<string, mixed>> $packages */
    private function analyzeLock(array $packages, MetadataLoaderInterface $loader, HttpClientInterface $http, bool $token, ?Allowlist $allowlist = null, int $noTokenBudget = GitHubFetchPlanner::DEFAULT_NO_TOKEN_BUDGET): Report
    {
        return $this->analyzer($loader, $http, $token, $allowlist ?? new Allowlist([]), false, $noTokenBudget)
            ->analyze(LockFile::fromArray(['packages' => $packages]), ProjectConfig::empty(), false);
    }

    public function testPackagesNotFromAComposerRepositoryAreCountedInOneNote(): void
    {
        $one = self::unlocked('private/one', 'https://git.example.com/private/one.git');
        $two = self::unlocked('private/two', 'https://git.example.com/private/two.git');

        self::assertSame([], $this->analyzeLock([], $this->loader(), $this->http([]), true)->notes());
        self::assertSame(
            ['1 package is not from a Composer repository and was not checked'],
            $this->analyzeLock([$one], $this->loader(), $this->http([]), true)->notes()
        );
        $report = $this->analyzeLock([$one, $two], $this->loader(), $this->http([]), true);
        self::assertSame(['2 packages are not from a Composer repository and were not checked'], $report->notes());
        self::assertSame(2, $report->notFromComposerRepository());
    }

    public function testAPackageTheRepositoryDoesNotKnowIsUnknownWithANoteAndAKnownQuietOneHasNoEvidence(): void
    {
        $metadata = ['vendor/fine' => $this->metadataNamed('vendor/fine', null, '2026-08-01T00:00:00+00:00')];
        $report = $this->analyzeLock([self::locked('vendor/gone'), self::locked('vendor/fine')], $this->loader($metadata, ['vendor/gone']), $this->http([]), true);
        $byName = self::byName($report);

        self::assertSame(Verdict::UNKNOWN, $byName['vendor/gone']->verdict());
        self::assertSame('not found in the repository', $byName['vendor/gone']->evidence());
        self::assertSame(Verdict::OK, $byName['vendor/fine']->verdict());
        self::assertSame('', $byName['vendor/fine']->evidence());
        self::assertFalse($report->hadNetworkFailures());
        self::assertSame([], $report->notes());
    }

    public function testAnAllowlistedOrNonRepositoryPackageIsNeverLookedUpOnGitHub(): void
    {
        $requested = new \ArrayObject();
        $packages = [
            self::locked('vendor/allowlisted', 'https://github.com/vendor/allowlisted.git'),
            self::unlocked('private/mirror', 'https://github.com/private/mirror.git'),
        ];
        $metadata = ['vendor/allowlisted' => $this->metadataNamed('vendor/allowlisted', 'https://github.com/vendor/allowlisted.git')];
        $allowlist = new Allowlist([new AllowlistEntry('vendor/allowlisted', null, 'complete', null, 'builtin')]);

        $byName = self::byName($this->analyzeLock($packages, $this->loader($metadata), $this->http([], $requested), true, $allowlist));

        self::assertSame([], $requested->getArrayCopy());
        self::assertSame(Verdict::FINISHED, $byName['vendor/allowlisted']->verdict());
        self::assertSame(Verdict::UNKNOWN, $byName['private/mirror']->verdict());
    }

    public function testTheRepositorysSourceUrlOutranksTheLocksAndTheLocksIsTheFallback(): void
    {
        $packages = [self::locked('vendor/pkg', 'https://github.com/old-owner/pkg.git')];
        $answer = [200, '{"archived":false,"pushed_at":"2026-08-01T00:00:00Z"}'];

        $requested = new \ArrayObject();
        $loader = $this->loader(['vendor/pkg' => $this->metadataNamed('vendor/pkg', 'https://github.com/new-owner/pkg.git')]);
        $report = $this->analyzeLock($packages, $loader, $this->http(['https://api.github.com/repos/new-owner/pkg' => $answer], $requested), true);
        self::assertSame(['https://api.github.com/repos/new-owner/pkg'], $requested->getArrayCopy());
        self::assertFalse($report->hadNetworkFailures());

        $requested = new \ArrayObject();
        $loader = $this->loader(['vendor/pkg' => $this->metadataNamed('vendor/pkg', null)]);
        $report = $this->analyzeLock($packages, $loader, $this->http(['https://api.github.com/repos/old-owner/pkg' => $answer], $requested), true);
        self::assertSame(['https://api.github.com/repos/old-owner/pkg'], $requested->getArrayCopy());
        self::assertFalse($report->hadNetworkFailures());
    }

    public function testWithoutATokenTheCandidatesBeyondTheBudgetCountAsSkippedToo(): void
    {
        $packages = [];
        $metadata = [];
        $answers = [];
        foreach (['vendor/one', 'vendor/two', 'vendor/three'] as $name) {
            $packages[] = self::locked($name);
            $metadata[$name] = $this->metadataNamed($name, 'https://github.com/'.$name.'.git');
            $answers['https://api.github.com/repos/'.$name] = [200, '{"archived":false,"pushed_at":"2015-01-01T00:00:00Z"}'];
        }

        // Three candidates (released 2015, so S2 fires for each), a budget of two: one is skipped for
        // the budget, none for the token, and the note counts both kinds together.
        $report = $this->analyzeLock($packages, $this->loader($metadata), $this->http($answers), false, null, 2);

        self::assertContains(
            'GitHub token not set: repository activity checked for 2 candidate packages, 1 packages skipped (set GITHUB_TOKEN to check all)',
            $report->notes()
        );
    }

    public function testTheDataDateIsTheNewerOfTheMetadataAndTheRepositoryActivity(): void
    {
        // The HTTP fake stamps every answer 2026-09-14T06:00:00+00:00: that is when the activity was fetched.
        $answer = [200, '{"archived":false,"pushed_at":"2026-08-01T00:00:00Z"}'];
        $github = 'https://github.com/vendor/pkg.git';

        $activityOnly = $this->analyzeLock([self::locked('vendor/pkg', $github)], $this->loader([], ['vendor/pkg']), $this->http(['https://api.github.com/repos/vendor/pkg' => $answer]), true);
        self::assertSame('2026-09-14T06:00:00+00:00', self::dataDateOf($activityOnly));

        $metadataOnly = $this->singlePackageReport($this->metadataNamed('vendor/pkg', null, '2026-08-01T00:00:00+00:00', '2026-09-14T12:00:00+00:00'), true);
        self::assertSame('2026-09-14T12:00:00+00:00', self::dataDateOf($metadataOnly));

        $activityNewer = $this->singlePackageReport($this->metadataNamed('vendor/pkg', $github, '2026-08-01T00:00:00+00:00', '2026-09-14T00:00:00+00:00'), true, $answer);
        self::assertSame('2026-09-14T06:00:00+00:00', self::dataDateOf($activityNewer));

        $metadataNewer = $this->singlePackageReport($this->metadataNamed('vendor/pkg', $github, '2026-08-01T00:00:00+00:00', '2026-09-14T12:00:00+00:00'), true, $answer);
        self::assertSame('2026-09-14T12:00:00+00:00', self::dataDateOf($metadataNewer));
    }

    private static function dataDateOf(Report $report): string
    {
        $date = $report->findings()[0]->dataDate();
        self::assertNotNull($date);

        return $date->format(\DATE_ATOM);
    }
}
