<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Composer;

use Composer\Config;
use Composer\Console\Application;
use Composer\IO\IOInterface;
use Composer\Util\Platform;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Composer\LockrotCommand;
use Lockrot\Composer\ServiceFactory;
use Lockrot\Config\LockrotConfig;
use Lockrot\Data\GitHub\GitHubClient;
use Lockrot\Data\GitHub\GitHubFetchPlanner;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Signal\SignalSet;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class LockrotCommandTest extends TestCase
{
    private const WALLABAG_LOCK = __DIR__.'/../../fixtures/apps/wallabag_wallabag/composer.lock';
    private const LARAVEL_LOCK = __DIR__.'/../../fixtures/skeletons/laravel/composer.lock';
    private const FIXED_NOW = '2026-09-14T00:00:00+00:00';

    private static ?FixtureRepositoryServer $server = null;
    private static ?RepositoryMetadataLoader $loader = null;

    private string $cwd;
    /** @var list<string> */
    private array $tempDirs = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK, self::LARAVEL_LOCK]);
        self::$server->start();
        // One loader shared across every test in this class, matching how a real analyzer run uses
        // it: one instance queried repeatedly rather than rebuilt per call.
        self::$loader = new RepositoryMetadataLoader(self::$server->repositories(), Clock::fixed(self::FIXED_NOW));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            self::$server->stop();
            self::$server = null;
        }
        self::$loader = null;
    }

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        foreach ($this->tempDirs as $dir) {
            self::removeTree($dir);
        }
        $this->tempDirs = [];
    }

    private function loader(): RepositoryMetadataLoader
    {
        $loader = self::$loader;
        self::assertNotNull($loader);

        return $loader;
    }

    /**
     * A trivial in-memory loader for tests whose command path never reaches the analyzer (a
     * disabled run, a config error, a missing lock) — real fixture metadata is irrelevant there.
     */
    private function emptyLoader(): MetadataLoaderInterface
    {
        return new class () implements MetadataLoaderInterface {
            public function load(array $names): MetadataBatch
            {
                return new MetadataBatch([], $names, []);
            }
        };
    }

    private function command(?MetadataLoaderInterface $loader = null): LockrotCommand
    {
        $loader ??= $this->emptyLoader();
        $factory = static function (IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, ?string $token, Clock $clock) use ($loader): Analyzer {
            return new Analyzer(
                $loader,
                new GitHubClient(new RecordedHttpClient(__DIR__.'/../../fixtures/http/github'), 'recorded'),
                new GitHubFetchPlanner(true),
                BuiltinAllowlist::load(),
                SignalSet::default($clock, $lockrot->thresholds(), $lockrot->targetPhp(), PhpReleaseDates::load()),
                new VerdictEngine(),
                $clock,
                $lockrot->offline()
            );
        };

        return $this->buildCommand($factory);
    }

    /** @param callable(IOInterface, Config, list<\Composer\Repository\RepositoryInterface>, LockrotConfig, ?string, Clock): Analyzer $factory */
    private function buildCommand(callable $factory): LockrotCommand
    {
        $app = new Application();
        $app->setAutoExit(false);
        $command = new LockrotCommand($factory);
        $app->add($command);
        $command->setApplication($app);

        return $command;
    }

    private function tester(?MetadataLoaderInterface $loader = null): CommandTester
    {
        return new CommandTester($this->command($loader));
    }

    /**
     * Runs the command with genuinely separate stdout/stderr streams, without
     * CommandTester's `capture_stderr_separately` option: on PHP 8.5 that option
     * triggers a `ReflectionProperty::setAccessible()` deprecation inside
     * Symfony\Component\Console\Tester\TesterTrait::initOutput() (the property has
     * been public-by-effect since PHP 8.1, but the call itself is only now
     * deprecated), which would pollute otherwise-pristine test output. Command::run()
     * only needs an InputInterface and an OutputInterface, so a minimal
     * ConsoleOutputInterface double gives the same stream separation directly.
     *
     * @param array<string, mixed> $args
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runWithSplitStreams(array $args): array
    {
        $command = $this->command();
        $input = new ArrayInput($args, $command->getDefinition());
        $errorOutput = new BufferedOutput();
        $output = new class ($errorOutput) extends BufferedOutput implements ConsoleOutputInterface {
            private OutputInterface $errorOutput;

            public function __construct(OutputInterface $errorOutput)
            {
                parent::__construct();
                $this->errorOutput = $errorOutput;
            }

            public function getErrorOutput(): OutputInterface
            {
                return $this->errorOutput;
            }

            public function setErrorOutput(OutputInterface $error): void
            {
                $this->errorOutput = $error;
            }

            public function section(): ConsoleSectionOutput
            {
                throw new \LogicException('ConsoleSectionOutput is not supported by this test double');
            }
        };
        $code = $command->run($input, $output);

        return [$code, $output->fetch(), $errorOutput->fetch()];
    }

    public function testTableOutputAndExitCodeOnWallabag(): void
    {
        chdir(__DIR__.'/../../fixtures/apps/wallabag_wallabag');
        $tester = $this->tester($this->loader());
        $code = $tester->execute(['--fail-on' => 'silent', '--target-php' => '8.4']);
        self::assertSame(1, $code, $tester->getDisplay());
        self::assertStringContainsString('phpzip/phpzip', $tester->getDisplay());
        self::assertStringContainsString('200 packages checked', $tester->getDisplay());
    }

    public function testJsonOutputAndDefaultExitZero(): void
    {
        chdir(__DIR__.'/../../fixtures/apps/wallabag_wallabag');
        $tester = $this->tester($this->loader());
        $code = $tester->execute(['--format' => 'json', '--target-php' => '8.4']);
        self::assertSame(0, $code);
        $json = json_decode($tester->getDisplay(), true);
        self::assertIsArray($json);
        self::assertIsArray($json['lockrot']);
        self::assertIsArray($json['counts']);
        self::assertSame(1, $json['lockrot']['schema']);
        self::assertSame(19, $json['counts']['abandoned']);
    }

    public function testCleanProjectExitZero(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');
        $tester = $this->tester($this->loader());
        self::assertSame(0, $tester->execute(['--fail-on' => 'silent', '--target-php' => '8.4']));
    }

    public function testMissingLockIsExit2(): void
    {
        $dir = sys_get_temp_dir().'/lockrot-nolock-'.uniqid();
        mkdir($dir);
        chdir($dir);
        $tester = $this->tester();
        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('composer.lock not found', $tester->getDisplay());
        rmdir($dir);
    }

    public function testMalformedComposerJsonIsExit2(): void
    {
        $dir = sys_get_temp_dir().'/lockrot-badjson-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/composer.json', '{broken');
        chdir($dir);
        try {
            [$code, $stdout, $stderr] = $this->runWithSplitStreams(['--format' => 'json']);
            self::assertSame(2, $code);
            self::assertSame('', $stdout);
            self::assertStringContainsString('composer.json', $stderr);
        } finally {
            unlink($dir.'/composer.json');
            rmdir($dir);
        }
    }

    public function testInvalidLockrotSchemaIsExit2(): void
    {
        $dir = sys_get_temp_dir().'/lockrot-badschema-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/composer.json', json_encode(['extra' => ['lockrot' => ['fail-on' => 'dead']]]));
        chdir($dir);
        try {
            [$code, $stdout, $stderr] = $this->runWithSplitStreams([]);
            self::assertSame(2, $code);
            self::assertSame('', $stdout);
            self::assertStringContainsString('extra.lockrot is invalid:', $stderr);
        } finally {
            unlink($dir.'/composer.json');
            rmdir($dir);
        }
    }

    public function testInvalidFailOnIsExit2(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');
        $tester = $this->tester();
        self::assertSame(2, $tester->execute(['--fail-on' => 'dead']));
    }

    public function testDisabledEnv(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');
        $tester = $this->tester();
        putenv('LOCKROT_DISABLE=1');
        try {
            self::assertSame(0, $tester->execute([]));
            self::assertStringContainsString('disabled', $tester->getDisplay());
        } finally {
            putenv('LOCKROT_DISABLE');
        }
    }

    public function testDisabledEnvKeepsStdoutClean(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');
        putenv('LOCKROT_DISABLE=1');
        try {
            [$code, $stdout, $stderr] = $this->runWithSplitStreams(['--format' => 'json']);
            self::assertSame(0, $code);
            self::assertSame('', $stdout);
            self::assertStringContainsString('disabled', $stderr);
        } finally {
            putenv('LOCKROT_DISABLE');
        }
    }

    public function testConfigErrorGoesToStderr(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');
        [$code, $stdout, $stderr] = $this->runWithSplitStreams(['--fail-on' => 'dead']);
        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('fail-on', $stderr);
    }

    /**
     * The end-to-end guarantee behind --offline, exercised through the real
     * ServiceFactory::createAnalyzer (not the in-memory test loader) against a project whose
     * composer.json points at the class-level fixture repository: with a cold Composer cache,
     * nothing at all may be fetched.
     *
     * Setting COMPOSER_DISABLE_NETWORK is on its own not enough, and this is the condition that
     * shows it: in plugin mode Composer\Console\Application::doRun() builds the Composer instance
     * while collecting plugin commands (getPluginCommands() -> getComposer()), so its
     * HttpDownloader — which latches COMPOSER_DISABLE_NETWORK in its own constructor — and its
     * RepositoryManager both exist before any command's initialize() runs. The
     * $app->getComposer(false, false) call below is exactly that call, reproduced here because
     * CommandTester invokes Command::run() directly and would otherwise never trigger it.
     */
    public function testOfflineInPluginModeFetchesNothingIntoComposersCache(): void
    {
        $server = self::$server;
        self::assertNotNull($server);

        $project = $this->tempDir('lockrot-offline-project-');
        $cacheDir = $this->tempDir('lockrot-offline-cache-');
        $home = $this->tempDir('lockrot-offline-home-');
        file_put_contents($project.'/composer.json', (string) json_encode([
            'name' => 'lockrot/offline-plugin-mode-test',
            'config' => ['secure-http' => false],
            'repositories' => ['packagist.org' => false, 'fixture' => ['type' => 'composer', 'url' => $server->url()]],
        ]));
        file_put_contents($project.'/composer.lock', (string) json_encode([
            'packages' => [['name' => 'phpzip/phpzip', 'version' => '2.0.8', 'notification-url' => 'https://packagist.org/downloads/']],
        ]));

        chdir($project);
        Platform::putEnv('COMPOSER_CACHE_DIR', $cacheDir);
        Platform::putEnv('COMPOSER_HOME', $home);
        try {
            $command = $this->buildCommand([ServiceFactory::class, 'createAnalyzer']);
            $application = $command->getApplication();
            self::assertInstanceOf(Application::class, $application);
            $application->getComposer(false, false);

            $tester = new CommandTester($command);
            $code = $tester->execute(['--offline' => true, '--format' => 'json']);

            self::assertSame(0, $code, $tester->getDisplay());
            self::assertSame([], $this->fetchedFilesUnder($cacheDir), 'nothing may be fetched while offline');
            $json = json_decode($tester->getDisplay(), true);
            self::assertIsArray($json);
            self::assertIsArray($json['notes']);
            $unavailable = array_values(array_filter(
                $json['notes'],
                static fn ($note): bool => \is_string($note) && strpos($note, 'Repository metadata unavailable') === 0
            ));
            self::assertCount(1, $unavailable, (string) json_encode($json['notes']));
        } finally {
            Platform::clearEnv('COMPOSER_CACHE_DIR');
            Platform::clearEnv('COMPOSER_HOME');
            Platform::clearEnv('COMPOSER_DISABLE_NETWORK');
        }
    }

    /**
     * Relative paths of everything Composer wrote under its cache directory, minus the `.htaccess`
     * guard Factory::createConfig() drops into every Composer directory it creates regardless of
     * any request being made.
     *
     * @return list<string>
     */
    private function fetchedFilesUnder(string $dir): array
    {
        $found = [];
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                continue;
            }
            $relative = substr($item->getPathname(), \strlen($dir) + 1);
            if ($relative === '.htaccess') {
                continue;
            }
            $found[] = $relative;
        }
        sort($found);

        return $found;
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.uniqid('', true);
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testWithoutOfflineOptionComposerDisableNetworkEnvStaysUnset(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');
        putenv('COMPOSER_DISABLE_NETWORK');
        $observed = 'factory not called';
        $loader = $this->emptyLoader();
        $factory = function (IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, ?string $token, Clock $clock) use ($loader, &$observed): Analyzer {
            $observed = getenv('COMPOSER_DISABLE_NETWORK');

            return new Analyzer(
                $loader,
                new GitHubClient(new RecordedHttpClient(__DIR__.'/../../fixtures/http/github'), 'recorded'),
                new GitHubFetchPlanner(true),
                BuiltinAllowlist::load(),
                SignalSet::default($clock, $lockrot->thresholds(), $lockrot->targetPhp(), PhpReleaseDates::load()),
                new VerdictEngine(),
                $clock,
                $lockrot->offline()
            );
        };
        try {
            $tester = new CommandTester($this->buildCommand($factory));
            $tester->execute(['--fail-on' => 'silent', '--target-php' => '8.4']);
            self::assertFalse($observed);
        } finally {
            putenv('COMPOSER_DISABLE_NETWORK');
        }
    }
}
