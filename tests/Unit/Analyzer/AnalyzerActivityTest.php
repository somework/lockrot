<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Allowlist\Allowlist;
use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunNote;
use Lockrot\Clock;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Advisory\AdvisoryBatch;
use Lockrot\Data\Advisory\AdvisoryLoaderInterface;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\PackageOrigin;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\TestCase;

/**
 * What the repository activity lookup did for each package (`finding.activity`), the one reason by
 * precedence, and where each check that did not run goes: S10 or `checks_skipped`
 * (docs/verdicts.md#what-was-not-checked).
 */
final class AnalyzerActivityTest extends TestCase
{
    /** @var list<string> the URLs that the fake HTTP client of the last run was asked */
    private array $asked = [];

    private const GH = 'https://api.github.com/repos/vendor/pkg';
    private const GH_OTHER = 'https://api.github.com/repos/vendor/other';
    private const GL_COMMITS = 'https://gitlab.com/api/v4/projects/group%2Fpkg/repository/commits?all=true&per_page=1';
    private const GL_PROJECT = 'https://gitlab.com/api/v4/projects/group%2Fpkg';
    private const LIVE = '{"archived":false,"pushed_at":"2026-08-01T00:00:00Z"}';
    private const COMMIT = '[{"committed_date":"2026-08-01T00:00:00Z"}]';
    private const GITEA = 'https://gitea.example.com:3000/vendor/pkg.git';
    private const ACTIVITY_SKIPS = [['check' => 'repository_activity', 'reason' => 'not_from_composer_repository', 'blocks' => ['S3', 'S4']], ['check' => 'release_metadata', 'reason' => 'not_from_composer_repository', 'blocks' => ['S2', 'S8']]];
    private const ORIGIN_S10 = [['check' => 'release_metadata', 'reason' => 'not_from_composer_repository', 'blocks' => ['S2', 'S8']], ['check' => 'repository_activity', 'reason' => 'not_from_composer_repository', 'blocks' => ['S3', 'S4']]];

    public function testAGithubReadPublishesTheForgeReadingAndNoActivityEntry(): void
    {
        $finding = $this->one([self::packagist('vendor/pkg')], ['vendor/pkg' => self::meta('vendor/pkg', 'https://github.com/vendor/pkg.git')], [self::GH => [200, self::LIVE]], true);

        self::assertSame(
            ['status' => 'read', 'reason' => null, 'forge' => 'github', 'host' => 'github.com', 'repo' => 'vendor/pkg', 'event' => 'push', 'archived' => false, 'fetched_at' => '2026-09-14T06:00:00+00:00', 'cached_at' => null],
            $finding['activity']
        );
        self::assertSame([], self::checks($finding, 'repository_activity'));
        self::assertSame([], self::checks($finding, 'repository_archived'));
        self::assertSame([self::GH], $this->asked);
    }

    public function testAnAnonymousGitlabReadLeavesTheArchivedFlagUnreadForWantOfAToken(): void
    {
        $finding = $this->one([self::packagist('vendor/pkg')], ['vendor/pkg' => self::meta('vendor/pkg', 'https://gitlab.com/group/pkg.git')], [self::GL_COMMITS => [200, self::COMMIT]], false);

        self::assertSame(['read', null, 'gitlab', 'commit', null], [JsonPath::arrayAt($finding, ['activity'])['status'], JsonPath::arrayAt($finding, ['activity'])['reason'], JsonPath::arrayAt($finding, ['activity'])['forge'], JsonPath::arrayAt($finding, ['activity'])['event'], JsonPath::arrayAt($finding, ['activity'])['archived']]);
        self::assertSame([['check' => 'repository_archived', 'reason' => 'no_token', 'blocks' => ['S3']]], self::checks($finding, 'repository_archived'));
        self::assertSame([], $finding['checks_missing']);
    }

    public function testAnAuthenticatedGitlabReadWhoseProjectRequestFailedLeavesTheArchivedFlagUnread(): void
    {
        $auth = ForgeAuth::withTokens(new Tokens(null, 'glpat-x'));
        $finding = $this->one([self::packagist('vendor/pkg')], ['vendor/pkg' => self::meta('vendor/pkg', 'https://gitlab.com/group/pkg.git')], [self::GL_COMMITS => [200, self::COMMIT], self::GL_PROJECT => [500, '']], false, null, $auth);

        self::assertNull(JsonPath::arrayAt($finding, ['activity'])['archived']);
        self::assertSame([['check' => 'repository_archived', 'reason' => 'fetch_failed', 'blocks' => ['S3']]], self::checks($finding, 'repository_archived'));
    }

    public function testAnAuthenticatedGitlabReadThatCarriesTheFlagNeedsNoArchivedEntry(): void
    {
        $auth = ForgeAuth::withTokens(new Tokens(null, 'glpat-x'));
        $finding = $this->one([self::packagist('vendor/pkg')], ['vendor/pkg' => self::meta('vendor/pkg', 'https://gitlab.com/group/pkg.git')], [self::GL_COMMITS => [200, self::COMMIT], self::GL_PROJECT => [200, '{"archived":false}']], false, null, $auth);

        self::assertFalse(JsonPath::arrayAt($finding, ['activity'])['archived']);
        self::assertSame([], self::checks($finding, 'repository_archived'));
    }

    public function testA404IsAnAnswerThatSkipsTheActivityChecks(): void
    {
        $finding = $this->one([self::packagist('vendor/pkg')], ['vendor/pkg' => self::meta('vendor/pkg', 'https://github.com/vendor/pkg.git')], [self::GH => [404, '']], true);

        self::assertSame(
            ['status' => 'not_found', 'reason' => 'repository_not_found', 'forge' => 'github', 'host' => 'github.com', 'repo' => 'vendor/pkg', 'event' => null, 'archived' => null, 'fetched_at' => null, 'cached_at' => null],
            $finding['activity']
        );
        self::assertSame([['check' => 'repository_activity', 'reason' => 'repository_not_found', 'blocks' => ['S3', 'S4']]], self::checks($finding, 'repository_activity'));
        self::assertSame([], $finding['checks_missing']);
    }

    public function testA404OnARateLimitedForgeStaysNotFoundAndRaisesNoS10(): void
    {
        $packages = [self::packagist('vendor/pkg'), self::packagist('vendor/other')];
        $metadata = ['vendor/pkg' => self::meta('vendor/pkg', 'https://github.com/vendor/pkg.git'), 'vendor/other' => self::meta('vendor/other', 'https://github.com/vendor/other.git')];

        $byName = $this->findings($packages, $metadata, [self::GH => [404, ''], self::GH_OTHER => [429, '']], true);

        self::assertSame(['not_found', 'repository_not_found'], [JsonPath::arrayAt($byName['vendor/pkg'], ['activity'])['status'], JsonPath::arrayAt($byName['vendor/pkg'], ['activity'])['reason']]);
        self::assertSame([], $byName['vendor/pkg']['checks_missing'], 'an answer beats a rate limit');
        self::assertSame([['check' => 'repository_activity', 'reason' => 'rate_limit', 'blocks' => ['S3', 'S4']]], $byName['vendor/other']['checks_missing'], 'the control: the rate limit is S10');
    }

    public function testARateLimitOnOneGitlabHostLeavesAFailureOnAnotherAFetchFailure(): void
    {
        $packages = [self::packagist('vendor/pkg'), self::packagist('vendor/other')];
        $metadata = ['vendor/pkg' => self::meta('vendor/pkg', 'https://gitlab.com/group/pkg.git'), 'vendor/other' => self::meta('vendor/other', 'https://gitlab.example.com/group/other.git')];
        $analyzer = $this->analyzer($this->loader($metadata), $this->http([self::GL_COMMITS => [429, '']]), ForgeAuth::anonymous(), new Allowlist([]), false, ActivityFetchPlanner::DEFAULT_ANONYMOUS_BUDGET, null, new RepoLocator(['gitlab.com', 'gitlab.example.com']));

        $report = $analyzer->analyze(LockFile::fromArray(['packages' => $packages]), ProjectConfig::empty(), false);
        $byName = self::byName($report);

        self::assertSame('rate_limit', JsonPath::arrayAt($byName['vendor/pkg'], ['activity'])['reason']);
        self::assertSame('fetch_failed', JsonPath::arrayAt($byName['vendor/other'], ['activity'])['reason'], 'the other host answered 500, not 429');
        $named = [];
        foreach ($report->runNotes() as $note) {
            if (\in_array($note->code(), [RunNote::REPOSITORY_ACTIVITY_RATE_LIMITED, RunNote::REPOSITORY_ACTIVITY_UNREACHABLE], true)) {
                $named[$note->code()] = array_column(JsonPath::arrayAt($note->data(), ['repositories']), 'host');
            }
        }
        self::assertSame([RunNote::REPOSITORY_ACTIVITY_RATE_LIMITED => ['gitlab.com'], RunNote::REPOSITORY_ACTIVITY_UNREACHABLE => ['gitlab.example.com']], $named);
    }

    public function testAHostLockrotCannotAskRaisesS10AndOneNote(): void
    {
        $report = $this->report([self::packagist('vendor/pkg')], ['vendor/pkg' => self::meta('vendor/pkg', self::GITEA)], [], true);
        $finding = JsonPath::decoded($report->findings()[0]->toArray());

        self::assertSame(
            ['status' => 'not_read', 'reason' => 'host_not_supported', 'forge' => null, 'host' => 'gitea.example.com:3000', 'repo' => null, 'event' => null, 'archived' => null, 'fetched_at' => null, 'cached_at' => null],
            $finding['activity']
        );
        self::assertSame([['check' => 'repository_activity', 'reason' => 'host_not_supported', 'blocks' => ['S3', 'S4']]], $finding['checks_missing']);
        self::assertSame([], self::checks($finding, 'repository_activity', ['checks_skipped']));
        $notes = array_values(array_filter($report->runNotes(), static fn (RunNote $note): bool => $note->code() === 'repository_activity_host_not_supported'));
        self::assertCount(1, $notes);
        self::assertSame([['host' => 'gitea.example.com:3000', 'packages' => 1, 'listed_in' => null]], JsonPath::arrayAt(JsonPath::decoded($notes[0]->toArray()), ['data', 'hosts']));
        self::assertSame([], $this->asked, 'lockrot sends no request to a host it cannot ask');
    }

    public function testAnUnsupportedHostOnARegistryAbandonedPackageIsASkip(): void
    {
        $abandoned = new PackageMetadata('vendor/pkg', true, null, true, new \DateTimeImmutable('2015-01-01T00:00:00+00:00'), '1.0.0', 1, self::GITEA, 'library', new \DateTimeImmutable(F::NOW));
        $report = $this->report([self::packagist('vendor/pkg')], ['vendor/pkg' => $abandoned], [], true);
        $finding = JsonPath::decoded($report->findings()[0]->toArray());

        self::assertSame('host_not_supported', JsonPath::arrayAt($finding, ['activity'])['reason']);
        self::assertSame([], $finding['checks_missing'], 'S1 drops the gap');
        self::assertSame([['check' => 'repository_activity', 'reason' => 'host_not_supported', 'blocks' => ['S3', 'S4']]], self::checks($finding, 'repository_activity', ['checks_skipped']));
        self::assertSame(['repository_activity_host_not_supported'], array_values(array_filter(array_map(static fn (RunNote $note): string => $note->code(), $report->runNotes()), static fn (string $code): bool => $code === 'repository_activity_host_not_supported')), 'the note counts a skip too');
    }

    public function testAnAllowlistedPackageOnAnUnsupportedHostIsAllowlistedWithItsHost(): void
    {
        $allowlist = new Allowlist([new AllowlistEntry('vendor/*', null, 'complete', null, 'builtin')]);
        $report = $this->report([self::packagist('vendor/pkg')], ['vendor/pkg' => self::meta('vendor/pkg', self::GITEA)], [], true, $allowlist);
        $finding = JsonPath::decoded($report->findings()[0]->toArray());

        self::assertSame(['not_read', 'allowlisted', null, 'gitea.example.com:3000'], [JsonPath::arrayAt($finding, ['activity'])['status'], JsonPath::arrayAt($finding, ['activity'])['reason'], JsonPath::arrayAt($finding, ['activity'])['forge'], JsonPath::arrayAt($finding, ['activity'])['host']]);
        self::assertSame([['check' => 'repository_activity', 'reason' => 'allowlisted', 'blocks' => ['S3', 'S4']]], self::checks($finding, 'repository_activity'));
        self::assertSame([], array_filter($report->runNotes(), static fn (RunNote $note): bool => $note->code() === 'repository_activity_host_not_supported'));
    }

    public function testAnAllowlistedPackageOnAnAskedHostKeepsItsForgeAndRepo(): void
    {
        $allowlist = new Allowlist([new AllowlistEntry('vendor/*', null, 'complete', null, 'builtin')]);
        $finding = $this->one([self::packagist('vendor/pkg')], ['vendor/pkg' => self::meta('vendor/pkg', 'https://github.com/vendor/pkg.git')], [], true, $allowlist);

        self::assertSame(['allowlisted', 'github', 'github.com', 'vendor/pkg'], [JsonPath::arrayAt($finding, ['activity'])['reason'], JsonPath::arrayAt($finding, ['activity'])['forge'], JsonPath::arrayAt($finding, ['activity'])['host'], JsonPath::arrayAt($finding, ['activity'])['repo']]);
        self::assertSame([], $this->asked, 'an allowlisted package is not asked');
    }

    public function testAPackageWithNoRepositoryUrlIsNoRepository(): void
    {
        $finding = $this->one([self::packagist('vendor/pkg')], ['vendor/pkg' => self::meta('vendor/pkg', null)], [], true);

        self::assertSame(['not_read', 'no_repository', null, null], [JsonPath::arrayAt($finding, ['activity'])['status'], JsonPath::arrayAt($finding, ['activity'])['reason'], JsonPath::arrayAt($finding, ['activity'])['forge'], JsonPath::arrayAt($finding, ['activity'])['host']]);
        self::assertSame([['check' => 'repository_activity', 'reason' => 'no_repository', 'blocks' => ['S3', 'S4']]], self::checks($finding, 'repository_activity'));
        self::assertSame([], $this->asked);
    }

    /**
     * @dataProvider networkCauses
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('networkCauses')]
    public function testANetworkCauseIsS10WithTheLocatedRepository(string $reason, bool $token, bool $offline): void
    {
        $analyzer = $this->analyzer($this->loader(['vendor/pkg' => self::meta('vendor/pkg', 'https://github.com/vendor/pkg.git')]), $this->http([]), $token ? ForgeAuth::withTokens(new Tokens('t', null)) : ForgeAuth::anonymous(), new Allowlist([]), $offline);
        $finding = JsonPath::decoded($analyzer->analyze(LockFile::fromArray(['packages' => [self::packagist('vendor/pkg')]]), ProjectConfig::empty(), false)->findings()[0]->toArray());

        self::assertSame(['not_read', $reason, 'github', 'github.com', 'vendor/pkg'], [JsonPath::arrayAt($finding, ['activity'])['status'], JsonPath::arrayAt($finding, ['activity'])['reason'], JsonPath::arrayAt($finding, ['activity'])['forge'], JsonPath::arrayAt($finding, ['activity'])['host'], JsonPath::arrayAt($finding, ['activity'])['repo']]);
        self::assertSame([['check' => 'repository_activity', 'reason' => $reason, 'blocks' => ['S3', 'S4']]], $finding['checks_missing']);
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function networkCauses(): iterable
    {
        yield 'offline' => ['offline', true, true];
        yield 'anonymous, not a candidate' => ['no_token', false, false];
        yield 'fetch failed' => ['fetch_failed', true, false];
    }

    public function testAnAnonymousCandidateBeyondTheBudgetIsAnonymousBudget(): void
    {
        $old = '2015-01-01T00:00:00+00:00';
        $packages = [self::packagist('vendor/other'), self::packagist('vendor/pkg')];
        $metadata = ['vendor/other' => self::meta('vendor/other', 'https://github.com/vendor/other.git', $old), 'vendor/pkg' => self::meta('vendor/pkg', 'https://github.com/vendor/pkg.git', $old)];

        $byName = $this->findings($packages, $metadata, [self::GH_OTHER => [200, self::LIVE]], false, null, null, 1);

        self::assertSame('read', JsonPath::arrayAt($byName['vendor/other'], ['activity'])['status'], 'the first candidate takes the budget');
        self::assertSame([['check' => 'repository_activity', 'reason' => 'anonymous_budget', 'blocks' => ['S3', 'S4']]], $byName['vendor/pkg']['checks_missing']);
    }

    public function testAnInstallTimeBudgetSpentBeforeTheLookupIsS10(): void
    {
        $analyzer = $this->analyzer($this->loader(['vendor/pkg' => self::meta('vendor/pkg', 'https://github.com/vendor/pkg.git')]), $this->http([]), ForgeAuth::withTokens(new Tokens('t', null)), new Allowlist([]))
            ->withDeadline(Deadline::inSeconds(0.0, static fn (): float => 0.0));
        $finding = JsonPath::decoded($analyzer->analyze(LockFile::fromArray(['packages' => [self::packagist('vendor/pkg')]]), ProjectConfig::empty(), false)->findings()[0]->toArray());

        self::assertSame([['check' => 'repository_activity', 'reason' => 'install_time_budget', 'blocks' => ['S3', 'S4']]], $finding['checks_missing']);
    }

    public function testAVcsAndAnUnknownOriginRaiseBothOriginEntriesInSignalOrder(): void
    {
        $lock = [self::vcs('acme/vcs'), self::unknownOrigin('acme/unknown')];
        $project = ProjectConfig::fromArray(['repositories' => [['type' => 'vcs', 'url' => 'https://github.com/acme/vcs.git']]]);

        $byName = $this->projectFindings($lock, $project);

        self::assertSame([PackageOrigin::VCS, PackageOrigin::UNKNOWN], [JsonPath::stringAt($byName['acme/vcs'], ['origin', 'kind']), JsonPath::stringAt($byName['acme/unknown'], ['origin', 'kind'])]);
        foreach (['acme/vcs', 'acme/unknown'] as $name) {
            self::assertSame(self::ORIGIN_S10, $byName[$name]['checks_missing'], $name);
            self::assertSame([], self::checks($byName[$name], 'repository_activity', ['checks_skipped']), $name);
            self::assertSame([], self::checks($byName[$name], 'release_metadata', ['checks_skipped']), $name);
            self::assertSame(['not_read', 'not_from_composer_repository'], [JsonPath::arrayAt($byName[$name], ['activity'])['status'], JsonPath::arrayAt($byName[$name], ['activity'])['reason']], $name);
        }
        self::assertSame(['github', 'github.com', 'acme/vcs'], [JsonPath::arrayAt($byName['acme/vcs'], ['activity'])['forge'], JsonPath::arrayAt($byName['acme/vcs'], ['activity'])['host'], JsonPath::arrayAt($byName['acme/vcs'], ['activity'])['repo']], 'placed, though not asked');
    }

    public function testThePathArtifactAndPackageOriginsKeepBothSkips(): void
    {
        $lock = [
            ['name' => 'acme/local', 'version' => 'dev-main', 'dist' => ['type' => 'path', 'url' => 'packages/local', 'reference' => 'd4e5f6']],
            ['name' => 'acme/artifact', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => '/srv/artifacts/acme-artifact-1.0.0.zip']],
            ['name' => 'acme/inline', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://downloads.acme.test/inline-1.0.0.zip']],
        ];
        $project = ProjectConfig::fromArray(['repositories' => [
            ['type' => 'path', 'url' => 'packages/*'],
            ['type' => 'artifact', 'url' => '/srv/artifacts'],
            ['type' => 'package', 'package' => ['name' => 'acme/inline', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://downloads.acme.test/inline-1.0.0.zip']]],
        ]]);

        $byName = $this->projectFindings($lock, $project);

        foreach (['acme/local' => PackageOrigin::PATH, 'acme/artifact' => PackageOrigin::ARTIFACT, 'acme/inline' => PackageOrigin::PACKAGE] as $name => $kind) {
            self::assertSame($kind, JsonPath::stringAt($byName[$name], ['origin', 'kind']), $name);
            self::assertSame([], $byName[$name]['checks_missing'], $name);
            self::assertSame(self::ACTIVITY_SKIPS, self::origin($byName[$name]), $name);
        }
    }

    public function testARaisedS1AndAWholePackageEntryTurnTheOriginEntriesIntoSkips(): void
    {
        $lock = [self::vcs('acme/vcs') + ['abandoned' => true], self::unknownOrigin('acme/unknown')];
        $project = ProjectConfig::fromArray(['repositories' => [['type' => 'vcs', 'url' => 'https://github.com/acme/vcs.git']]]);
        $allowlist = new Allowlist([new AllowlistEntry('acme/unknown', null, 'complete', null, 'builtin')]);

        $byName = $this->projectFindings($lock, $project, $allowlist);

        self::assertSame([], $byName['acme/vcs']['checks_missing'], 'S1 raised');
        self::assertSame(self::ACTIVITY_SKIPS, self::origin($byName['acme/vcs']));
        self::assertSame([], $byName['acme/unknown']['checks_missing'], 'accepted whole');
        self::assertSame('allowlisted', JsonPath::arrayAt($byName['acme/unknown'], ['activity'])['reason']);
        self::assertSame(
            [['check' => 'repository_activity', 'reason' => 'allowlisted', 'blocks' => ['S3', 'S4']], ['check' => 'release_metadata', 'reason' => 'not_from_composer_repository', 'blocks' => ['S2', 'S8']]],
            self::origin($byName['acme/unknown'])
        );
    }

    /**
     * @dataProvider manifestsWithoutRepositories
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('manifestsWithoutRepositories')]
    public function testARunThatReadsNoManifestRepositoriesPlacesNoVcsOrArtifactEntry(?ProjectConfig $project): void
    {
        $lock = [
            self::vcs('acme/vcs'),
            ['name' => 'acme/artifact', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => '/srv/artifacts/acme-artifact-1.0.0.zip']],
            ['name' => 'acme/local', 'version' => 'dev-main', 'dist' => ['type' => 'path', 'url' => 'packages/local', 'reference' => 'd4e5f6']],
        ];

        $byName = $this->projectFindings($lock, $project ?? ProjectConfig::empty());

        foreach (['acme/vcs', 'acme/artifact'] as $name) {
            self::assertSame(PackageOrigin::UNKNOWN, JsonPath::stringAt($byName[$name], ['origin', 'kind']), $name);
            self::assertSame(self::ORIGIN_S10, $byName[$name]['checks_missing'], $name);
        }
        self::assertSame(PackageOrigin::PATH, JsonPath::stringAt($byName['acme/local'], ['origin', 'kind']));
        self::assertSame([], $byName['acme/local']['checks_missing']);
        self::assertSame(self::ACTIVITY_SKIPS, self::origin($byName['acme/local']));
    }

    /** @return iterable<string, array{?ProjectConfig}> */
    public static function manifestsWithoutRepositories(): iterable
    {
        yield 'a lock-only run' => [null];
        yield 'a composer.json without repositories' => [ProjectConfig::fromArray(['require' => ['acme/vcs' => '*']])];
    }

    public function testAVcsPackageWithACountedAdvisoryGetsNoReleasesEntry(): void
    {
        $advisory = new Advisory('PKSA-1', 'CVE-2026-0001', 'A flaw', null, 'high', null);
        $lock = [self::vcs('acme/vcs')];
        $project = ProjectConfig::fromArray(['repositories' => [['type' => 'vcs', 'url' => 'https://github.com/acme/vcs.git']]]);

        $finding = $this->projectFindings($lock, $project, null, new AdvisoryBatch(['acme/vcs' => [$advisory]]))['acme/vcs'];

        self::assertSame(self::ORIGIN_S10, $finding['checks_missing'], 'the release_metadata gap already fails --fail-on=unchecked');
    }

    /**
     * The model's document of each case records the origin, the activity and the two origin
     * checks. A case without an `inputs` block cannot hydrate, so the test rebuilds its lock entry.
     *
     * @dataProvider originCases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('originCases')]
    public function testAnOriginCaseOfTheModelGetsTheModelsActivityAndOriginChecks(string $id): void
    {
        $recorded = JsonPath::arrayAt(CaseHydrator::case($id), ['finding']);
        $kind = JsonPath::stringAt($recorded, ['origin', 'kind']);
        $name = JsonPath::stringAt($recorded, ['package']);
        $url = 'https://github.com/'.JsonPath::stringAt($recorded, ['activity', 'repo']).'.git';
        $entry = ['name' => $name, 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => $url, 'reference' => 'a1b2c3d']];
        $project = ProjectConfig::fromArray($kind === PackageOrigin::VCS ? ['repositories' => [['type' => 'vcs', 'url' => $url]]] : []);

        $finding = $this->projectFindings([$entry], $project)[$name];

        self::assertSame($kind, JsonPath::stringAt($finding, ['origin', 'kind']));
        self::assertSame($recorded['activity'], $finding['activity']);
        self::assertSame($recorded['checks_missing'], $finding['checks_missing']);
        self::assertSame(self::origin($recorded), self::origin($finding));
        self::assertSame(self::s10($recorded), self::s10($finding));
    }

    /**
     * @param array<mixed, mixed> $finding
     *
     * @return mixed the S10 data of the finding, or null
     */
    private static function s10(array $finding)
    {
        foreach (JsonPath::arrayAt($finding, ['signals']) as $signal) {
            if (\is_array($signal) && $signal['id'] === 'S10') {
                return $signal['data'];
            }
        }

        return null;
    }

    /** @return iterable<string, array{string}> */
    public static function originCases(): iterable
    {
        foreach (['pinned-unread', 'scope-registries‡', 'unknown-origin', 'B-accepted-unread‡'] as $id) {
            yield $id => [$id];
        }
    }

    /** @return array<string, mixed> */
    private static function packagist(string $name): array
    {
        return ['name' => $name, 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'];
    }

    /** @return array<string, mixed> */
    private static function vcs(string $name): array
    {
        return ['name' => $name, 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/'.$name.'.git', 'reference' => 'a1b2c3d']];
    }

    /** @return array<string, mixed> */
    private static function unknownOrigin(string $name): array
    {
        return ['name' => $name, 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://satis.example.test/'.$name.'.zip']];
    }

    private static function meta(string $name, ?string $sourceUrl, string $releasedAt = '2026-08-01T00:00:00+00:00'): PackageMetadata
    {
        return new PackageMetadata($name, false, null, true, new \DateTimeImmutable($releasedAt), '1.0.0', 1, $sourceUrl, 'library', new \DateTimeImmutable(F::NOW));
    }

    /**
     * @param array<mixed, mixed> $finding
     * @param list<string>         $lists   `checks_skipped`, `checks_missing` or both
     *
     * @return list<mixed> the entries of those lists with that check
     */
    private static function checks(array $finding, string $check, array $lists = ['checks_skipped', 'checks_missing']): array
    {
        $entries = array_merge(...array_map(static fn (string $list): array => JsonPath::arrayAt($finding, [$list]), $lists));

        return array_values(array_filter($entries, static fn ($entry): bool => \is_array($entry) && $entry['check'] === $check));
    }

    /**
     * @param array<mixed, mixed> $finding
     *
     * @return list<mixed> the skips of the two checks that the origin decides
     */
    private static function origin(array $finding): array
    {
        return array_values(array_filter(JsonPath::arrayAt($finding, ['checks_skipped']), static fn ($entry): bool => \is_array($entry) && \in_array($entry['check'], ['repository_activity', 'release_metadata'], true)));
    }

    /**
     * @param list<array<string, mixed>>              $packages
     * @param array<string, PackageMetadata>          $metadata
     * @param array<string, array{int, string}>       $answers
     *
     * @return array<mixed, mixed>
     */
    private function one(array $packages, array $metadata, array $answers, bool $token, ?Allowlist $allowlist = null, ?ForgeAuth $auth = null): array
    {
        return JsonPath::decoded($this->report($packages, $metadata, $answers, $token, $allowlist, $auth)->findings()[0]->toArray());
    }

    /**
     * @param list<array<string, mixed>>        $packages
     * @param array<string, PackageMetadata>    $metadata
     * @param array<string, array{int, string}> $answers
     *
     * @return array<string, array<mixed, mixed>>
     */
    private function findings(array $packages, array $metadata, array $answers, bool $token, ?Allowlist $allowlist = null, ?ForgeAuth $auth = null, int $budget = ActivityFetchPlanner::DEFAULT_ANONYMOUS_BUDGET): array
    {
        return self::byName($this->report($packages, $metadata, $answers, $token, $allowlist, $auth, $budget));
    }

    /**
     * @param list<array<string, mixed>>        $packages
     * @param array<string, PackageMetadata>    $metadata
     * @param array<string, array{int, string}> $answers
     */
    private function report(array $packages, array $metadata, array $answers, bool $token, ?Allowlist $allowlist = null, ?ForgeAuth $auth = null, int $budget = ActivityFetchPlanner::DEFAULT_ANONYMOUS_BUDGET): Report
    {
        $auth ??= $token ? ForgeAuth::withTokens(new Tokens('t', null)) : ForgeAuth::anonymous();

        return $this->analyzer($this->loader($metadata), $this->http($answers), $auth, $allowlist ?? new Allowlist([]), false, $budget)
            ->analyze(LockFile::fromArray(['packages' => $packages]), ProjectConfig::empty(), false);
    }

    /**
     * @param list<array<string, mixed>> $packages
     *
     * @return array<string, array<mixed, mixed>>
     */
    private function projectFindings(array $packages, ProjectConfig $project, ?Allowlist $allowlist = null, ?AdvisoryBatch $advisories = null): array
    {
        return self::byName($this->analyzer($this->loader([]), $this->http([]), ForgeAuth::withTokens(new Tokens('t', null)), $allowlist ?? new Allowlist([]), false, ActivityFetchPlanner::DEFAULT_ANONYMOUS_BUDGET, $advisories)
            ->analyze(LockFile::fromArray(['packages' => $packages]), $project, false));
    }

    /** @return array<string, array<mixed, mixed>> */
    private static function byName(Report $report): array
    {
        $byName = [];
        foreach ($report->findings() as $finding) {
            $byName[$finding->package()] = JsonPath::decoded($finding->toArray());
        }

        return $byName;
    }

    /** @param array<string, PackageMetadata> $metadata */
    private function loader(array $metadata): MetadataLoaderInterface
    {
        $batch = new MetadataBatch($metadata, [], []);

        return new class ($batch) implements MetadataLoaderInterface {
            private MetadataBatch $batch;

            public function __construct(MetadataBatch $batch)
            {
                $this->batch = $batch;
            }

            public function load(array $installedByName): MetadataBatch
            {
                return $this->batch;
            }
        };
    }

    /** @param array<string, array{int, string}> $map url => [status, body]; any other URL answers 500 */
    private function http(array $map): HttpClientInterface
    {
        $this->asked = [];
        $ask = function (string $url): void {
            $this->asked[] = $url;
        };

        return new class ($map, $ask) implements HttpClientInterface {
            /** @var array<string, array{int, string}> */
            private array $map;
            /** @var callable(string): void */
            private $ask;

            /**
             * @param array<string, array{int, string}> $map
             * @param callable(string): void            $ask
             */
            public function __construct(array $map, callable $ask)
            {
                $this->map = $map;
                $this->ask = $ask;
            }

            public function fetchAll(array $urls, array $headers = []): array
            {
                $out = [];
                foreach ($urls as $url) {
                    ($this->ask)($url);
                    [$status, $body] = $this->map[$url] ?? [500, ''];
                    $out[$url] = new HttpResult($url, $status, $body, new \DateTimeImmutable('2026-09-14T06:00:00+00:00'));
                }

                return $out;
            }
        };
    }

    private function analyzer(MetadataLoaderInterface $metadata, HttpClientInterface $http, ForgeAuth $auth, Allowlist $allowlist, bool $offline = false, int $budget = ActivityFetchPlanner::DEFAULT_ANONYMOUS_BUDGET, ?AdvisoryBatch $advisories = null, ?RepoLocator $locator = null): Analyzer
    {
        $clock = Clock::fixed(F::NOW);

        return new Analyzer(
            $metadata,
            new ActivityClient($http, $auth),
            new ActivityFetchPlanner($auth, $budget),
            $locator ?? new RepoLocator(),
            $allowlist,
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            $offline,
            $advisories === null ? null : self::advisories($advisories)
        );
    }

    private static function advisories(AdvisoryBatch $batch): AdvisoryLoaderInterface
    {
        return new class ($batch) implements AdvisoryLoaderInterface {
            private AdvisoryBatch $batch;

            public function __construct(AdvisoryBatch $batch)
            {
                $this->batch = $batch;
            }

            public function load(array $versionByName, array $notFromComposerRepository = [], array $aliasVersionsByName = []): AdvisoryBatch
            {
                return $this->batch;
            }
        };
    }
}
