<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Composer;

use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Composer\ComposerCacheAdapter;
use Lockrot\Composer\ComposerHttpClient;
use Lockrot\Composer\ServiceFactory;
use Lockrot\Config\LockrotConfig;
use Lockrot\Data\Cache\ArrayCache;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\CachingHttpClient;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use PHPUnit\Framework\TestCase;

final class ServiceFactoryTest extends TestCase
{
    private const NOW = '2026-09-14T00:00:00+00:00';

    /** @var list<string> */
    private array $cacheDirs = [];

    /** Composer\Cache::isEnabled() creates the directory, so each test that enables it cleans up. */
    protected function tearDown(): void
    {
        foreach ($this->cacheDirs as $dir) {
            foreach (glob($dir.'/lockrot/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir.'/lockrot');
            @rmdir($dir);
        }
        $this->cacheDirs = [];
    }

    private function tempCacheDir(): string
    {
        $dir = sys_get_temp_dir().'/lockrot-sf-'.uniqid();
        $this->cacheDirs[] = $dir;

        return $dir;
    }

    public function testBuildsAnalyzer(): void
    {
        $config = new Config(false, sys_get_temp_dir());
        $config->merge(['config' => ['cache-dir' => $this->tempCacheDir(), 'home' => sys_get_temp_dir()]]);
        $lockrot = LockrotConfig::fromSources([], [], [], '8.4.0', null);
        $analyzer = ServiceFactory::createAnalyzer(new NullIO(), $config, [], $lockrot, Tokens::none(), Clock::fixed('2026-09-14T00:00:00+00:00'));
        self::assertInstanceOf(Analyzer::class, $analyzer);
    }

    /**
     * A Deadline driven by a scripted monotonic clock instead of hrtime(): the first reading (0.0)
     * is the one Deadline::inSeconds() uses to fix the expiry, every later reading is what
     * isPast()/remainingSeconds() then see. No real time passes in these tests.
     */
    private static function deadline(float $seconds, float $laterReadings): Deadline
    {
        $reading = 0.0;
        $now = static function () use (&$reading, $laterReadings): float {
            $current = $reading;
            $reading = $laterReadings;

            return $current;
        };

        return Deadline::inSeconds($seconds, $now);
    }

    private function configWithTempCache(): Config
    {
        $config = new Config(false, sys_get_temp_dir());
        $config->merge(['config' => ['cache-dir' => $this->tempCacheDir(), 'home' => sys_get_temp_dir()]]);

        return $config;
    }

    /** ServiceFactory::createHttp() must wrap a ComposerHttpClient; this reads back its request timeout. */
    private static function timeoutOf(CachingHttpClient $client): int
    {
        $inner = $client->inner();

        return $inner instanceof ComposerHttpClient
            ? $inner->timeoutSeconds()
            : self::fail('ServiceFactory::createHttp() must wrap a ComposerHttpClient, got '.\get_class($inner));
    }

    /**
     * The deadline has to travel all the way into the Analyzer (via withDeadline()), not only into
     * the metadata loader — an exhausted budget must stop the GitHub round too. Nothing here can
     * reach the network: the repository list is empty and the GitHub branch is the one being skipped.
     */
    public function testAnExhaustedDeadlineReachesTheAnalyzerAndSkipsTheGitHubRound(): void
    {
        $lockrot = LockrotConfig::fromSources([], [], [], '8.4.0', null);
        $analyzer = ServiceFactory::createAnalyzer(
            new NullIO(),
            $this->configWithTempCache(),
            [],
            $lockrot,
            Tokens::none(),
            Clock::fixed(self::NOW),
            self::deadline(5.0, 1000.0)
        );

        $report = $analyzer->analyze($this->singlePackageLock(), ProjectConfig::empty(), false);

        self::assertContains('repository activity not checked: install-time budget exhausted', $report->notes());
    }

    public function testWithoutADeadlineTheAnalyzerRunsUnbounded(): void
    {
        $lockrot = LockrotConfig::fromSources([], [], [], '8.4.0', null);
        $analyzer = ServiceFactory::createAnalyzer(new NullIO(), $this->configWithTempCache(), [], $lockrot, Tokens::none(), Clock::fixed(self::NOW));

        // An empty lock keeps this off the network while still running the branch the deadline guards.
        $report = $analyzer->analyze(LockFile::fromArray(['packages' => []]), ProjectConfig::empty(), false);

        self::assertNotContains('repository activity not checked: install-time budget exhausted', $report->notes());
    }

    public function testAnExhaustedDeadlineShortensTheGitHubRequestTimeoutToOneSecond(): void
    {
        $lockrot = LockrotConfig::fromSources([], [], [], '8.4.0', null);
        $http = ServiceFactory::createHttp(new NullIO(), $this->configWithTempCache(), $lockrot, Clock::fixed(self::NOW), self::deadline(5.0, 1000.0));

        self::assertSame(1, self::timeoutOf($http));
    }

    public function testAPartlySpentDeadlineRoundsTheRequestTimeoutUp(): void
    {
        // 5s budget, 2.6s already gone -> 2.4s left -> a 3s request timeout.
        $lockrot = LockrotConfig::fromSources([], [], [], '8.4.0', null);
        $http = ServiceFactory::createHttp(new NullIO(), $this->configWithTempCache(), $lockrot, Clock::fixed(self::NOW), self::deadline(5.0, 2.6));

        self::assertSame(3, self::timeoutOf($http));
    }

    /**
     * The timeout must track the budget at request time, not at construction time: the metadata pass
     * runs between createHttp() and the GitHub round, so a value frozen at construction would let the
     * GitHub round outlast the budget by a whole request timeout.
     */
    public function testTheGitHubRequestTimeoutFollowsTheDeadlineAsItDrains(): void
    {
        $readings = [0.0, 1.0, 4.5, 4.5];
        $now = static function () use (&$readings): float {
            return \count($readings) > 1 ? array_shift($readings) : $readings[0];
        };
        $lockrot = LockrotConfig::fromSources([], [], [], '8.4.0', null);
        $http = ServiceFactory::createHttp(new NullIO(), $this->configWithTempCache(), $lockrot, Clock::fixed(self::NOW), Deadline::inSeconds(5.0, $now));

        self::assertSame(4, self::timeoutOf($http), '1.0s gone -> 4.0s left');
        self::assertSame(1, self::timeoutOf($http), '4.5s gone -> 0.5s left, rounded up to the 1s floor');
    }

    public function testWithoutADeadlineTheDefaultRequestTimeoutIsUsed(): void
    {
        $lockrot = LockrotConfig::fromSources([], [], [], '8.4.0', null);
        $config = $this->configWithTempCache();
        $clock = Clock::fixed(self::NOW);

        self::assertSame(ComposerHttpClient::DEFAULT_TIMEOUT, self::timeoutOf(ServiceFactory::createHttp(new NullIO(), $config, $lockrot, $clock)));
        self::assertSame(ComposerHttpClient::DEFAULT_TIMEOUT, self::timeoutOf(ServiceFactory::createHttp(new NullIO(), $config, $lockrot, $clock, Deadline::never())));
    }

    private function singlePackageLock(): LockFile
    {
        return LockFile::fromArray(['packages' => [[
            'name' => 'vendor/pkg',
            'version' => '1.0.0',
            'notification-url' => 'https://packagist.org/downloads/',
            'source' => ['type' => 'git', 'url' => 'https://github.com/vendor/pkg.git', 'reference' => 'abc123'],
        ]]]);
    }

    public function testEnabledComposerCacheIsUsedForStorage(): void
    {
        $config = new Config(false, sys_get_temp_dir());
        $config->merge(['config' => ['cache-dir' => $this->tempCacheDir(), 'home' => sys_get_temp_dir()]]);
        self::assertInstanceOf(ComposerCacheAdapter::class, ServiceFactory::createCache(new NullIO(), $config));
    }

    public function testDisabledComposerCacheFallsBackToRequestScopedCache(): void
    {
        // `composer --no-cache` sets COMPOSER_CACHE_DIR=/dev/null, which Composer\Cache::isUsable()
        // rejects -> isEnabled() false.
        $config = new Config(false, sys_get_temp_dir());
        $config->merge(['config' => ['cache-dir' => '/dev/null', 'home' => sys_get_temp_dir()]]);
        self::assertInstanceOf(ArrayCache::class, ServiceFactory::createCache(new NullIO(), $config));
    }

    private static function bitbucketConfig(): Config
    {
        $config = new Config(false, sys_get_temp_dir());
        $config->merge(['config' => ['bitbucket-oauth' => ['bitbucket.org' => ['consumer-key' => 'key', 'consumer-secret' => 'secret']]]]);

        return $config;
    }

    /**
     * A downloader that answers the token URL from a script and records the request it saw in its
     * public `$seen`, so the exchange runs without the network.
     */
    /** A downloader that answers the token URL from a script and records the request it saw, so the exchange runs without the network. */
    private static function tokenDownloader(NullIO $io, Config $config, ?int $status, string $body): RecordingTokenDownloader
    {
        return new RecordingTokenDownloader($io, $config, $status, $body);
    }

    /** The three shapes that need no exchange: nothing stored, a bearer token already, and credentials that are not the configured consumer. */
    public function testTheBitbucketAuthorizerOnlyExchangesAConfiguredConsumer(): void
    {
        $config = self::bitbucketConfig();
        $never = static function (): HttpDownloader {
            self::fail('no exchange expected');
        };

        $io = new NullIO();
        self::assertFalse(ServiceFactory::bitbucketAuthorizer($io, $config, Deadline::never(), $never)(), 'no credentials at all');

        $io = new NullIO();
        $io->setAuthentication('bitbucket.org', 'x-token-auth', 'bearer');
        self::assertTrue(ServiceFactory::bitbucketAuthorizer($io, $config, Deadline::never(), $never)(), 'already a bearer token');

        $io = new NullIO();
        $io->setAuthentication('bitbucket.org', 'me@example.com', 'atlassian-api-token');
        self::assertTrue(ServiceFactory::bitbucketAuthorizer($io, $config, Deadline::never(), $never)(), 'http-basic: AuthHelper sends it as it is');
        self::assertSame(['username' => 'me@example.com', 'password' => 'atlassian-api-token'], $io->getAuthentication('bitbucket.org'), 'left untouched');

        $io = new NullIO();
        $io->setAuthentication('api.bitbucket.org', 'me@example.com', 'atlassian-api-token');
        self::assertTrue(ServiceFactory::bitbucketAuthorizer($io, $config, Deadline::never(), $never)(), 'credentials under the API host count too');
    }

    /** The consumer pair becomes the bearer token AuthHelper sends, and nothing is written anywhere. */
    public function testTheBitbucketAuthorizerExchangesTheConsumerForABearerTokenInTheIo(): void
    {
        $config = self::bitbucketConfig();
        $io = new NullIO();
        $io->setAuthentication('bitbucket.org', 'key', 'secret');
        $downloader = self::tokenDownloader($io, $config, 200, '{"access_token":"ATOKEN","expires_in":7200,"token_type":"bearer"}');

        $authorizer = ServiceFactory::bitbucketAuthorizer($io, $config, self::deadline(5.0, 2.6), static fn (): RecordingTokenDownloader => $downloader);

        self::assertTrue($authorizer());
        self::assertSame(['username' => 'x-token-auth', 'password' => 'ATOKEN'], $io->getAuthentication('bitbucket.org'));
        self::assertSame([
            'url' => 'https://bitbucket.org/site/oauth2/access_token',
            'options' => ['retry-auth-failure' => false, 'http' => ['method' => 'POST', 'content' => 'grant_type=client_credentials', 'timeout' => 3]],
        ], $downloader->seen, 'the timeout is what is left of the budget, rounded up');
    }

    /** A refused or malformed exchange leaves the consumer pair alone and the run anonymous, with a warning at -v. */
    public function testAFailedBitbucketExchangeLeavesTheRunAnonymous(): void
    {
        $config = self::bitbucketConfig();
        foreach ([[401, '{"error":"unauthorized_client"}'], [200, '{"token_type":"bearer"}'], [200, 'not json'], [null, '']] as [$status, $body]) {
            $io = new NullIO();
            $io->setAuthentication('bitbucket.org', 'key', 'secret');
            $downloader = self::tokenDownloader($io, $config, $status, $body);

            self::assertFalse(ServiceFactory::bitbucketAuthorizer($io, $config, Deadline::never(), static fn (): RecordingTokenDownloader => $downloader)(), (string) $status);
            self::assertSame(['username' => 'key', 'password' => 'secret'], $io->getAuthentication('bitbucket.org'), 'nothing changed');
        }
    }

    public function testGithubTokenFromComposerConfig(): void
    {
        $config = new Config(false, sys_get_temp_dir());
        $config->merge(['config' => ['github-oauth' => ['github.com' => 'ghp_from_composer']]]);
        self::assertSame('ghp_from_composer', ServiceFactory::githubTokenFromComposer($config));
        self::assertNull(ServiceFactory::githubTokenFromComposer(new Config(false, sys_get_temp_dir())));
    }
}

/** See {@see ServiceFactoryTest::tokenDownloader()}. */
final class RecordingTokenDownloader extends HttpDownloader
{
    private ?int $status;
    private string $body;
    /** @var array<string, mixed> the URL and options of the one request made */
    public array $seen = [];

    public function __construct(IOInterface $io, Config $config, ?int $status, string $body)
    {
        parent::__construct($io, $config);
        $this->status = $status;
        $this->body = $body;
    }

    /** @param array<string, mixed> $options */
    public function get($url, $options = []): Response
    {
        $this->seen = ['url' => $url, 'options' => $options];
        if (!\is_string($url) || $url === '') {
            throw new \LogicException('a URL is expected');
        }
        if ($this->status === null) {
            throw new TransportException('connection refused');
        }
        if ($this->status >= 400) {
            throw new TransportException('HTTP '.$this->status, $this->status);
        }

        return new Response(['url' => $url], $this->status, [], $this->body);
    }
}
