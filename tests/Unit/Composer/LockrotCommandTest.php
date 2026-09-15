<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Composer;

use Composer\Composer;
use Composer\Config;
use Composer\Console\Application;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginEvents;
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
use Lockrot\Json\JsonReader;
use Lockrot\Signal\SignalSet;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\VerdictEngine;
use Lockrot\Version;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * Snapshots COMPOSER_CACHE_DIR/COMPOSER_HOME, sets them to $cacheDir/$home for the duration of
     * $body, and restores each in a finally block — putEnv() when the previous value was a string,
     * clearEnv() when it was false — so a caller running with these already set (e.g. a nested
     * Composer invocation) is left the way it found them rather than wiped.
     *
     * @param callable(): void $body
     */
    private function withComposerEnv(string $cacheDir, string $home, callable $body): void
    {
        $previousCacheDir = Platform::getEnv('COMPOSER_CACHE_DIR');
        $previousHome = Platform::getEnv('COMPOSER_HOME');
        Platform::putEnv('COMPOSER_CACHE_DIR', $cacheDir);
        Platform::putEnv('COMPOSER_HOME', $home);
        try {
            $body();
        } finally {
            if ($previousCacheDir === false) {
                Platform::clearEnv('COMPOSER_CACHE_DIR');
            } else {
                Platform::putEnv('COMPOSER_CACHE_DIR', $previousCacheDir);
            }
            if ($previousHome === false) {
                Platform::clearEnv('COMPOSER_HOME');
            } else {
                Platform::putEnv('COMPOSER_HOME', $previousHome);
            }
        }
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
     *
     * @param null|MetadataLoaderInterface $loader the in-memory default when null, as in tester()
     */
    private function runWithSplitStreams(array $args, ?MetadataLoaderInterface $loader = null): array
    {
        $command = $this->command($loader);
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

    /**
     * The options every stdout write() of one run was made with, in order.
     *
     * The report is the only thing this command writes to stdout (errors go to the error output of
     * a ConsoleOutputInterface), so the single entry this returns is the report write itself.
     *
     * @param array<string, mixed> $args
     *
     * @return array{0: int, 1: string, 2: list<int>} exit code, stdout, the options of each write
     */
    private function runRecordingWriteOptions(array $args, ?MetadataLoaderInterface $loader = null): array
    {
        $command = $this->command($loader);
        $input = new ArrayInput($args, $command->getDefinition());
        $output = new class (new BufferedOutput()) extends BufferedOutput implements ConsoleOutputInterface {
            /** @var list<int> */
            public array $writeOptions = [];
            private OutputInterface $errorOutput;

            public function __construct(OutputInterface $errorOutput)
            {
                parent::__construct();
                $this->errorOutput = $errorOutput;
            }

            /** @param iterable<string>|string $messages */
            public function write($messages, bool $newline = false, int $options = self::OUTPUT_NORMAL): void
            {
                $this->writeOptions[] = $options;
                parent::write($messages, $newline, $options);
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

        return [$code, $output->fetch(), $output->writeOptions];
    }

    /**
     * Only `table` is written through the tag formatter; every machine-readable format goes out
     * with OUTPUT_RAW, so a `<` in a constraint or a package name can never be eaten as a console
     * tag on its way to a parser (OutputInterface::OUTPUT_RAW = 2 in symfony/console 5.4.47
     * Output/OutputInterface.php:30 and 2.8.52 same file, same line).
     */
    public function testTheTableFormatIsWrittenThroughTheFormatter(): void
    {
        chdir(__DIR__.'/../../fixtures/apps/wallabag_wallabag');
        [$code, $stdout, $options] = $this->runRecordingWriteOptions(['--target-php' => '8.4'], $this->loader());

        self::assertSame(0, $code, $stdout);
        self::assertSame([OutputInterface::OUTPUT_NORMAL], $options);
    }

    /** @return iterable<string, array{0: string}> */
    public static function machineReadableFormatProvider(): iterable
    {
        yield 'json' => ['json'];
        yield 'sarif' => ['sarif'];
        yield 'gitlab' => ['gitlab'];
        yield 'github' => ['github'];
        yield 'markdown' => ['markdown'];
    }

    /**
     * @dataProvider machineReadableFormatProvider
     */
    #[DataProvider('machineReadableFormatProvider')]
    public function testEveryOtherFormatIsWrittenRaw(string $format): void
    {
        chdir(__DIR__.'/../../fixtures/apps/wallabag_wallabag');
        [$code, $stdout, $options] = $this->runRecordingWriteOptions(['--format' => $format, '--target-php' => '8.4'], $this->loader());

        self::assertSame(0, $code, $stdout);
        self::assertSame([OutputInterface::OUTPUT_RAW], $options);
    }

    public function testJsonStaysByteValidWhenEvidenceCarriesAngleBrackets(): void
    {
        chdir(__DIR__.'/../../fixtures/apps/wallabag_wallabag');
        [, $stdout] = $this->runRecordingWriteOptions(['--format' => 'json', '--target-php' => '8.4'], $this->loader());

        self::assertIsArray(json_decode($stdout, true), $stdout);
        // the wallabag lock is full of open-ended php constraints, which is where a `<` shows up
        self::assertStringContainsString('php constraint', $stdout);
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

    public function testGithubAnnotationsOnWallabag(): void
    {
        chdir(__DIR__.'/../../fixtures/apps/wallabag_wallabag');
        $tester = $this->tester($this->loader());
        $code = $tester->execute(['--format' => 'github', '--fail-on' => 'silent', '--target-php' => '8.4']);
        $display = $tester->getDisplay();
        $lines = explode("\n", trim($display));

        self::assertSame(1, $code, $display);
        self::assertMatchesRegularExpression('{^::error file=composer\.lock,line=\d+,title=lockrot%3A abandoned::}', $lines[0]);
        self::assertStringContainsString('phpzip/phpzip', $display);
        self::assertStringStartsWith('200 packages checked · abandoned 19 · ', $lines[\count($lines) - 1]);
    }

    public function testSarifOutputOnWallabag(): void
    {
        chdir(__DIR__.'/../../fixtures/apps/wallabag_wallabag');
        $tester = $this->tester($this->loader());
        $code = $tester->execute(['--format' => 'sarif', '--target-php' => '8.4']);
        $display = $tester->getDisplay();

        self::assertSame(0, $code, $display);
        self::assertStringStartsWith('{', $display);
        $sarif = json_decode($display, true);
        self::assertIsArray($sarif);
        self::assertSame('2.1.0', JsonPath::stringAt($sarif, ['version']));
        self::assertCount(1, JsonPath::arrayAt($sarif, ['runs']));
        self::assertSame('lockrot', JsonPath::stringAt($sarif, ['runs', 0, 'tool', 'driver', 'name']));
        self::assertNotSame([], JsonPath::arrayAt($sarif, ['runs', 0, 'results']));
        self::assertSame('composer.lock', JsonPath::stringAt($sarif, ['runs', 0, 'results', 0, 'locations', 0, 'physicalLocation', 'artifactLocation', 'uri']));
        self::assertGreaterThan(0, JsonPath::intAt($sarif, ['runs', 0, 'results', 0, 'locations', 0, 'physicalLocation', 'region', 'startLine']));
        $srcRoot = JsonPath::stringAt($sarif, ['runs', 0, 'originalUriBaseIds', '%SRCROOT%', 'uri']);
        self::assertStringStartsWith('file:///', $srcRoot);
        self::assertStringEndsWith('/wallabag_wallabag/', $srcRoot);
    }

    public function testGitlabOutputOnWallabag(): void
    {
        chdir(__DIR__.'/../../fixtures/apps/wallabag_wallabag');
        $tester = $this->tester($this->loader());
        $code = $tester->execute(['--format' => 'gitlab', '--fail-on' => 'silent', '--target-php' => '8.4']);
        $display = $tester->getDisplay();

        self::assertSame(1, $code, $display);
        self::assertStringStartsWith('[', $display);
        $issues = json_decode($display, true);
        self::assertIsArray($issues);
        self::assertNotSame([], $issues);
        self::assertSame('issue', JsonPath::stringAt($issues, [0, 'type']));
        self::assertStringStartsWith('lockrot/', JsonPath::stringAt($issues, [0, 'check_name']));
        self::assertSame('composer.lock', JsonPath::stringAt($issues, [0, 'location', 'path']));
    }

    public function testMarkdownOutputOnWallabag(): void
    {
        chdir(__DIR__.'/../../fixtures/apps/wallabag_wallabag');
        $tester = $this->tester($this->loader());
        $code = $tester->execute(['--format' => 'markdown', '--fail-on' => 'silent', '--target-php' => '8.4']);
        $display = $tester->getDisplay();

        self::assertSame(1, $code, $display);
        self::assertStringStartsWith('### lockrot: dependency rot in 75 of 200 packages', $display);
        self::assertStringContainsString('| Package | Version | Verdict | Evidence | Via |', $display);
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
        try {
            $this->withComposerEnv($cacheDir, $home, function () use ($cacheDir): void {
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
            });
        } finally {
            Platform::clearEnv('COMPOSER_DISABLE_NETWORK');
        }
    }

    /**
     * In plugin mode, Composer\Console\Application::doRun() builds the Composer instance (and its
     * EventDispatcher) before this command's initialize() ever runs — see composerBootstrap()'s
     * docblock. composerBootstrap() rebuilds the RepositoryManager from Config alone, so unless that
     * instance's EventDispatcher is threaded through, mirror/proxy/CDN plugins listening for
     * PluginEvents::PRE_FILE_DOWNLOAD never see lockrot's own repository metadata requests. This
     * registers such a listener directly on $composer->getEventDispatcher() and runs the command
     * online (no --offline) against the fixture repository, asserting the listener actually fires.
     */
    public function testPluginModeThreadsEventDispatcherThroughRebuiltRepositories(): void
    {
        $server = self::$server;
        self::assertNotNull($server);

        $project = $this->tempDir('lockrot-dispatcher-project-');
        $cacheDir = $this->tempDir('lockrot-dispatcher-cache-');
        $home = $this->tempDir('lockrot-dispatcher-home-');
        file_put_contents($project.'/composer.json', (string) json_encode([
            'name' => 'lockrot/plugin-mode-dispatcher-test',
            'config' => ['secure-http' => false],
            'repositories' => ['packagist.org' => false, 'fixture' => ['type' => 'composer', 'url' => $server->url()]],
        ]));
        file_put_contents($project.'/composer.lock', (string) json_encode([
            'packages' => [['name' => 'phpzip/phpzip', 'version' => '2.0.8', 'notification-url' => 'https://packagist.org/downloads/']],
        ]));

        chdir($project);
        $this->withComposerEnv($cacheDir, $home, function (): void {
            $command = $this->buildCommand([ServiceFactory::class, 'createAnalyzer']);
            $application = $command->getApplication();
            self::assertInstanceOf(Application::class, $application);
            $composer = $application->getComposer(false, false);
            self::assertInstanceOf(Composer::class, $composer);

            $invocations = 0;
            $composer->getEventDispatcher()->addListener(
                PluginEvents::PRE_FILE_DOWNLOAD,
                static function () use (&$invocations): void {
                    ++$invocations;
                }
            );

            $tester = new CommandTester($command);
            $code = $tester->execute(['--format' => 'json']);

            self::assertSame(0, $code, $tester->getDisplay());
            self::assertGreaterThan(
                0,
                $invocations,
                'a PRE_FILE_DOWNLOAD listener registered on the Composer instance must see lockrot\'s own repository metadata requests'
            );
        });
    }

    /**
     * Symfony's Command::run() calls initialize() with no try/catch of its own
     * (vendor/symfony/console/Command/Command.php:263), so a failure inside Composer's own
     * BaseCommand::initialize() — here, a plugin's PRE_COMMAND_RUN listener throwing, which
     * BaseCommand::initialize() dispatches at src/Composer/Command/BaseCommand.php:246-249, itself
     * uncaught by Composer — must still leave COMPOSER_DISABLE_NETWORK/COMPOSER_ROOT_VERSION as
     * initialize() found them, even though execute() (and its own finally) never runs. Reuses the
     * plugin-mode setup from testPluginModeThreadsEventDispatcherThroughRebuiltRepositories(): a
     * Composer instance built ahead of time via Application::getComposer() so tryComposer() inside
     * BaseCommand::initialize() returns it non-null, which is what makes the PRE_COMMAND_RUN
     * dispatch (and so the listener) run at all.
     */
    public function testInitializeRestoresTheEnvironmentWhenAPreCommandRunListenerThrows(): void
    {
        $server = self::$server;
        self::assertNotNull($server);

        $project = $this->tempDir('lockrot-initialize-throws-project-');
        $cacheDir = $this->tempDir('lockrot-initialize-throws-cache-');
        $home = $this->tempDir('lockrot-initialize-throws-home-');
        file_put_contents($project.'/composer.json', (string) json_encode([
            'name' => 'lockrot/initialize-throws-test',
            'config' => ['secure-http' => false],
            'repositories' => ['packagist.org' => false, 'fixture' => ['type' => 'composer', 'url' => $server->url()]],
        ]));
        file_put_contents($project.'/composer.lock', (string) json_encode([
            'packages' => [['name' => 'phpzip/phpzip', 'version' => '2.0.8', 'notification-url' => 'https://packagist.org/downloads/']],
        ]));

        chdir($project);
        $this->withComposerEnv($cacheDir, $home, function (): void {
            $command = $this->buildCommand([ServiceFactory::class, 'createAnalyzer']);
            $application = $command->getApplication();
            self::assertInstanceOf(Application::class, $application);
            $composer = $application->getComposer(false, false);
            self::assertInstanceOf(Composer::class, $composer);
            $composer->getEventDispatcher()->addListener(
                PluginEvents::PRE_COMMAND_RUN,
                static function (): void {
                    throw new \RuntimeException('a plugin listener blew up during initialize()');
                }
            );

            $previousDisableNetwork = Platform::getEnv('COMPOSER_DISABLE_NETWORK');
            $previousRootVersion = Platform::getEnv('COMPOSER_ROOT_VERSION');
            Platform::clearEnv('COMPOSER_DISABLE_NETWORK');
            Platform::clearEnv('COMPOSER_ROOT_VERSION');
            try {
                $input = new ArrayInput(['--offline' => true, '--format' => 'json'], $command->getDefinition());
                $thrown = null;
                try {
                    $command->run($input, new BufferedOutput());
                } catch (\RuntimeException $e) {
                    $thrown = $e;
                }

                self::assertNotNull($thrown, 'a PRE_COMMAND_RUN listener throwing must propagate out of Command::run(), not be swallowed');
                self::assertSame('a plugin listener blew up during initialize()', $thrown->getMessage());
                self::assertFalse(Platform::getEnv('COMPOSER_DISABLE_NETWORK'), 'the environment must be restored even though execute() never ran');
                self::assertFalse(Platform::getEnv('COMPOSER_ROOT_VERSION'), 'the environment must be restored even though execute() never ran');
            } finally {
                $this->restoreGlobalEnv('COMPOSER_DISABLE_NETWORK', $previousDisableNetwork);
                $this->restoreGlobalEnv('COMPOSER_ROOT_VERSION', $previousRootVersion);
            }
        });
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

    /** @param string|false $value */
    private function restoreGlobalEnv(string $name, $value): void
    {
        if ($value === false) {
            Platform::clearEnv($name);
        } else {
            Platform::putEnv($name, $value);
        }
    }

    public function testOfflineOptionRestoresComposerEnvironmentAfterExecute(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');
        $previousDisableNetwork = Platform::getEnv('COMPOSER_DISABLE_NETWORK');
        $previousRootVersion = Platform::getEnv('COMPOSER_ROOT_VERSION');
        Platform::clearEnv('COMPOSER_DISABLE_NETWORK');
        Platform::clearEnv('COMPOSER_ROOT_VERSION');
        try {
            $tester = $this->tester($this->loader());
            $tester->execute(['--offline' => true, '--fail-on' => 'silent', '--target-php' => '8.4']);

            self::assertFalse(Platform::getEnv('COMPOSER_DISABLE_NETWORK'), 'COMPOSER_DISABLE_NETWORK must be unset again once execute() returns');
            self::assertFalse(Platform::getEnv('COMPOSER_ROOT_VERSION'), 'COMPOSER_ROOT_VERSION must be unset again once execute() returns');
        } finally {
            $this->restoreGlobalEnv('COMPOSER_DISABLE_NETWORK', $previousDisableNetwork);
            $this->restoreGlobalEnv('COMPOSER_ROOT_VERSION', $previousRootVersion);
        }
    }

    public function testAPreExistingComposerRootVersionSurvivesExecute(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');
        $previous = Platform::getEnv('COMPOSER_ROOT_VERSION');
        Platform::putEnv('COMPOSER_ROOT_VERSION', '9.9.9');
        try {
            $tester = $this->tester($this->loader());
            $tester->execute(['--fail-on' => 'silent', '--target-php' => '8.4']);

            self::assertSame('9.9.9', Platform::getEnv('COMPOSER_ROOT_VERSION'), 'a value the caller already set must be restored, not cleared');
        } finally {
            $this->restoreGlobalEnv('COMPOSER_ROOT_VERSION', $previous);
        }
    }

    /**
     * A writable copy of the wallabag fixture: the baseline file is written next to composer.json,
     * and the fixture directory itself is checked in, so every baseline test runs on a copy.
     */
    private function wallabagCopy(): string
    {
        $dir = $this->tempDir('lockrot-baseline-project-');
        $source = \dirname(self::WALLABAG_LOCK);
        copy($source.'/composer.json', $dir.'/composer.json');
        copy($source.'/composer.lock', $dir.'/composer.lock');
        chdir($dir);

        return $dir;
    }

    /** @return array<string, mixed> */
    private function readJsonFile(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded);

        return JsonReader::stringKeyed($decoded);
    }

    /**
     * The `findings` map of a written baseline, with every entry narrowed to the three strings the
     * schema guarantees, so the tests below can read and rewrite it without fighting `mixed`.
     *
     * @return array<string, array<string, string>>
     */
    private function baselineFindings(string $path): array
    {
        $findings = $this->readJsonFile($path)['findings'] ?? null;
        self::assertIsArray($findings);

        $entries = [];
        foreach ($findings as $package => $entry) {
            self::assertIsString($package);
            self::assertIsArray($entry);
            $entries[$package] = [
                'version' => JsonPath::stringAt($entry, ['version']),
                'verdict' => JsonPath::stringAt($entry, ['verdict']),
                'first_seen' => JsonPath::stringAt($entry, ['first_seen']),
            ];
        }

        return $entries;
    }

    /** @param array<string, array<string, string>> $findings */
    private function writeBaselineFile(string $path, array $findings): void
    {
        file_put_contents($path, (string) json_encode([
            'lockrot' => ['version' => Version::STRING, 'schema' => 1],
            'generated_at' => self::FIXED_NOW,
            'findings' => $findings,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
    }

    public function testGenerateBaselineWritesTheFileAndExitsZeroDespiteFailOn(): void
    {
        $dir = $this->wallabagCopy();

        [$code, $stdout, $stderr] = $this->runWithSplitStreams(
            ['--generate-baseline' => true, '--fail-on' => 'stale', '--target-php' => '8.4'],
            $this->loader()
        );

        self::assertSame(0, $code, $stderr);
        self::assertSame('', $stdout, 'a generate run prints nothing on stdout');
        self::assertMatchesRegularExpression(
            '/^lockrot: baseline written to lockrot-baseline\.json \(\d+ findings\)$/m',
            $stderr
        );
        self::assertFileExists($dir.'/lockrot-baseline.json');

        $findings = $this->baselineFindings($dir.'/lockrot-baseline.json');
        self::assertNotSame([], $findings);
        self::assertStringContainsString('('.\count($findings).' findings)', $stderr);
        self::assertStringEndsWith("}\n", (string) file_get_contents($dir.'/lockrot-baseline.json'));
    }

    public function testASecondRunWithTheGeneratedBaselinePresentExitsZero(): void
    {
        $this->wallabagCopy();
        self::assertSame(0, $this->tester($this->loader())->execute(['--generate-baseline' => true, '--target-php' => '8.4']));

        $tester = $this->tester($this->loader());
        $code = $tester->execute(['--fail-on' => 'stale', '--target-php' => '8.4']);

        self::assertSame(0, $code, $tester->getDisplay());
        self::assertStringContainsString('baseline: ', $tester->getDisplay());
        self::assertStringContainsString('0 new · 0 worsened', $tester->getDisplay());
    }

    public function testAWorsenedFindingExitsOneEvenWithABaseline(): void
    {
        $dir = $this->wallabagCopy();
        self::assertSame(0, $this->tester($this->loader())->execute(['--generate-baseline' => true, '--target-php' => '8.4']));

        $findings = $this->baselineFindings($dir.'/lockrot-baseline.json');
        self::assertSame('abandoned', $findings['doctrine/annotations']['verdict']);
        $findings['doctrine/annotations']['verdict'] = 'stale';
        $this->writeBaselineFile($dir.'/lockrot-baseline.json', $findings);

        $tester = $this->tester($this->loader());
        $code = $tester->execute(['--fail-on' => 'silent', '--target-php' => '8.4']);

        self::assertSame(1, $code, $tester->getDisplay());
        self::assertStringContainsString('1 worsened', $tester->getDisplay());
        self::assertStringContainsString('abandoned (was stale)', $tester->getDisplay());
    }

    public function testRegeneratingCarriesFirstSeenOver(): void
    {
        $dir = $this->wallabagCopy();
        self::assertSame(0, $this->tester($this->loader())->execute(['--generate-baseline' => true, '--target-php' => '8.4']));

        $findings = $this->baselineFindings($dir.'/lockrot-baseline.json');
        $findings['doctrine/annotations']['first_seen'] = '2024-01-02';
        $this->writeBaselineFile($dir.'/lockrot-baseline.json', $findings);

        self::assertSame(0, $this->tester($this->loader())->execute(['--generate-baseline' => true, '--target-php' => '8.4']));

        $regenerated = $this->baselineFindings($dir.'/lockrot-baseline.json');
        self::assertSame('2024-01-02', $regenerated['doctrine/annotations']['first_seen']);
        self::assertSame('2026-09-14', $regenerated['doctrine/cache']['first_seen']);
    }

    public function testJsonOutputCarriesTheBaselineBlock(): void
    {
        $this->wallabagCopy();
        self::assertSame(0, $this->tester($this->loader())->execute(['--generate-baseline' => true, '--target-php' => '8.4']));

        $tester = $this->tester($this->loader());
        $tester->execute(['--format' => 'json', '--target-php' => '8.4']);
        $json = json_decode($tester->getDisplay(), true);

        self::assertIsArray($json);
        self::assertIsArray($json['baseline']);
        self::assertSame('lockrot-baseline.json', $json['baseline']['path']);
        self::assertSame(0, $json['baseline']['new']);
        self::assertSame(0, $json['baseline']['worsened']);
        self::assertSame([], $json['baseline']['stale']);
        self::assertGreaterThan(0, $json['baseline']['known']);
    }

    public function testWithoutABaselineFileTheJsonBaselineBlockIsNull(): void
    {
        $this->wallabagCopy();
        $tester = $this->tester($this->loader());
        $tester->execute(['--format' => 'json', '--target-php' => '8.4']);
        $json = json_decode($tester->getDisplay(), true);

        self::assertIsArray($json);
        self::assertNull($json['baseline']);
    }

    /**
     * Staleness is a question about composer.lock, not about the current run's scope. A baseline
     * generated with --dev holds packages-dev findings; a later run without --dev does not analyse
     * them, but they are still in the lock, so reporting them as "no longer in composer.lock" would
     * be false.
     */
    public function testABaselineGeneratedWithDevReportsNoStaleEntriesOnARunWithoutDev(): void
    {
        $this->wallabagCopy();
        self::assertSame(0, $this->tester($this->loader())->execute(['--generate-baseline' => true, '--dev' => true, '--target-php' => '8.4']));

        $tester = $this->tester($this->loader());
        $tester->execute(['--format' => 'json', '--target-php' => '8.4']);
        $json = json_decode($tester->getDisplay(), true);

        self::assertIsArray($json);
        self::assertIsArray($json['baseline']);
        self::assertSame([], $json['baseline']['stale'], 'packages-dev entries are in the lock, not gone');
        self::assertStringNotContainsString('no longer in composer.lock', $tester->getDisplay());
    }

    public function testAnExplicitBaselinePathThatDoesNotExistIsExit2(): void
    {
        $this->wallabagCopy();

        [$code, $stdout, $stderr] = $this->runWithSplitStreams(
            ['--baseline' => 'ci/missing.json', '--target-php' => '8.4'],
            $this->loader()
        );

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('ci/missing.json', $stderr);
    }

    public function testAnEmptyBaselineOptionIsExit2(): void
    {
        $this->wallabagCopy();

        [$code, $stdout, $stderr] = $this->runWithSplitStreams(
            ['--baseline' => '', '--target-php' => '8.4'],
            $this->loader()
        );

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('--baseline must not be empty', $stderr);
    }

    public function testAMalformedBaselineFileIsExit2(): void
    {
        $dir = $this->wallabagCopy();
        file_put_contents($dir.'/lockrot-baseline.json', '{"findings": ');

        [$code, $stdout, $stderr] = $this->runWithSplitStreams(['--target-php' => '8.4'], $this->loader());

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('is not valid JSON', $stderr);
    }

    public function testAnExplicitBaselinePathIsHonouredAndReportedRelative(): void
    {
        $dir = $this->wallabagCopy();
        mkdir($dir.'/ci');

        [$code, , $stderr] = $this->runWithSplitStreams(
            ['--generate-baseline' => true, '--baseline' => 'ci/rot.json', '--target-php' => '8.4'],
            $this->loader()
        );

        self::assertSame(0, $code, $stderr);
        self::assertFileExists($dir.'/ci/rot.json');
        self::assertFileDoesNotExist($dir.'/lockrot-baseline.json');
        self::assertStringContainsString('baseline written to ci/rot.json', $stderr);
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
