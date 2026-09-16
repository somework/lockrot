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
    /** @param array<string, array{int, string}> $map url => [status, body] */
    private function http(array $map): HttpClientInterface
    {
        return new class ($map) implements HttpClientInterface {
            /** @var array<string, array{int, string}> */
            private array $map;

            /** @param array<string, array{int, string}> $map */
            public function __construct(array $map)
            {
                $this->map = $map;
            }
            public function fetchAll(array $urls, array $headers = []): array
            {
                $out = [];
                foreach ($urls as $url) {
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

    private function analyzer(MetadataLoaderInterface $metadata, HttpClientInterface $http, bool $token, Allowlist $allowlist, bool $offline = false): Analyzer
    {
        $clock = Clock::fixed(F::NOW);
        return new Analyzer(
            $metadata,
            new GitHubClient($http, $token ? 't' : null),
            new GitHubFetchPlanner($token),
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
}
