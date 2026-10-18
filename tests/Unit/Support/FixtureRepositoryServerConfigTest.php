<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Support;

use Composer\Util\Platform;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\HermeticComposerConfig;
use PHPUnit\Framework\TestCase;

/**
 * The fixture server's Composer config reads no credential of the machine: tests are hermetic, and
 * Composer 2.6 throws on a GitHub token whose characters it does not accept.
 */
final class FixtureRepositoryServerConfigTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];
    private string $home = '';

    protected function setUp(): void
    {
        foreach (['COMPOSER_AUTH', 'COMPOSER_HOME', 'COMPOSER_CACHE_DIR'] as $name) {
            $this->saved[$name] = Platform::getEnv($name);
        }
        $this->home = sys_get_temp_dir().'/lockrot-machine-home-'.bin2hex(random_bytes(8));
        mkdir($this->home);
        file_put_contents($this->home.'/auth.json', (string) json_encode(['http-basic' => ['repo.example.com' => ['username' => 'machine', 'password' => 'secret']]]));
        Platform::putEnv('COMPOSER_HOME', $this->home);
        Platform::putEnv('COMPOSER_AUTH', (string) json_encode(['github-oauth' => ['github.com' => 'not a valid token!']]));
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            $value === false ? Platform::clearEnv($name) : Platform::putEnv($name, $value);
        }
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->home, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->home);
    }

    /** Composer creates its home, cache and data directories when it builds the config. */
    public function testTheConfigWritesOnlyBelowTheHomeThatTheCallerOwns(): void
    {
        $machineCache = $this->home.'/machine-cache';
        Platform::putEnv('COMPOSER_CACHE_DIR', $machineCache);
        $home = $this->home.'/hermetic';

        HermeticComposerConfig::create($home);

        self::assertDirectoryExists($home.'/cache');
        self::assertDirectoryDoesNotExist($machineCache);
        self::assertSame($machineCache, Platform::getEnv('COMPOSER_CACHE_DIR'), 'the environment of the test process is restored');
    }

    public function testTheConfigHoldsNoAuthOfTheMachine(): void
    {
        $server = FixtureRepositoryServer::fromLockFiles([]);
        try {
            $config = $server->config();
            $repositories = $server->repositories();
        } finally {
            $server->stop();
        }

        // Composer 2.2 gives null for a key that no source sets.
        self::assertSame([[], []], [$config->get('github-oauth') ?? [], $config->get('http-basic') ?? []]);
        self::assertNotSame([], $repositories);
        self::assertSame((string) json_encode(['github-oauth' => ['github.com' => 'not a valid token!']]), Platform::getEnv('COMPOSER_AUTH'), 'the environment of the test process is restored');
    }
}
