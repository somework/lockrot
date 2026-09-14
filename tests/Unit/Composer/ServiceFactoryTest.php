<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Composer;

use Composer\Config;
use Composer\IO\NullIO;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Composer\ComposerCacheAdapter;
use Lockrot\Composer\ServiceFactory;
use Lockrot\Config\LockrotConfig;
use Lockrot\Data\Cache\ArrayCache;
use PHPUnit\Framework\TestCase;

final class ServiceFactoryTest extends TestCase
{
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
        $analyzer = ServiceFactory::createAnalyzer(new NullIO(), $config, $lockrot, null, Clock::fixed('2026-09-14T00:00:00+00:00'));
        self::assertInstanceOf(Analyzer::class, $analyzer);
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
        // (Cache.php:82) rejects -> isEnabled() false.
        $config = new Config(false, sys_get_temp_dir());
        $config->merge(['config' => ['cache-dir' => '/dev/null', 'home' => sys_get_temp_dir()]]);
        self::assertInstanceOf(ArrayCache::class, ServiceFactory::createCache(new NullIO(), $config));
    }

    public function testGithubTokenFromComposerConfig(): void
    {
        $config = new Config(false, sys_get_temp_dir());
        $config->merge(['config' => ['github-oauth' => ['github.com' => 'ghp_from_composer']]]);
        self::assertSame('ghp_from_composer', ServiceFactory::githubTokenFromComposer($config));
        self::assertNull(ServiceFactory::githubTokenFromComposer(new Config(false, sys_get_temp_dir())));
    }
}
