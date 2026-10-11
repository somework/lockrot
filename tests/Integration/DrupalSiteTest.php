<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunNote;
use Lockrot\Clock;
use Lockrot\Config\Gate;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\TestCase;

/**
 * One recorded Drupal site lock (tests/fixtures/apps/drupal-site, provenance.json names its commit):
 * its git.drupalcode.org packages without and with Composer's `gitlab-domains` recipe.
 */
final class DrupalSiteTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/';
    private const SITE = self::FIXTURES.'apps/drupal-site/';
    private const DRUPALCODE = 'git.drupalcode.org';
    private const NOW = '2026-10-12T00:00:00+00:00';

    private static ?FixtureRepositoryServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = FixtureRepositoryServer::fromLockFiles([self::SITE.'composer.lock'], self::FIXTURES.'http/p2', [], [
            'https://packages.drupal.org/files/packages/8/p2/' => self::SITE.'http/p2',
        ]);
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            self::$server->stop();
            self::$server = null;
        }
    }

    public function testWithoutTheRecipeEveryDrupalcodePackageIsAHostLockrotCannotAsk(): void
    {
        $report = $this->analyze(['gitlab.com']);
        $findings = self::findingsOn($report);

        self::assertNotSame([], $findings);
        foreach ($findings as $name => $finding) {
            self::assertSame(['not_read', 'host_not_supported', self::DRUPALCODE, null], [$finding['activity']['status'], $finding['activity']['reason'], $finding['activity']['host'], $finding['activity']['forge']], $name);
            self::assertContains(['check' => 'repository_activity', 'reason' => 'host_not_supported', 'blocks' => ['S3', 'S4']], $finding['checks_missing'], $name);
        }
        $notes = array_values(array_filter($report->runNotes(), static fn (RunNote $note): bool => $note->code() === RunNote::REPOSITORY_ACTIVITY_HOST_NOT_SUPPORTED));
        self::assertCount(1, $notes);
        self::assertSame([['host' => self::DRUPALCODE, 'packages' => \count($findings), 'listed_in' => null]], JsonPath::decoded($notes[0]->toArray())['data']['hosts']);
        self::assertTrue(Gate::decide($report, FailOn::fromString('unchecked'), false, Gate::MODE_CHECK)->fails(), '--fail-on=unchecked exits 1');
    }

    public function testWithTheRecipeEveryDrupalcodePackageIsReadAnonymouslyWithoutItsArchivedFlag(): void
    {
        $report = $this->analyze(['gitlab.com', self::DRUPALCODE]);
        $findings = self::findingsOn($report);

        self::assertNotSame([], $findings);
        foreach ($findings as $name => $finding) {
            self::assertSame(['read', 'gitlab', 'commit', null], [$finding['activity']['status'], $finding['activity']['forge'], $finding['activity']['event'], $finding['activity']['archived']], $name);
            self::assertContains(['check' => 'repository_archived', 'reason' => 'no_token', 'blocks' => ['S3']], $finding['checks_skipped'], $name);
        }
        foreach ($report->findings() as $finding) {
            self::assertSame([], array_values(array_filter(JsonPath::decoded($finding->toArray())['checks_missing'], static fn (array $entry): bool => $entry['check'] === 'repository_activity')), $finding->package());
        }
        self::assertSame([], array_filter($report->runNotes(), static fn (RunNote $note): bool => $note->code() === RunNote::REPOSITORY_ACTIVITY_HOST_NOT_SUPPORTED));
    }

    /** @param list<string> $gitlabDomains */
    private function analyze(array $gitlabDomains): Report
    {
        $server = self::$server;
        self::assertNotNull($server);
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $analyzer = new Analyzer(
            new RepositoryMetadataLoader($server->repositories(), $clock),
            new ActivityClient(self::recordings(), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator($gitlabDomains),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            false
        );

        return $analyzer->analyze(LockFile::fromFile(self::SITE.'composer.lock'), ProjectConfig::fromFile(self::SITE.'composer.json'), false);
    }

    /** The recorded GitHub answers, shared with the other fixtures, and the site's own git.drupalcode.org answers. */
    private static function recordings(): HttpClientInterface
    {
        return new class (new RecordedHttpClient(self::FIXTURES.'http/github'), new RecordedHttpClient(self::SITE.'http/gitlab'), 'https://'.self::DRUPALCODE.'/') implements HttpClientInterface {
            private HttpClientInterface $github;
            private HttpClientInterface $gitlab;
            private string $gitlabBase;

            public function __construct(HttpClientInterface $github, HttpClientInterface $gitlab, string $gitlabBase)
            {
                $this->github = $github;
                $this->gitlab = $gitlab;
                $this->gitlabBase = $gitlabBase;
            }

            public function fetchAll(array $urls, array $headers = []): array
            {
                $out = [];
                foreach ($urls as $url) {
                    $client = strpos($url, $this->gitlabBase) === 0 ? $this->gitlab : $this->github;
                    $out += $client->fetchAll([$url], $headers);
                }

                return $out;
            }
        };
    }

    /**
     * The findings of the lock entries whose source is on git.drupalcode.org, by the lock itself.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function findingsOn(Report $report): array
    {
        $lock = JsonPath::decodeFile(self::SITE.'composer.lock');
        $names = [];
        foreach (JsonPath::arrayAt($lock, ['packages']) as $entry) {
            if (\is_array($entry) && strpos((string) ($entry['source']['url'] ?? ''), 'https://'.self::DRUPALCODE.'/') === 0) {
                $names[] = $entry['name'];
            }
        }
        $findings = [];
        foreach ($report->findings() as $finding) {
            if (\in_array($finding->package(), $names, true)) {
                $findings[$finding->package()] = JsonPath::decoded($finding->toArray());
            }
        }
        self::assertCount(\count($names), $findings, 'one finding per git.drupalcode.org entry');

        return $findings;
    }
}
