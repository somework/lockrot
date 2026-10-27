<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Config;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Package\RootPackageInterface;
use Composer\Repository\FilterRepository;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\RepositoryInterface;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveCheckers;
use Lockrot\Solver\MoveQuery;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\HermeticComposerConfig;
use PHPUnit\Framework\TestCase;

/**
 * R4-1: Composer wraps a repository that has `only`, `exclude` or `canonical` in a FilterRepository,
 * and the move check asks that repository as Composer does. A second repository plays packagist.org
 * and lists a different package under the same name.
 */
final class FilteredRepositoryMoveCheckTest extends TestCase
{
    /** @var list<FixtureRepositoryServer> */
    private array $servers = [];
    private ?string $home = null;

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];
        if ($this->home !== null) {
            self::removeTree($this->home);
            $this->home = null;
        }
    }

    public function testAMoveOfANameThatOnlyAnOnlyRepositoryServesResolvesAgainstThatRepository(): void
    {
        $acme = $this->server(['acme/lib' => [['version' => '1.4.0'], ['version' => '2.0.0']]]);
        $namesake = $this->server(['acme/lib' => [['version' => '1.4.0'], ['version' => '9.0.0', 'require' => ['php' => '>=99']]], 'other/lib' => [['version' => '1.0.0'], ['version' => '1.1.0']]]);
        [$config, $repositories] = $this->project([['type' => 'composer', 'url' => $acme->url(), 'only' => ['acme/*']], ['type' => 'composer', 'url' => $namesake->url()]]);
        self::assertInstanceOf(FilterRepository::class, $repositories[0]);
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'acme/lib', 'version' => '1.4.0', 'notification-url' => $acme->url().'/downloads/'],
            ['name' => 'other/lib', 'version' => '1.0.0', 'notification-url' => $namesake->url().'/downloads/'],
        ]]);

        $checker = MoveCheckers::forProject(self::root($config), $lock, ProjectConfig::empty(), $config, $repositories, '8.4', false, Deadline::never());

        self::assertSame(['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'installs' => '2.0.0'], $checker->check(MoveQuery::require('acme/lib', '^2.0', '2.0.0'))->check()->jsonSerialize());
        self::assertSame(['result' => 'refused', 'form' => null, 'reason' => null, 'installs' => null], $checker->check(MoveQuery::require('acme/lib', '^9.0', '9.0.0'))->check()->jsonSerialize(), 'the packagist namesake\'s 9.0.0 is not a release of acme/lib');
        self::assertSame(MoveCheck::RESOLVES, $checker->check(MoveQuery::update('other/lib', '^1.1', '1.1.0'))->check()->result());
    }

    public function testAMoveOfANameThatAnExcludeRepositoryLeavesOutResolvesAgainstTheNextRepository(): void
    {
        $acme = $this->server(['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0']], 'acme/tool' => [['version' => '1.0.0'], ['version' => '7.0.0']]]);
        $next = $this->server(['acme/tool' => [['version' => '1.0.0'], ['version' => '1.1.0']]]);
        [$config, $repositories] = $this->project([['type' => 'composer', 'url' => $acme->url(), 'exclude' => ['acme/tool']], ['type' => 'composer', 'url' => $next->url()]]);
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'acme/lib', 'version' => '1.0.0', 'notification-url' => $acme->url().'/downloads/'],
            ['name' => 'acme/tool', 'version' => '1.0.0', 'notification-url' => $next->url().'/downloads/'],
        ]]);

        $checker = MoveCheckers::forProject(self::root($config, ['acme/lib' => '^1.0', 'acme/tool' => '*']), $lock, ProjectConfig::empty(), $config, $repositories, '8.4', false, Deadline::never());

        self::assertSame([MoveCheck::RESOLVES, '1.1.0'], [$checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'))->check()->result(), $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'))->installs()]);
        self::assertSame([MoveCheck::RESOLVES, '1.1.0'], [$checker->check(MoveQuery::update('acme/tool', '>=1.1', '1.1.0'))->check()->result(), $checker->check(MoveQuery::update('acme/tool', '>=1.1', '1.1.0'))->installs()]);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $releases
     */
    private function server(array $releases): FixtureRepositoryServer
    {
        $server = FixtureRepositoryServer::fromReleases($releases);
        $server->start();
        $this->servers[] = $server;

        return $server;
    }

    /**
     * Composer's config and default repositories for the given `repositories`, in that order.
     *
     * @param list<array<string, mixed>> $repositories
     *
     * @return array{Config, list<RepositoryInterface>}
     */
    private function project(array $repositories): array
    {
        $this->home = sys_get_temp_dir().'/lockrot-filtered-repository-'.bin2hex(random_bytes(8));
        mkdir($this->home);
        $config = HermeticComposerConfig::create($this->home);
        $config->merge(['config' => ['cache-dir' => $this->home.'/cache', 'secure-http' => false, 'audit' => ['block-insecure' => false]], 'repositories' => array_merge($repositories, [['packagist.org' => false]])]);
        $io = new NullIO();
        $manager = RepositoryFactory::manager($io, $config, Factory::createHttpDownloader($io, $config));

        return [$config, array_values(RepositoryFactory::defaultRepos($io, $config, $manager))];
    }

    /** @param array<string, string> $requires */
    private static function root(Config $config, array $requires = ['acme/lib' => '^1.0', 'other/lib' => '^1.0']): RootPackageInterface
    {
        $io = new NullIO();

        return (new RootPackageLoader(RepositoryFactory::manager($io, $config, Factory::createHttpDownloader($io, $config)), $config, null, null, $io))->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => $requires]);
    }

    private static function removeTree(string $dir): void
    {
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }
}
