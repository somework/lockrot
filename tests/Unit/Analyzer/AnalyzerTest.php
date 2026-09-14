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
use Lockrot\Data\Packagist\PackagistClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
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

    /** @param list<array<string, mixed>> $versions */
    private function p2(string $name, array $versions): string
    {
        $json = json_encode(['packages' => [$name => $versions], 'minified' => 'composer/2.0']);

        return $json === false ? '' : $json;
    }

    private function analyzer(HttpClientInterface $http, bool $token, Allowlist $allowlist): Analyzer
    {
        $clock = Clock::fixed(F::NOW);
        return new Analyzer(
            new PackagistClient($http),
            new GitHubClient($http, $token ? 't' : null),
            new GitHubFetchPlanner($token),
            $allowlist,
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock
        );
    }

    public function testMiniProjectVerdicts(): void
    {
        $http = $this->http([
            'https://repo.packagist.org/p2/vendor/direct.json' => [200, $this->p2('vendor/direct', [['name' => 'vendor/direct', 'version' => '1.2.3', 'time' => '2024-01-10T10:00:00+00:00', 'source' => ['url' => 'https://github.com/vendor/direct.git']]])],
            'https://repo.packagist.org/p2/vendor/transitive.json' => [200, $this->p2('vendor/transitive', [['name' => 'vendor/transitive', 'version' => '2.0.0', 'time' => '2015-11-16T16:30:51+00:00', 'source' => ['url' => 'https://github.com/vendor/transitive.git']]])],
            'https://repo.packagist.org/p2/vendor/snapshot.json' => [200, $this->p2('vendor/snapshot', [])],
            'https://repo.packagist.org/p2/vendor/snapshot~dev.json' => [200, $this->p2('vendor/snapshot', [['name' => 'vendor/snapshot', 'version' => 'dev-master', 'time' => '2015-08-31T22:07:48+00:00']])],
            'https://api.github.com/repos/vendor/transitive' => [200, '{"archived":false,"pushed_at":"2015-11-16T16:31:37Z"}'],
        ]);
        $report = $this->analyzer($http, false, new Allowlist([]))->analyze(
            LockFile::fromFile(__DIR__.'/../../fixtures/mini/composer.lock'),
            ProjectConfig::fromFile(__DIR__.'/../../fixtures/mini/composer.json'),
            false
        );
        $byName = [];
        foreach ($report->findings() as $finding) {
            $byName[$finding->package()] = $finding;
        }
        // vendor/direct is released 2024-01-10 with an open-ended "php": ">=7.4" constraint, which
        // predates the PHP 8.4 GA (2024-11-21) used as targetPhp below -> S5 fires per the spec
        // ("installed version released before target PHP GA while its constraint is open-ended"),
        // giving OLD_PROMISE rather than OK. The mini fixture (tests/fixtures/mini/composer.lock) is
        // shared with LockFileTest/DependencyGraphTest/ProjectConfigTest and predates the OldPromiseRule
        // design, so this is documented here instead of edited into the fixture.
        self::assertSame(Verdict::OLD_PROMISE, $byName['vendor/direct']->verdict());
        self::assertSame(Verdict::SILENT, $byName['vendor/transitive']->verdict());
        self::assertSame(['vendor/direct', 'vendor/transitive'], $byName['vendor/transitive']->chain());
        self::assertNotNull($byName['vendor/transitive']->dataDate());
        self::assertSame('2026-09-14T06:00:00+00:00', $byName['vendor/transitive']->dataDate()->format(\DATE_ATOM));
        self::assertSame(Verdict::PINNED, $byName['vendor/snapshot']->verdict());
        self::assertSame(Verdict::UNKNOWN, $byName['private/thing']->verdict());
        self::assertSame('not on Packagist, not checked', $byName['private/thing']->evidence());
        self::assertSame(4, $report->packagesChecked());
        self::assertSame(1, $report->notOnPackagist());
        self::assertFalse($report->hadNetworkFailures());
        // Sort order is severity desc, name asc: SILENT(50) > PINNED(40) > OLD_PROMISE(30) > UNKNOWN(10).
        self::assertSame(['vendor/transitive', 'vendor/snapshot', 'vendor/direct', 'private/thing'], array_map(static fn ($f) => $f->package(), $report->findings()));
        self::assertNotEmpty(array_filter($report->notes(), static fn (string $n): bool => strpos($n, 'GitHub token not set') !== false));
    }

    public function testAllowlistedIsFinishedAndNotFetchedFromGitHub(): void
    {
        $http = $this->http([
            'https://repo.packagist.org/p2/vendor/transitive.json' => [200, $this->p2('vendor/transitive', [['name' => 'vendor/transitive', 'version' => '2.0.0', 'time' => '2015-11-16T16:30:51+00:00', 'source' => ['url' => 'https://github.com/vendor/transitive.git']]])],
        ]);
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/transitive', 'version' => '2.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $report = $this->analyzer($http, false, new Allowlist([new AllowlistEntry('vendor/*', null, 'complete', null, 'builtin')]))->analyze($lock, ProjectConfig::empty(), false);
        self::assertSame(Verdict::FINISHED, $report->findings()[0]->verdict());
        self::assertSame('complete', $report->findings()[0]->allowlistReason());
    }

    public function testPackagistFailureIsUnknownWithNoteAndNetworkFlag(): void
    {
        $http = $this->http(['https://repo.packagist.org/p2/vendor/direct.json' => [503, '']]);
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/direct', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $report = $this->analyzer($http, true, new Allowlist([]))->analyze($lock, ProjectConfig::empty(), false);
        self::assertSame(Verdict::UNKNOWN, $report->findings()[0]->verdict());
        self::assertTrue($report->hadNetworkFailures());
        self::assertStringContainsString('Packagist', implode(' ', $report->notes()));
    }

    /**
     * One package on Packagist with a GitHub source, optionally with a canned GitHub response.
     *
     * @param list<array<string, mixed>> $versions
     * @param array{int, string}|null    $github   status and body for api.github.com/repos/vendor/pkg
     */
    private function singlePackageReport(array $versions, bool $token, ?array $github = null): Report
    {
        $map = ['https://repo.packagist.org/p2/vendor/pkg.json' => [200, $this->p2('vendor/pkg', $versions)]];
        if ($github !== null) {
            $map['https://api.github.com/repos/vendor/pkg'] = $github;
        }
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/pkg', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);

        return $this->analyzer($this->http($map), $token, new Allowlist([]))->analyze($lock, ProjectConfig::empty(), false);
    }

    /** @return list<array<string, mixed>> a release recent enough that S2 never fires (not a candidate) */
    private function recent(): array
    {
        return [['name' => 'vendor/pkg', 'version' => '1.0.0', 'time' => '2026-08-01T00:00:00+00:00', 'source' => ['url' => 'https://github.com/vendor/pkg.git']]];
    }

    /** @return list<array<string, mixed>> a release old enough that S2 fires (a candidate for the activity check) */
    private function ancient(): array
    {
        return [['name' => 'vendor/pkg', 'version' => '1.0.0', 'time' => '2015-01-01T00:00:00+00:00', 'source' => ['url' => 'https://github.com/vendor/pkg.git']]];
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
        $report = $this->analyzer($this->http([]), true, new Allowlist([]))->analyze(LockFile::fromArray(['packages' => []]), ProjectConfig::empty(), false);
        self::assertSame(array_fill_keys(Verdict::all(), 0), $report->byVerdict());
    }

    public function testAllowlistAccessorReturnsConstructorValue(): void
    {
        $allowlist = new Allowlist([new AllowlistEntry('vendor/*', null, 'complete', null, 'builtin')]);
        $analyzer = $this->analyzer($this->http([]), true, $allowlist);
        self::assertSame($allowlist, $analyzer->allowlist());
    }

    public function testWithAllowlistReturnsClonedInstanceLeavingOriginalUnchanged(): void
    {
        $original = new Allowlist([]);
        $replacement = new Allowlist([new AllowlistEntry('vendor/*', null, 'complete', null, 'builtin')]);
        $analyzer = $this->analyzer($this->http([]), true, $original);

        $withReplacement = $analyzer->withAllowlist($replacement);

        self::assertNotSame($analyzer, $withReplacement);
        self::assertSame($replacement, $withReplacement->allowlist());
        self::assertSame($original, $analyzer->allowlist());
    }
}
