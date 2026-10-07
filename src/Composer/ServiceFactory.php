<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Cache;
use Composer\Config;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Util\Bitbucket;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Config\LockrotConfig;
use Lockrot\Data\Advisory\AdvisoryIgnore;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
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
use Lockrot\Output\TerminalText;
use Lockrot\Signal\SignalSet;
use Lockrot\Verdict\VerdictEngine;

/** @internal */
final class ServiceFactory
{
    /**
     * @param list<RepositoryInterface> $repositories the project's configured Composer repositories, in lookup order
     * @param ?Deadline                 $deadline     install-time budget, null for no limit
     * @param ?string                   $projectPhp   the project's `require.php`, or null when the manifest has none, see {@see \Lockrot\Signal\PhpFloor}
     */
    public static function createAnalyzer(IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, Tokens $tokens, Clock $clock, ?Deadline $deadline = null, ?string $projectPhp = null): Analyzer
    {
        $deadline ??= Deadline::never();
        $http = self::createHttp($io, $config, $lockrot, $clock, $deadline);
        $auth = new ForgeAuth(
            $tokens,
            static fn (string $host): bool => $io->hasAuthentication($host),
            $lockrot->offline() ? null : self::bitbucketAuthorizer($io, $config, $deadline)
        );

        $analyzer = new Analyzer(
            new RepositoryMetadataLoader($repositories, $clock, $lockrot->offline(), $deadline),
            new ActivityClient($http, $auth),
            new ActivityFetchPlanner($auth),
            RepoLocator::fromConfig($config),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, $lockrot->thresholds(), $lockrot->targetPhp(), PhpReleaseDates::load(), $projectPhp),
            new VerdictEngine(),
            $clock,
            $lockrot->offline(),
            new RepositoryAdvisoryLoader($repositories, $lockrot->offline(), $deadline, AdvisoryIgnore::fromConfig($config))
        );

        return $analyzer->withDeadline($deadline);
    }

    /**
     * Exchanges a `bitbucket-oauth` consumer for a bearer token up front. Composer exchanges it only
     * after a 401, and lockrot switches that retry off ({@see ComposerHttpClient::fetchAll()}). The
     * request is the one that {@see Bitbucket} sends. Composer's own helper is not used, because it
     * also rewrites `composer.json` and `auth.json`. AuthHelper adds the consumer pair to the POST as
     * HTTP Basic, and sends a token stored as `x-token-auth` as a bearer token on api.bitbucket.org.
     * A failed exchange leaves the pair in the IO: IOInterface cannot clear one entry.
     *
     * @param null|callable(): HttpDownloader $downloaderFactory the downloader to post with, Composer's own when null
     *
     * @return callable(): bool
     */
    public static function bitbucketAuthorizer(IOInterface $io, Config $config, Deadline $deadline, ?callable $downloaderFactory = null): callable
    {
        return static function () use ($io, $config, $deadline, $downloaderFactory): bool {
            if ($io->hasAuthentication('api.bitbucket.org')) {
                return true;
            }
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
                $downloader = $downloaderFactory !== null ? $downloaderFactory() : Factory::createHttpDownloader($io, $config);
                $response = $downloader->get(Bitbucket::OAUTH2_ACCESS_TOKEN_URL, [
                    'retry-auth-failure' => false,
                    'http' => ['method' => 'POST', 'content' => 'grant_type=client_credentials', 'timeout' => ComposerHttpClient::timeoutFor($deadline)],
                ]);
                $decoded = $response instanceof Response ? $response->decodeJson() : null;
                $token = \is_array($decoded) ? ($decoded['access_token'] ?? null) : null;
                if (!\is_string($token) || $token === '') {
                    throw new \RuntimeException('no access_token in the answer');
                }
                $io->setAuthentication('bitbucket.org', 'x-token-auth', $token);

                return true;
            } catch (\Throwable $e) {
                // Raw, past Composer's formatter: the transport's message is not console markup
                // (see TerminalText), and what a terminal obeys in it is shown instead.
                $line = 'lockrot: Bitbucket OAuth token request failed, continuing without credentials: '.TerminalText::neutralise($e->getMessage());
                $io->writeErrorRaw(TerminalText::warning($line, $io->isDecorated()), true, IOInterface::VERBOSE);

                return false;
            }
        };
    }

    /**
     * HTTP for repository activity only. The deadline cuts each activity request to the time left
     * ({@see ComposerHttpClient::timeoutSeconds()}). Repository metadata requests come from the
     * project's own ComposerRepository instances, whose HttpDownloader timeouts lockrot cannot set.
     * There, the deadline check of {@see \Lockrot\Data\Repository\RepositoryMetadataLoader} stops
     * the later chunks, not a request in flight.
     */
    public static function createHttp(IOInterface $io, Config $config, LockrotConfig $lockrot, Clock $clock, ?Deadline $deadline = null): CachingHttpClient
    {
        $downloader = Factory::createHttpDownloader($io, $config);

        return new CachingHttpClient(new ComposerHttpClient($downloader, $io, $config, $clock, $deadline), self::createCache($io, $config), ActivityClient::CACHE_TTL, $clock, $lockrot->offline());
    }

    /**
     * `composer --no-cache` sets COMPOSER_CACHE_DIR=/dev/null, which makes Composer\Cache::isEnabled()
     * false. The fallback is a memory cache, not one in sys_get_temp_dir(): the user asked for no
     * cache on disk.
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
