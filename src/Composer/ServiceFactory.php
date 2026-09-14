<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Cache;
use Composer\Config;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Repository\RepositoryInterface;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Config\LockrotConfig;
use Lockrot\Data\Cache\ArrayCache;
use Lockrot\Data\Cache\CacheInterface;
use Lockrot\Data\GitHub\GitHubClient;
use Lockrot\Data\GitHub\GitHubFetchPlanner;
use Lockrot\Data\Http\CachingHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Signal\SignalSet;
use Lockrot\Verdict\VerdictEngine;

final class ServiceFactory
{
    /** @param list<RepositoryInterface> $repositories the project's configured Composer repositories, in lookup order */
    public static function createAnalyzer(IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, ?string $githubToken, Clock $clock): Analyzer
    {
        $http = self::createHttp($io, $config, $lockrot, $clock);

        return new Analyzer(
            new RepositoryMetadataLoader($repositories, $clock, $lockrot->offline()),
            new GitHubClient($http, $githubToken),
            new GitHubFetchPlanner($githubToken !== null),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, $lockrot->thresholds(), $lockrot->targetPhp(), PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            $lockrot->offline()
        );
    }

    /** GitHub activity only: repository metadata now goes through Composer's own repository layer (RepositoryMetadataLoader), which has its own cache via Composer's HttpDownloader. */
    public static function createHttp(IOInterface $io, Config $config, LockrotConfig $lockrot, Clock $clock): CachingHttpClient
    {
        $downloader = Factory::createHttpDownloader($io, $config);

        return new CachingHttpClient(new ComposerHttpClient($downloader, $clock), self::createCache($io, $config), GitHubClient::CACHE_TTL, $clock, $lockrot->offline());
    }

    /**
     * Cache backing for one run: Composer's own cache directory when it is enabled, otherwise a
     * request-scoped in-memory cache. `composer --no-cache` sets COMPOSER_CACHE_DIR=/dev/null, which
     * makes Composer\Cache::isEnabled() false; writing to sys_get_temp_dir() instead would quietly
     * re-enable on-disk caching the user just asked to turn off.
     */
    public static function createCache(IOInterface $io, Config $config): CacheInterface
    {
        $configuredCacheDir = $config->get('cache-dir');
        $cacheDir = rtrim(\is_string($configuredCacheDir) ? $configuredCacheDir : sys_get_temp_dir(), '/').'/lockrot/';
        $composerCache = new Cache($io, $cacheDir);

        return $composerCache->isEnabled() ? new ComposerCacheAdapter($composerCache) : new ArrayCache();
    }

    public static function githubTokenFromComposer(Config $config): ?string
    {
        $oauth = $config->get('github-oauth');
        $token = \is_array($oauth) ? ($oauth['github.com'] ?? null) : null;

        return \is_string($token) && $token !== '' ? $token : null;
    }
}
