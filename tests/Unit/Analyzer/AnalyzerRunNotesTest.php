<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Composer\Downloader\TransportException;
use Lockrot\Allowlist\Allowlist;
use Lockrot\Analyzer\Analysis;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\RunNote;
use Lockrot\Clock;
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
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\Rule\NotCheckedRule;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\TestCase;

/**
 * The typed run notes through the real Analyzer: one per condition, the network flag computed from
 * them, and the activity notes held against the reason each package's own facts give (what S10
 * reads), so a note and the findings it explains cannot tell two stories.
 */
final class AnalyzerRunNotesTest extends TestCase
{
    private const OLD = '2015-01-01T00:00:00+00:00';
    private const RECENT = '2026-08-01T00:00:00+00:00';

    public function testEveryNoteOfARunIsTypedInTheOrderItsTextIsWritten(): void
    {
        $packages = [self::locked('vendor/a'), self::locked('vendor/b'), ['name' => 'private/one', 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => 'https://git.example.com/one.git', 'reference' => 'a']]];
        $failed = ['vendor/a' => MetadataLoaderInterface::OFFLINE_NOT_FOUND_REASON, 'vendor/b' => 'the file for this package could not be read'];
        $advisories = new AdvisoryBatch([], [RunNote::advisoryIgnoreUnreadable('Unknown key'), RunNote::advisoriesNotChecked(RunNote::ADVISORIES_OFFLINE, 0)]);

        $report = $this->analyze($packages, new MetadataBatch([], [], $failed), $this->http([]), true, $advisories, false, true)->report();

        self::assertSame(['offline', 'metadata_unavailable', 'advisory_ignore_unreadable', 'advisories_not_checked', 'not_from_composer_repository'], array_map(static fn (RunNote $note): string => $note->code(), $report->runNotes()));
        self::assertSame($report->notes(), array_map(static fn (RunNote $note): string => $note->text(), $report->runNotes()));
        $document = $report->toArray();
        self::assertSame($document['notes'], array_column(JsonPath::arrayAt($document, ['note_details']), 'text'));
        $metadata = $report->runNotes()[1];
        self::assertSame(['package_count' => 2, 'reasons' => [
            ['reason' => 'offline', 'message' => MetadataLoaderInterface::OFFLINE_NOT_FOUND_REASON, 'package_count' => 1],
            ['reason' => 'fetch_failed', 'message' => 'the file for this package could not be read', 'package_count' => 1],
        ]], $metadata->data(), 'offline, a cache miss is `offline` and a repository that threw is the catch-all');
        self::assertSame(['package_count' => 1], $report->runNotes()[4]->data());
        self::assertSame(1, $report->notFromComposerRepository());
        self::assertTrue($report->hadNetworkFailures(), 'the metadata note sets it');
        self::assertSame([false, true, false, false, false], array_map(static fn (RunNote $note): bool => $note->setsNetworkFailures(), $report->runNotes()));
    }

    /** A repository that could not be reached fails `--strict-network`; one that answered with something lockrot could not read does not. */
    public function testAnAdvisoryFailureSetsTheNetworkFlagOnlyWhenTheRepositoryWasNotReached(): void
    {
        $packages = [self::locked('vendor/a')];
        $metadata = new MetadataBatch(['vendor/a' => self::metadata('vendor/a', null, self::RECENT)], [], []);
        foreach ([[new TransportException('HTTP 503'), true], [new \LogicException('not JSON'), false]] as [$error, $network]) {
            $batch = new AdvisoryBatch([], [RunNote::advisoriesUnavailable('packagist.org', $error)]);

            $report = $this->analyze($packages, $metadata, $this->http([]), false, $batch)->report();

            self::assertSame([RunNote::ADVISORIES_UNAVAILABLE], array_map(static fn (RunNote $note): string => $note->code(), $report->runNotes()));
            self::assertSame($network, $report->runNotes()[0]->setsNetworkFailures(), \get_class($error));
            self::assertSame($network, $report->hadNetworkFailures(), \get_class($error));
            self::assertSame($network, $report->toArray()['network_failures']);
        }
    }

    /**
     * Two GitLab hosts, one rate-limiting and one down, plus a repository that answered 404: the
     * rate-limit note, which is all the text says, lists both failed repositories with their own
     * host and message, and the 404 is typed in its own note. Every package on the rate-limited
     * forge carries `rate_limit`, the 404 one included, because the analyzer asks about the rate
     * limit before it asks what happened to the repository.
     */
    public function testTheActivityNotesListEachRepositoryAndAgreeWithEachPackagesReason(): void
    {
        $packages = [self::locked('vendor/limited'), self::locked('vendor/down'), self::locked('vendor/gone')];
        $metadata = new MetadataBatch([
            'vendor/limited' => self::metadata('vendor/limited', 'https://gitlab.com/group/limited.git'),
            'vendor/down' => self::metadata('vendor/down', 'https://gitlab.example.org/team/down.git'),
            'vendor/gone' => self::metadata('vendor/gone', 'https://gitlab.com/group/gone.git'),
        ], [], []);
        $http = $this->http([
            'https://gitlab.com/api/v4/projects/group%2Flimited/repository/commits?all=true&per_page=1' => [429, ''],
            'https://gitlab.example.org/api/v4/projects/team%2Fdown/repository/commits?all=true&per_page=1' => [500, ''],
        ]);

        $analysis = $this->analyze($packages, $metadata, $http, false, null, false, false, ['gitlab.com', 'gitlab.example.org']);
        $report = $analysis->report();

        self::assertSame([
            'GitLab API rate limit reached; repository activity missing for 2 repositories',
            'GitLab did not answer for 1 repositories (private, renamed or removed); repository activity missing',
        ], $report->notes());
        [$limited, $notFound] = $report->runNotes();
        self::assertSame(RunNote::REPOSITORY_ACTIVITY_RATE_LIMITED, $limited->code());
        self::assertSame(RunNote::REPOSITORY_ACTIVITY_NOT_FOUND, $notFound->code());
        self::assertSame('gitlab', $limited->data()['forge_id']);
        self::assertSame([
            ['host' => 'gitlab.com', 'repo' => 'group/limited', 'message' => 'HTTP 429'],
            ['host' => 'gitlab.example.org', 'repo' => 'team/down', 'message' => 'HTTP 500'],
        ], self::sorted($limited->data()['repositories']));
        self::assertSame(['forge_id' => 'gitlab', 'repositories' => [['host' => 'gitlab.com', 'repo' => 'group/gone']]], $notFound->data());
        self::assertTrue($limited->setsNetworkFailures());
        self::assertFalse($notFound->setsNetworkFailures());
        self::assertTrue($report->hadNetworkFailures());

        self::assertSame(
            ['vendor/down' => NotCheckedRule::RATE_LIMIT, 'vendor/gone' => NotCheckedRule::RATE_LIMIT, 'vendor/limited' => NotCheckedRule::RATE_LIMIT],
            self::activityReasons($analysis, ['vendor/limited', 'vendor/down', 'vendor/gone']),
            'rate_limit on the forge is the union of the rate-limited note and the not-found one'
        );
    }

    /** A repository down on a forge that is not rate-limiting: every failure's own message, and `fetch_failed` on exactly those packages. */
    public function testUnreachableRepositoriesKeepEveryMessageAndMatchFetchFailed(): void
    {
        $packages = [self::locked('vendor/one'), self::locked('vendor/two'), self::locked('vendor/fine')];
        $metadata = new MetadataBatch([
            'vendor/one' => self::metadata('vendor/one', 'https://github.com/owner/one.git'),
            'vendor/two' => self::metadata('vendor/two', 'https://github.com/owner/two.git'),
            'vendor/fine' => self::metadata('vendor/fine', 'https://github.com/owner/fine.git'),
        ], [], []);
        $http = $this->http([
            'https://api.github.com/repos/owner/one' => [502, ''],
            'https://api.github.com/repos/owner/two' => [500, ''],
            'https://api.github.com/repos/owner/fine' => [200, '{"archived":false,"pushed_at":"2026-08-01T00:00:00Z"}'],
        ]);

        $analysis = $this->analyze($packages, $metadata, $http, true);
        $notes = $analysis->report()->runNotes();

        self::assertCount(1, $notes);
        self::assertSame('GitHub unreachable for 2 repositories: HTTP 502', $notes[0]->text());
        self::assertSame(['forge_id' => 'github', 'repositories' => [
            ['host' => 'github.com', 'repo' => 'owner/one', 'message' => 'HTTP 502'],
            ['host' => 'github.com', 'repo' => 'owner/two', 'message' => 'HTTP 500'],
        ]], $notes[0]->data(), 'the text names the first reason; the data keeps both');
        self::assertSame(['vendor/one' => NotCheckedRule::FETCH_FAILED, 'vendor/two' => NotCheckedRule::FETCH_FAILED], self::activityReasons($analysis, ['vendor/one', 'vendor/two', 'vendor/fine']));
    }

    /** A 404 without rate limiting has no reason on its finding: the note is its only record. */
    public function testARepositoryThatAnswered404IsTypedOnlyInItsNote(): void
    {
        $packages = [self::locked('vendor/gone')];
        $metadata = new MetadataBatch(['vendor/gone' => self::metadata('vendor/gone', 'https://github.com/owner/gone.git')], [], []);

        $analysis = $this->analyze($packages, $metadata, $this->http([]), true);

        self::assertSame(['forge_id' => 'github', 'repositories' => [['host' => 'github.com', 'repo' => 'owner/gone']]], $analysis->report()->runNotes()[0]->data());
        self::assertSame([], self::activityReasons($analysis, ['vendor/gone']));
        self::assertFalse($analysis->report()->hadNetworkFailures());
    }

    /** The cap's two parts are the packages that carry `no_token` and `anonymous_budget`. */
    public function testTheAnonymousCapSplitsWhatItSkippedByTheReasonsThePackagesCarry(): void
    {
        $packages = [];
        $metadata = [];
        $answers = [];
        foreach (['vendor/one', 'vendor/two', 'vendor/three'] as $name) {
            $packages[] = self::locked($name);
            $metadata[$name] = self::metadata($name, 'https://github.com/'.$name.'.git');
            $answers['https://api.github.com/repos/'.$name] = [200, '{"archived":false,"pushed_at":"2015-01-01T00:00:00Z"}'];
        }
        $packages[] = self::locked('vendor/recent');
        $metadata['vendor/recent'] = self::metadata('vendor/recent', 'https://github.com/vendor/recent.git', self::RECENT);

        $analysis = $this->analyze($packages, new MetadataBatch($metadata, [], []), $this->http($answers), false, null, false, false, ['gitlab.com'], 1);
        $cap = $analysis->report()->runNotes()[0];

        self::assertSame('GitHub token not set: repository activity checked for 1 candidate packages, 3 packages skipped (set GITHUB_TOKEN to check all)', $cap->text());
        self::assertSame(['forge_id' => 'github', 'checked' => 1, 'skipped_no_token' => 1, 'skipped_budget' => 2], $cap->data());
        $reasons = array_count_values(self::activityReasons($analysis, ['vendor/one', 'vendor/two', 'vendor/three', 'vendor/recent']));
        ksort($reasons);
        self::assertSame([NotCheckedRule::RATE_BUDGET => 2, NotCheckedRule::NO_TOKEN => 1], $reasons);
    }

    /** Every package whose repository was never asked carries the reason the note gives, not only the first. */
    public function testAnExhaustedBudgetSaysTheActivityWasNotChecked(): void
    {
        $packages = [self::locked('vendor/a'), self::locked('vendor/b')];
        $metadata = new MetadataBatch([
            'vendor/a' => self::metadata('vendor/a', 'https://github.com/vendor/a.git'),
            'vendor/b' => self::metadata('vendor/b', 'https://github.com/vendor/b.git'),
        ], [], []);

        $analysis = $this->analyze($packages, $metadata, $this->http([]), true, null, true);
        $report = $analysis->report();

        self::assertSame([RunNote::REPOSITORY_ACTIVITY_NOT_CHECKED], array_map(static fn (RunNote $note): string => $note->code(), $report->runNotes()));
        self::assertSame(['reason' => 'install_time_budget'], $report->runNotes()[0]->data());
        self::assertFalse($report->hadNetworkFailures());
        self::assertSame(['vendor/a' => NotCheckedRule::BUDGET, 'vendor/b' => NotCheckedRule::BUDGET], self::activityReasons($analysis, ['vendor/a', 'vendor/b']));
    }

    /** Offline, every package whose activity lockrot's cache does not hold carries `offline`, as the offline note says. */
    public function testOfflineEveryUnreadRepositoryIsOffline(): void
    {
        $packages = [self::locked('vendor/a'), self::locked('vendor/b')];
        $metadata = new MetadataBatch([
            'vendor/a' => self::metadata('vendor/a', 'https://github.com/vendor/a.git'),
            'vendor/b' => self::metadata('vendor/b', 'https://github.com/vendor/b.git'),
        ], [], []);

        $analysis = $this->analyze($packages, $metadata, $this->http([]), true, null, false, true);

        self::assertSame(RunNote::OFFLINE, $analysis->report()->runNotes()[0]->code());
        self::assertSame(['vendor/a' => NotCheckedRule::OFFLINE, 'vendor/b' => NotCheckedRule::OFFLINE], self::activityReasons($analysis, ['vendor/a', 'vendor/b']));
    }

    /**
     * @param list<array<string, mixed>> $packages
     * @param list<string>               $gitlabDomains
     */
    private function analyze(array $packages, MetadataBatch $metadata, HttpClientInterface $http, bool $token, ?AdvisoryBatch $advisories = null, bool $pastDeadline = false, bool $offline = false, array $gitlabDomains = ['gitlab.com'], int $anonymousBudget = ActivityFetchPlanner::DEFAULT_ANONYMOUS_BUDGET): Analysis
    {
        $clock = Clock::fixed(F::NOW);
        $auth = ForgeAuth::withTokens(new Tokens($token ? 't' : null, null));
        $analyzer = new Analyzer(
            self::loader($metadata),
            new ActivityClient($http, $auth),
            new ActivityFetchPlanner($auth, $anonymousBudget),
            new RepoLocator($gitlabDomains),
            new Allowlist([]),
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            $offline,
            $advisories === null ? null : self::advisories($advisories)
        );
        if ($pastDeadline) {
            $analyzer = $analyzer->withDeadline(Deadline::inSeconds(0.0, static fn (): float => 0.0));
        }
        $lock = LockFile::fromArray(['packages' => $packages]);

        return $analyzer->analyzeWithFacts($lock->packages(false), $lock, ProjectConfig::empty(), false);
    }

    /**
     * Why each package's repository activity was not read, by package, for those that have a reason.
     *
     * @param list<string> $names
     *
     * @return array<string, string>
     */
    private static function activityReasons(Analysis $analysis, array $names): array
    {
        $reasons = [];
        foreach ($names as $name) {
            $facts = $analysis->facts($name);
            self::assertNotNull($facts, $name);
            if ($facts->activityNotChecked() !== null) {
                $reasons[$name] = $facts->activityNotChecked();
            }
        }
        ksort($reasons);

        return $reasons;
    }

    /**
     * @param mixed $repositories
     *
     * @return array<mixed, mixed> by host
     */
    private static function sorted($repositories): array
    {
        self::assertIsArray($repositories);
        $byHost = [];
        foreach (array_keys($repositories) as $at) {
            $byHost[JsonPath::stringAt($repositories, [$at, 'host'])] = $repositories[$at];
        }
        ksort($byHost);

        return array_values($byHost);
    }

    /** @return array<string, mixed> */
    private static function locked(string $name): array
    {
        return ['name' => $name, 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'];
    }

    private static function metadata(string $name, ?string $sourceUrl, string $releasedAt = self::OLD): PackageMetadata
    {
        return new PackageMetadata($name, false, null, true, new \DateTimeImmutable($releasedAt), '1.0.0', 1, $sourceUrl, 'library', new \DateTimeImmutable(F::NOW));
    }

    private static function loader(MetadataBatch $batch): MetadataLoaderInterface
    {
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

    private static function advisories(AdvisoryBatch $batch): AdvisoryLoaderInterface
    {
        return new class ($batch) implements AdvisoryLoaderInterface {
            private AdvisoryBatch $batch;

            public function __construct(AdvisoryBatch $batch)
            {
                $this->batch = $batch;
            }

            public function load(array $versionByName): AdvisoryBatch
            {
                return $this->batch;
            }
        };
    }

    /** @param array<string, array{int, string}> $map url => [status, body]; any other URL answers 404 */
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
}
