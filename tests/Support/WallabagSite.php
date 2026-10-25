<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Repository\RepositoryFactory;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Report;
use Lockrot\Clock;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Solver\MoveCheckers;
use Lockrot\Verdict\MoveChecks;

/**
 * The lock of the external audit: wallabag 2.6.14 at `config.platform.php` 7.4.29, with its own
 * recorded answers (tests/fixtures/sites/wallabag-2.6.14, provenance.json), analysed with the move
 * check and no repository activity.
 */
final class WallabagSite
{
    public const DIR = __DIR__.'/../fixtures/sites/wallabag-2.6.14/';
    private const NOW = '2026-10-18T00:00:00+00:00';

    public static function analyze(string $targetPhp): Report
    {
        $server = FixtureRepositoryServer::fromLockFiles([self::DIR.'composer.lock'], self::DIR.'http/p2');
        $server->withRecordedAdvisoryApi(self::DIR, self::DIR.'http/advisories');
        $server->start();
        try {
            return self::analyzeOn($server, $targetPhp);
        } finally {
            $server->stop();
        }
    }

    private static function analyzeOn(FixtureRepositoryServer $server, string $targetPhp): Report
    {
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens(null, null));
        $json = JsonPath::decodeFile(self::DIR.'composer.json');
        $config = $server->config();
        $config->merge(['config' => ['platform' => JsonPath::arrayAt($json, ['config', 'platform'])]]);
        $repositories = $server->repositories();
        $io = new NullIO();
        /** @var array<string, mixed> $json */
        $root = (new RootPackageLoader(RepositoryFactory::manager($io, $config, Factory::createHttpDownloader($io, $config)), $config, null, null, $io))->load($json + ['version' => '1.0.0']);
        $lock = LockFile::fromFile(self::DIR.'composer.lock');
        $project = ProjectConfig::fromFile(self::DIR.'composer.json');
        $analyzer = (new Analyzer(
            new RepositoryMetadataLoader($repositories, $clock),
            new ActivityClient(new FakeHttpClient(), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), $targetPhp, PhpReleaseDates::load(), $project->requirePhp()),
            $clock,
            false,
            new RepositoryAdvisoryLoader($repositories)
        ))->withMoveChecks(MoveChecks::of(MoveCheckers::forProject($root, $lock, $project, $config, $repositories, $targetPhp, false, Deadline::never())));

        return $analyzer->analyze($lock, $project, false);
    }
}
