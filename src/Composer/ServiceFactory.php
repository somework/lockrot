<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Cache;
use Composer\Config;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Util\Bitbucket;
use Composer\Util\HttpDownloader;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Config\LockrotConfig;
use Lockrot\Data\Cache\ArrayCache;
use Lockrot\Data\Cache\CacheInterface;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\CachingHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Deadline;
use Lockrot\Signal\SignalSet;
use Lockrot\Verdict\VerdictEngine;

/** Builds the analyzer and its HTTP stack from Composer's own IO, Config and repositories. */
final class ServiceFactory
{
    /**
     * @param list<RepositoryInterface> $repositories the project's configured Composer repositories, in lookup order
     * @param ?Deadline                 $deadline     install-time budget; null (and `composer lockrot`) means unlimited
     */
    public static function createAnalyzer(IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, Tokens $tokens, Clock $clock, ?Deadline $deadline = null): Analyzer
    {
        $deadline ??= Deadline::never();
        $http = self::createHttp($io, $config, $lockrot, $clock, $deadline);
        $auth = new ForgeAuth(
            $tokens,
            static fn (string $host): bool => $io->hasAuthentication($host),
            $lockrot->offline() ? null : self::bitbucketAuthorizer($io, $config, Factory::createHttpDownloader($io, $config))
        );

        $analyzer = new Analyzer(
            new RepositoryMetadataLoader($repositories, $clock, $lockrot->offline(), $deadline),
            new ActivityClient($http, $auth),
            new ActivityFetchPlanner($auth),
            RepoLocator::fromConfig($config),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, $lockrot->thresholds(), $lockrot->targetPhp(), PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            $lockrot->offline()
        );

        return $analyzer->withDeadline($deadline);
    }

    /**
     * Composer's credentials for bitbucket.org, made sendable. `http-basic` (an Atlassian API token)
     * and `bearer` entries need nothing: Composer's AuthHelper puts them on the wire. A
     * `bitbucket-oauth` consumer is different — Composer loads it into the IO as a plain
     * username/password pair and only exchanges it for a bearer token when a request comes back
     * 401, a retry lockrot switches off ({@see ComposerHttpClient::fetchAll()}). So the exchange
     * Composer would do on that challenge is done here, up front, with Composer's own
     * {@see Bitbucket::requestToken()}, which stores the resulting token in the IO for AuthHelper
     * to send. Run at most once per run and only when a Bitbucket repository is actually planned
     * ({@see ForgeAuth}); an exchange that fails leaves the run anonymous on Bitbucket.
     *
     * @return callable(): bool
     */
    public static function bitbucketAuthorizer(IOInterface $io, Config $config, HttpDownloader $downloader): callable
    {
        return static function () use ($io, $config, $downloader): bool {
            if (!$io->hasAuthentication('bitbucket.org')) {
                return false;
            }
            $auth = $io->getAuthentication('bitbucket.org');
            $consumers = $config->get('bitbucket-oauth');
            $consumer = \is_array($consumers) ? ($consumers['bitbucket.org'] ?? null) : null;
            $consumerKey = \is_array($consumer) ? ($consumer['consumer-key'] ?? null) : null;
            if ($auth['username'] === 'x-token-auth' || $auth['username'] !== $consumerKey) {
                // Already a bearer token, or http-basic/bearer credentials AuthHelper sends as they are.
                return true;
            }
            try {
                (new Bitbucket($io, $config, null, $downloader))->requestToken('bitbucket.org', (string) $auth['username'], (string) $auth['password']);

                return true;
            } catch (\Throwable $e) {
                $io->writeError('<warning>lockrot: Bitbucket OAuth token request failed: '.$e->getMessage().'</warning>', true, IOInterface::VERBOSE);

                return false;
            }
        };
    }

    /**
     * Repository activity only: repository metadata goes through Composer's own repository layer
     * (RepositoryMetadataLoader), which has its own cache via Composer's HttpDownloader.
     *
     * A deadline shortens the per-request timeout of the activity calls to what is left of the budget
     * at the moment the calls are issued ({@see ComposerHttpClient::timeoutSeconds()}), so a single
     * slow response cannot outlast it. The repository half cannot be bounded the same way: those
     * requests are issued by the project's own ComposerRepository instances through the
     * HttpDownloader Composer built for them, whose timeouts lockrot does not get to set — there,
     * the between-chunk deadline check in RepositoryMetadataLoader is the only bound.
     */
    public static function createHttp(IOInterface $io, Config $config, LockrotConfig $lockrot, Clock $clock, ?Deadline $deadline = null): CachingHttpClient
    {
        $downloader = Factory::createHttpDownloader($io, $config);

        return new CachingHttpClient(new ComposerHttpClient($downloader, $io, $config, $clock, $deadline), self::createCache($io, $config), ActivityClient::CACHE_TTL, $clock, $lockrot->offline());
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
