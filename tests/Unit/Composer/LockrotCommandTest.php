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
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Json\JsonReader;
use Lockrot\Signal\SignalSet;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\MarkupRefusingFormatter;
use Lockrot\Tests\Support\MemoisingMetadataLoader;
use Lockrot\Tests\Support\RecordingOutput;
use Lockrot\Tests\Support\SplitStreamOutput;
use Lockrot\Verdict\VerdictEngine;
use Lockrot\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\StringInput;
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
    private static ?MemoisingMetadataLoader $loader = null;

    private string $cwd;
    /** @var list<string> */
    private array $tempDirs = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK, self::LARAVEL_LOCK]);
        self::$server->start();
        // One loader shared across every test in this class, matching how a real analyzer run uses
        // it: one instance queried repeatedly rather than rebuilt per call. It remembers what it
        // has already been asked for, because every test here runs the command over the same
        // 200-package lock and fetching all two hundred again for each of them was 30 of this
        // class's 40 seconds — and the class is most of the unit suite, and mutation testing pays
        // it again per mutant. What loading really does is tested in RepositoryMetadataLoaderTest.
        self::$loader = new MemoisingMetadataLoader(
            new RepositoryMetadataLoader(self::$server->repositories(), Clock::fixed(self::FIXED_NOW))
        );
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
        // The baseline tests write next to composer.json of the *copy* fixtureCopy() makes; if one
        // ever runs in the tracked fixture instead (a lost chdir), the file is removed and the test
        // fails here rather than the fixture directory quietly growing an untracked baseline.
        $stray = \dirname(self::WALLABAG_LOCK).'/lockrot-baseline.json';
        if (is_file($stray)) {
            unlink($stray);
            self::fail('a test wrote lockrot-baseline.json into the tracked wallabag fixture');
        }
        foreach ($this->tempDirs as $dir) {
            self::removeTree($dir);
        }
        $this->tempDirs = [];
    }

    private function loader(): MemoisingMetadataLoader
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
        $factory = static function (IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, Tokens $tokens, Clock $clock) use ($loader): Analyzer {
            return self::testAnalyzer($loader, $lockrot, $clock);
        };

        return $this->buildCommand($factory);
    }

    /** The analyzer every test factory in this class builds: fixture metadata and recorded GitHub envelopes, never the network. */
    private static function testAnalyzer(MetadataLoaderInterface $loader, LockrotConfig $lockrot, Clock $clock): Analyzer
    {
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));

        return new Analyzer(
            $loader,
            new ActivityClient(new RecordedHttpClient(__DIR__.'/../../fixtures/http/github'), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, $lockrot->thresholds(), $lockrot->targetPhp(), PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            $lockrot->offline()
        );
    }

    /** @param callable(IOInterface, Config, list<\Composer\Repository\RepositoryInterface>, LockrotConfig, Tokens, Clock): Analyzer $factory */
    private function buildCommand(callable $factory): LockrotCommand
    {
        return $this->register(new LockrotCommand($factory));
    }

    /** The command the plugin registers: no analyzer factory, so its own default wiring is used. */
    private function buildDefaultCommand(): LockrotCommand
    {
        return $this->register(new LockrotCommand());
    }

    private function register(LockrotCommand $command): LockrotCommand
    {
        $app = new Application();
        $app->setAutoExit(false);
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
     * $body, and restores both in a finally block, so a caller running with these already set (e.g.
     * a nested Composer invocation) is left the way it found them rather than wiped.
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
     * Runs the command with genuinely separate stdout/stderr streams, without CommandTester's
     * `capture_stderr_separately` option: on PHP 8.5 that option triggers a
     * `ReflectionProperty::setAccessible()` deprecation inside
     * Symfony\Component\Console\Tester\TesterTrait::initOutput(), which would pollute otherwise
     * pristine test output. Command::run() only needs an InputInterface and an OutputInterface, so a
     * minimal ConsoleOutputInterface double gives the same stream separation directly.
     *
     * @param array<string, mixed> $args
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     *
     * @param null|MetadataLoaderInterface $loader the in-memory default when null, as in tester()
     */
    private function runWithSplitStreams(array $args, ?MetadataLoaderInterface $loader = null): array
    {
        return $this->runCommandWithSplitStreams($this->command($loader), $args);
    }

    /**
     * {@see runWithSplitStreams()} for a command built with a factory of the test's own.
     *
     * @param array<string, mixed> $args
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runCommandWithSplitStreams(LockrotCommand $command, array $args): array
    {
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

    /**
     * Runs the command with a factory that records the process environment and the resolved
     * configuration as the analysis saw them.
     *
     * None of this is visible from outside the run: --offline's network guard is in place only while
     * the command is running, and the COMPOSER_ROOT_VERSION default only while Composer might still
     * guess one. Both are put back before execute() returns.
     *
     * @param array<string, mixed> $args
     *
     * @return array{rootVersion: string|false, disableNetwork: string|false, offline: bool}
     */
    private function observeDuringRun(array $args, ?MetadataLoaderInterface $loader = null): array
    {
        $loader ??= $this->emptyLoader();
        $observed = null;
        $factory = static function (IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, Tokens $tokens, Clock $clock) use ($loader, &$observed): Analyzer {
            $observed = [
                'rootVersion' => Platform::getEnv('COMPOSER_ROOT_VERSION'),
                'disableNetwork' => Platform::getEnv('COMPOSER_DISABLE_NETWORK'),
                'offline' => $lockrot->offline(),
            ];

            return self::testAnalyzer($loader, $lockrot, $clock);
        };
        $tester = new CommandTester($this->buildCommand($factory));
        $tester->execute($args);

        return $observed ?? self::fail('the analyzer factory was never called: '.$tester->getDisplay());
    }

    /**
     * Runs the command and returns what it wrote to stderr *before* Composer's output formatter saw
     * it, so a test can assert on the console tags the formatter would otherwise strip.
     *
     * @param array<string, mixed>                                                                                        $args
     * @param null|callable(IOInterface, Config, list<\Composer\Repository\RepositoryInterface>, LockrotConfig, Tokens, Clock): Analyzer $factory the default test factory when null
     *
     * @return array{0: int, 1: string, 2: list<string>} exit code, stdout, the unformatted stderr messages
     */
    private function runRecordingErrorMessages(array $args, ?callable $factory = null): array
    {
        $command = $factory === null ? $this->command($this->loader()) : $this->buildCommand($factory);
        $input = new ArrayInput($args, $command->getDefinition());
        $errors = new RecordingOutput();
        $output = new SplitStreamOutput();
        $output->setErrorOutput($errors);

        $code = $command->run($input, $output);

        return [$code, $output->fetch(), $errors->raw];
    }

    /**
     * `--help` is where a person looks for the list, and it was written out by hand, so adding a
     * format left it behind — `html` was accepted and unlisted. The option's description now has to
     * name every format the config will take.
     */
    public function testTheHelpTextNamesEveryFormatTheToolAccepts(): void
    {
        $description = $this->command()->getDefinition()->getOption('format')->getDescription();

        foreach (LockrotConfig::FORMATS as $format) {
            self::assertStringContainsString($format, $description, $format.' is accepted but not listed in --help');
        }
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
     * tag on its way to a parser (OutputInterface::OUTPUT_RAW is 2 in both symfony/console 5.4 and
     * 2.8).
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
        // the wallabag lock is full of open-ended php constraints, quoted in S5's evidence, which is where a `<` or a `>` shows up
        self::assertStringContainsString('admits 8.4 untested', $stdout);
        self::assertStringContainsString('(php \\">=', $stdout);
    }

    /** The block sums what the run analysed: `--dev` adds the development packages to it, and nothing else moves. */
    public function testDevPackagesEnterTheLibyearsBlockOnlyWithDev(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');
        $prod = $this->tester($this->loader());
        $prod->execute(['--format' => 'json', '--target-php' => '8.4']);
        $withDev = $this->tester($this->loader());
        $withDev->execute(['--format' => 'json', '--dev' => true, '--target-php' => '8.4']);
        $prodJson = (array) json_decode($prod->getDisplay(), true);
        $devJson = (array) json_decode($withDev->getDisplay(), true);
        $prodBlock = JsonPath::arrayAt($prodJson, ['libyears']);
        $devBlock = JsonPath::arrayAt($devJson, ['libyears']);

        self::assertGreaterThan($prodJson['packages_checked'], $devJson['packages_checked']);
        // every analysed package is either measured or filed under a reason, in both runs
        foreach ([[$prodJson, $prodBlock], [$devJson, $devBlock]] as [$json, $block]) {
            self::assertIsInt($block['measured']);
            self::assertIsArray($block['unmeasured']);
            self::assertSame($json['packages_checked'], $block['measured'] + array_sum($block['unmeasured']));
        }
        self::assertGreaterThanOrEqual($prodBlock['total'], $devBlock['total']);
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

    public function testExplainPrintsOnePackageWithItsSignalsAndFactsAndExitsZero(): void
    {
        chdir(__DIR__.'/../../fixtures/apps/wallabag_wallabag');
        $tester = $this->tester($this->loader());

        $code = $tester->execute(['--explain' => ' Doctrine/Annotations ', '--target-php' => '8.4', '--fail-on' => 'stale']);

        $display = $tester->getDisplay();
        self::assertSame(0, $code, $display);
        self::assertStringStartsWith('doctrine/annotations ', $display, 'the name is trimmed and lower-cased, as Composer names packages');
        self::assertStringContainsString('— abandoned, priority', $display);
        self::assertStringContainsString("\n  S1 high marked abandoned by its repository", $display);
        self::assertStringContainsString("\nrepository metadata (as of ", $display);
        self::assertStringContainsString("\n    branch     highest tag        released           newest dated release     php\n", $display);
        self::assertStringContainsString("\nthresholds: release-warn-years 3", $display);
        self::assertStringNotContainsString('packages checked', $display, 'the report itself is not printed');
    }

    public function testExplainAsJsonCarriesTheFindingAndTheFacts(): void
    {
        chdir(__DIR__.'/../../fixtures/apps/wallabag_wallabag');
        [$code, $stdout, $options] = $this->runRecordingWriteOptions(['--explain' => 'doctrine/annotations', '--format' => 'json', '--target-php' => '8.4'], $this->loader());

        self::assertSame(0, $code, $stdout);
        self::assertSame([OutputInterface::OUTPUT_RAW], $options, 'written raw, like every machine-readable format');
        self::assertStringEndsWith("}\n", $stdout);
        self::assertStringEndsNotWith("\n\n", $stdout, 'the formatter ends the document; the writer adds no second newline');
        $json = json_decode($stdout, true);
        self::assertIsArray($json);
        self::assertIsArray($json['lockrot']);
        self::assertSame(1, $json['lockrot']['schema']);
        self::assertSame('doctrine/annotations', $json['package']);
        self::assertIsArray($json['finding']);
        self::assertSame('abandoned', $json['finding']['verdict']);
        self::assertIsArray($json['metadata']);
        self::assertTrue($json['metadata']['abandoned']);
        self::assertIsArray($json['metadata']['branches']);
        self::assertSame('8.4', $json['target_php']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function explainConfigurationErrors(): iterable
    {
        yield 'not in the lock' => [['--explain' => 'nobody/nothing'], 'nobody/nothing is not in composer.lock'];
        yield 'a dev package without --dev' => [['--explain' => 'clue/ndjson-react'], 'clue/ndjson-react is in packages-dev; pass --dev to explain it'];
        yield 'a format with no explanation form' => [['--explain' => 'doctrine/annotations', '--format' => 'github'], '--explain prints text or, with --format=json, JSON; --format=github has no explanation form'];
        yield 'an empty name' => [['--explain' => ' '], '--explain needs a package name'];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @dataProvider explainConfigurationErrors
     */
    #[DataProvider('explainConfigurationErrors')]
    public function testExplainRefusesWhatItCannotExplainAsAConfigurationError(array $args, string $message): void
    {
        chdir(__DIR__.'/../../fixtures/apps/wallabag_wallabag');
        [$code, $stdout, $errors] = $this->runRecordingErrorMessages($args + ['--target-php' => '8.4']);

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('lockrot: '.$message, implode("\n", $errors));
    }

    /** An explanation does not consult the baseline, so a baseline that would fail the report run does not fail it. */
    public function testExplainDoesNotNeedTheBaseline(): void
    {
        chdir(__DIR__.'/../../fixtures/apps/wallabag_wallabag');
        $tester = $this->tester($this->loader());

        $code = $tester->execute(['--explain' => 'doctrine/annotations', '--baseline' => 'no-such-baseline.json', '--target-php' => '8.4']);

        self::assertSame(0, $code, $tester->getDisplay());
        self::assertStringStartsWith('doctrine/annotations ', $tester->getDisplay());
    }

    public function testExplainReachesADevPackageWithDev(): void
    {
        chdir(__DIR__.'/../../fixtures/apps/wallabag_wallabag');
        $tester = $this->tester($this->loader());

        $code = $tester->execute(['--explain' => 'clue/ndjson-react', '--dev' => true, '--target-php' => '8.4']);

        self::assertSame(0, $code, $tester->getDisplay());
        self::assertStringStartsWith('clue/ndjson-react ', $tester->getDisplay());
        self::assertStringContainsString(' · packages-dev', $tester->getDisplay());
    }

    public function testGithubAnnotationsOnWallabag(): void
    {
        chdir(__DIR__.'/../../fixtures/apps/wallabag_wallabag');
        $tester = $this->tester($this->loader());
        $code = $tester->execute(['--format' => 'github', '--fail-on' => 'silent', '--target-php' => '8.4']);
        $display = $tester->getDisplay();
        $lines = explode("\n", trim($display));

        self::assertSame(1, $code, $display);
        self::assertMatchesRegularExpression('{^::error file=composer\.lock,line=\d+,title=lockrot%3A abandoned \(critical\)::}', $lines[0]);
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
        // 51 flagged: 0.11.0 moved S5's line to PHP 8.0's GA, and 32 of wallabag's old-promise rows were PHP 8-era releases.
        self::assertStringStartsWith('### lockrot: dependency rot in 51 of 200 packages', $display);
        self::assertStringContainsString('| Package | Version | Verdict | Evidence | Via |', $display);
    }

    /**
     * The laravel skeleton's one finding is `stale` on a transitive package — priority `low`. The
     * verdict threshold `stale` fails on it wherever it sits; the priority threshold `medium` lets
     * it pass and `low` does not. wallabag has direct `critical` rows, so `critical` fails there.
     */
    public function testAPriorityFailOnDecidesTheExitCode(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');
        foreach (['stale' => 1, 'medium' => 0, 'low' => 1, 'none' => 0] as $failOn => $code) {
            $tester = $this->tester($this->loader());
            self::assertSame($code, $tester->execute(['--fail-on' => $failOn, '--target-php' => '8.4']), $failOn."\n".$tester->getDisplay());
        }

        chdir(__DIR__.'/../../fixtures/apps/wallabag_wallabag');
        $tester = $this->tester($this->loader());
        self::assertSame(1, $tester->execute(['--fail-on' => 'critical', '--target-php' => '8.4']), $tester->getDisplay());
    }

    public function testCleanProjectExitZero(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');
        $tester = $this->tester($this->loader());
        self::assertSame(0, $tester->execute(['--fail-on' => 'silent', '--target-php' => '8.4']));
    }

    /**
     * `composer lockrot` never walks up to a parent project the way `composer` does, so the message
     * has to name the directory it did look in and say that it looked nowhere else — otherwise the
     * only reading left is "this project has no lock".
     */
    public function testMissingLockIsExit2(): void
    {
        $dir = $this->tempDir('lockrot-nolock-');
        chdir($dir);
        // The real path, because sys_get_temp_dir() is a symlink on macOS and getcwd() resolves it.
        $cwd = (string) getcwd();
        $tester = $this->tester();
        self::assertSame(2, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringStartsWith('lockrot: composer.lock not found in ', $display);
        self::assertStringContainsString('not found in '.$cwd.';', $display);
        self::assertStringContainsString('lockrot does not look in parent directories', $display);
    }

    /** `composer rot` is the documented short form, so the alias is part of the command's contract. */
    public function testTheCommandIsAlsoReachableAsRot(): void
    {
        self::assertSame(['rot'], $this->command()->getAliases());
    }

    /**
     * The command the plugin registers, with no analyzer factory of its own, has to build a working
     * one through {@see ServiceFactory::createAnalyzer()}.
     *
     * Kept off the network the same way {@see InstallTimeSummaryTest} keeps its equivalent test off
     * it: the project points at the class-level fixture repository, and the locked package carries no
     * `source`, so no repository activity round is planned.
     */
    public function testTheDefaultAnalyzerFactoryBuildsAWorkingAnalyzer(): void
    {
        $server = self::$server;
        self::assertNotNull($server);

        $project = $this->tempDir('lockrot-default-factory-project-');
        file_put_contents($project.'/composer.json', (string) json_encode([
            'name' => 'lockrot/default-factory-test',
            'require' => ['phpzip/phpzip' => '2.0.8'],
            'config' => ['secure-http' => false],
            'repositories' => ['packagist.org' => false, 'fixture' => ['type' => 'composer', 'url' => $server->url()]],
        ]));
        file_put_contents($project.'/composer.lock', (string) json_encode([
            'packages' => [[
                'name' => 'phpzip/phpzip',
                'version' => '2.0.8',
                'require' => ['php' => '>=5.3.0'],
                'notification-url' => 'https://packagist.org/downloads/',
            ]],
        ]));

        chdir($project);
        $this->withComposerEnv($this->tempDir('lockrot-default-factory-cache-'), $this->tempDir('lockrot-default-factory-home-'), function (): void {
            $tester = new CommandTester($this->buildDefaultCommand());
            $code = $tester->execute(['--format' => 'json', '--target-php' => '8.4']);

            self::assertSame(0, $code, $tester->getDisplay());
            $json = json_decode($tester->getDisplay(), true);
            self::assertIsArray($json);
            self::assertSame(1, $json['packages_checked'], 'the default factory has to produce an analyzer that actually analysed the lock');
            self::assertIsArray($json['findings']);
            self::assertSame('phpzip/phpzip', JsonPath::stringAt($json, ['findings', 0, 'package']));
        });
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

    /** @param array<string, mixed> $extraLockrot */
    private function writeLockrotConfig(string $dir, string $source, array $extraLockrot): void
    {
        $manifest = $this->readJsonFile($source.'/composer.json');
        $extra = $manifest['extra'] ?? [];
        self::assertIsArray($extra);
        $extra['lockrot'] = $extraLockrot;
        $manifest['extra'] = $extra;
        file_put_contents($dir.'/composer.json', (string) json_encode($manifest, \JSON_UNESCAPED_SLASHES));
    }

    /**
     * An unknown key is one line on stderr naming the key it was probably meant to be, and nothing
     * else: the same report byte for byte, the same exit code. The count pins one line per
     * unknown key per run.
     */
    public function testAnUnknownKeyWarnsOnceOnStderrAndChangesNothingElse(): void
    {
        $dir = $this->fixtureCopy(self::LARAVEL_LOCK, ['target-php' => '8.4']);
        [$cleanCode, $cleanStdout, $cleanStderr] = $this->runWithSplitStreams(['--format' => 'json'], $this->loader());
        $this->writeLockrotConfig($dir, \dirname(self::LARAVEL_LOCK), ['target-php' => '8.4', 'install-tme' => 'off', 'x-ci' => 1, 'extensions' => ['acme/x' => ['k' => 1]]]);

        [$code, $stdout, $stderr] = $this->runWithSplitStreams(['--format' => 'json'], $this->loader());

        self::assertSame(0, $cleanCode, $cleanStderr);
        self::assertStringNotContainsString('unknown key', $cleanStderr);
        self::assertSame($cleanCode, $code, $stderr);
        self::assertSame($cleanStdout, $stdout);
        self::assertIsArray(json_decode($stdout, true));
        self::assertSame(1, substr_count($stderr, 'extra.lockrot.'), $stderr);
        self::assertStringContainsString('lockrot: unknown key extra.lockrot.install-tme ignored (did you mean install-time?)', $stderr);
    }

    public function testAnUnknownKeyNeverChangesTheExitCode(): void
    {
        $this->fixtureCopy(self::WALLABAG_LOCK, ['fail-on' => 'silent', 'target-php' => '8.4', 'slack-webhook' => 'https://example.com']);

        [$code, $stdout, $stderr] = $this->runWithSplitStreams(['--format' => 'json'], $this->loader());

        self::assertSame(1, $code, $stderr);
        self::assertIsArray(json_decode($stdout, true), $stdout);
        self::assertStringContainsString('lockrot: unknown key extra.lockrot.slack-webhook ignored', $stderr);
    }

    public function testLockrotDisableSilencesTheUnknownKeyWarning(): void
    {
        $this->fixtureCopy(self::LARAVEL_LOCK, ['install-tme' => 'off']);
        putenv('LOCKROT_DISABLE=1');
        try {
            [$code, $stdout, $stderr] = $this->runWithSplitStreams(['--format' => 'json'], null);
        } finally {
            putenv('LOCKROT_DISABLE');
        }

        self::assertSame(0, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('disabled', $stderr);
        self::assertStringNotContainsString('unknown key', $stderr);
    }

    /**
     * A config the schema rejects is exit 2 with the schema's message, and the unknown key that may
     * have caused it is named in the same error, with its suggestion. A misspelt required key is
     * reported by the schema as the right one missing, so the suggestion is what says why.
     */
    public function testASchemaErrorAlsoNamesTheUnknownKeys(): void
    {
        $this->fixtureCopy(self::LARAVEL_LOCK, ['install-tme' => 'off', 'fail-on' => 'dead']);

        [$code, $stdout, $stderr] = $this->runWithSplitStreams([]);

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertStringStartsWith("lockrot: extra.lockrot is invalid:\n  - fail-on: ", $stderr);
        self::assertStringEndsWith("\n  - unknown key extra.lockrot.install-tme ignored (did you mean install-time?)\n", $stderr);
        self::assertSame(1, substr_count($stderr, 'unknown key'), $stderr);
    }

    public function testAMisspeltRequiredIgnoreKeyGetsItsSuggestionInTheError(): void
    {
        $this->fixtureCopy(self::LARAVEL_LOCK, ['ignore' => [['package' => 'a/b', 'reasn' => 'legacy']]]);

        [$code, , $stderr] = $this->runWithSplitStreams([]);

        self::assertSame(2, $code);
        self::assertStringContainsString('  - ignore[0].reason: The property reason is required', $stderr);
        self::assertStringContainsString('  - unknown key extra.lockrot.ignore[0].reasn ignored (did you mean reason?)', $stderr);
    }

    public function testExplainStillPrintsCleanJsonWithAnUnknownKey(): void
    {
        $this->fixtureCopy(self::LARAVEL_LOCK, ['target-php' => '8.4', 'install-tme' => 'off']);

        [$code, $stdout, $stderr] = $this->runWithSplitStreams(['--explain' => 'brick/math', '--format' => 'json'], $this->loader());

        self::assertSame(0, $code, $stderr);
        self::assertIsArray(json_decode($stdout, true), $stdout);
        self::assertStringContainsString('lockrot: unknown key extra.lockrot.install-tme ignored', $stderr);
    }

    /**
     * Each run warns about the config it read, however many ran before it in the same process: there
     * is no process-wide memory of what was printed.
     */
    public function testEveryRunInAProcessWarnsAboutItsOwnConfig(): void
    {
        $this->fixtureCopy(self::LARAVEL_LOCK, ['target-php' => '8.4', 'install-tme' => 'off']);

        [, , $first] = $this->runWithSplitStreams(['--format' => 'json'], $this->loader());
        [$code, $stdout, $second] = $this->runWithSplitStreams(['--format' => 'json'], $this->loader());

        $line = "lockrot: unknown key extra.lockrot.install-tme ignored (did you mean install-time?)\n";
        self::assertSame($line, $first);
        self::assertSame($line, $second);
        self::assertSame(0, $code, $second);
        self::assertIsArray(json_decode($stdout, true), $stdout);
    }

    /**
     * A key is the project's text, never console markup, and the line never reaches Symfony's tag
     * formatter: some symfony/console 5.4 releases in Composer's PHARs throw on `<<fg=red>>`, which
     * turned a warning into exit 2 with nothing on stdout. A formatter that refuses the text proves
     * it is not even asked; `a\<b` keeps its backslash, escaped like any other.
     *
     * @dataProvider keysThatLookLikeMarkup
     */
    #[DataProvider('keysThatLookLikeMarkup')]
    public function testAKeyThatLooksLikeMarkupIsPrintedAsWrittenAndChangesNothing(string $key, string $shown): void
    {
        $this->fixtureCopy(self::LARAVEL_LOCK, ['target-php' => '8.4', $key => 1]);
        $errors = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false, new MarkupRefusingFormatter($key));

        [$code, $stdout] = $this->runWithErrorOutput(['--format' => 'json'], $errors);

        $stderr = $errors->fetch();
        self::assertSame(0, $code, $stderr);
        self::assertIsArray(json_decode($stdout, true), $stdout);
        self::assertSame('lockrot: unknown key extra.lockrot.'.$shown." ignored\n", $stderr);
    }

    /** @return iterable<string, array{string, string}> */
    public static function keysThatLookLikeMarkup(): iterable
    {
        yield 'a style tag' => ['<<fg=red>>', '<<fg=red>>'];
        yield 'a link tag' => ['<<href=https://example.com>>', '<<href=https://example.com>>'];
        yield 'an escaped tag' => ['a\\<b', 'a\\\\<b'];
    }

    /**
     * A config error quotes the keys too, so it is written past the formatter as well: the schema
     * error and the key it names both reach stderr, and the exit code is the config error's.
     */
    public function testAConfigErrorQuotingAKeyThatLooksLikeMarkupIsPrintedAsWritten(): void
    {
        $this->fixtureCopy(self::LARAVEL_LOCK, ['<<fg=red>>' => 1, 'fail-on' => 'dead']);
        $errors = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false, new MarkupRefusingFormatter('<<'));

        [$code, $stdout] = $this->runWithErrorOutput([], $errors);

        $stderr = $errors->fetch();
        self::assertSame(2, $code, $stderr);
        self::assertSame('', $stdout);
        self::assertStringStartsWith('lockrot: extra.lockrot is invalid:', $stderr);
        self::assertStringEndsWith("\n  - unknown key extra.lockrot.<<fg=red>> ignored\n", $stderr);
    }

    /**
     * Decorated, the warning is coloured by lockrot itself, whole and on stderr alone. The key is
     * thousands of `<b` — the text that once exhausted PCRE's JIT in the formatter, so the closing
     * tag printed literally and the style ran on into the report, which shares the formatter.
     */
    public function testADecoratedWarningIsColouredWholeAndNothingLeaksOntoTheReport(): void
    {
        $this->fixtureCopy(self::LARAVEL_LOCK, ['target-php' => '8.4', str_repeat('<b', 4000) => 1]);
        $formatter = new OutputFormatter(true);
        $errors = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true, $formatter);

        [$code, $stdout] = $this->runWithErrorOutput([], $errors, $formatter);

        $stderr = $errors->fetch();
        self::assertSame(0, $code, $stderr);
        self::assertSame("\033[30;43mlockrot: unknown key extra.lockrot.".substr(str_repeat('<b', 128), 0, 255)."… ignored\033[39;49m\n", $stderr);
        self::assertStringNotContainsString("\033[30;43m", $stdout);
        self::assertStringNotContainsString('<b', $stdout);
    }

    /** A config error is coloured line by line, the error style, on a decorated stderr. */
    public function testADecoratedConfigErrorIsColouredLineByLine(): void
    {
        $this->fixtureCopy(self::LARAVEL_LOCK, ['install-tme' => 'off', 'fail-on' => 'dead']);
        $errors = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);

        [$code] = $this->runWithErrorOutput([], $errors);

        $lines = explode("\n", rtrim($errors->fetch(), "\n"));
        self::assertSame(2, $code);
        self::assertCount(3, $lines);
        foreach ($lines as $line) {
            self::assertStringStartsWith("\033[37;41m", $line);
            self::assertStringEndsWith("\033[39;49m", $line);
        }
        self::assertStringStartsWith("\033[37;41mlockrot: extra.lockrot is invalid:", $lines[0]);
    }

    /**
     * Runs the command with $errors as its stderr and returns the exit code and stdout. $formatter,
     * when given, is stdout's too, the way ConsoleOutput shares one formatter between its streams.
     *
     * @param array<string, mixed> $args
     *
     * @return array{0: int, 1: string}
     */
    private function runWithErrorOutput(array $args, OutputInterface $errors, ?OutputFormatter $formatter = null): array
    {
        $command = $this->command($this->loader());
        $input = new ArrayInput($args, $command->getDefinition());
        $output = new SplitStreamOutput($formatter);
        $output->setErrorOutput($errors);

        $code = $command->run($input, $output);

        return [$code, $output->fetch()];
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
     * Symfony's Command::run() calls initialize() with no try/catch of its own, so a failure inside
     * Composer's own BaseCommand::initialize() — here, a plugin's PRE_COMMAND_RUN listener throwing,
     * which BaseCommand::initialize() dispatches and Composer itself does not catch — must still
     * leave COMPOSER_DISABLE_NETWORK/COMPOSER_ROOT_VERSION as initialize() found them, even though
     * execute() (and its own finally) never runs. Reuses the plugin-mode setup from
     * testPluginModeThreadsEventDispatcherThroughRebuiltRepositories(): a Composer instance built
     * ahead of time via Application::getComposer() so tryComposer() inside BaseCommand::initialize()
     * returns it non-null, which is what makes the PRE_COMMAND_RUN dispatch (and so the listener)
     * run at all.
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

    /**
     * @return iterable<string, array{0: string|false, 1: string}> the value COMPOSER_ROOT_VERSION carries before the run, and the one the analysis must see
     */
    public static function rootVersionPreStates(): iterable
    {
        // Nothing set and an empty value are the two states in which RootPackageLoader would fall
        // back to VersionGuesser — shelling out to git/hg/fossil/svn and then warning about the
        // 1.0.0 default it lands on anyway. Setting that default up front skips both.
        yield 'unset' => [false, '1.0.0'];
        yield 'empty' => ['', '1.0.0'];
        // Anything the caller chose is theirs: lockrot never reads the root package's own version,
        // so it has no reason to overwrite one.
        yield 'already set' => ['9.9.9', '9.9.9'];
    }

    /**
     * @param string|false $before
     *
     * @dataProvider rootVersionPreStates
     */
    #[DataProvider('rootVersionPreStates')]
    public function testTheRootVersionDefaultIsSetForTheRunAndThenPutBack($before, string $duringRun): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');
        $previous = Platform::getEnv('COMPOSER_ROOT_VERSION');
        $this->restoreGlobalEnv('COMPOSER_ROOT_VERSION', $before);
        try {
            $observed = $this->observeDuringRun(['--target-php' => '8.4'], $this->loader());

            self::assertSame($duringRun, $observed['rootVersion']);
            self::assertSame($before, Platform::getEnv('COMPOSER_ROOT_VERSION'), 'the environment is left exactly as initialize() found it');
        } finally {
            $this->restoreGlobalEnv('COMPOSER_ROOT_VERSION', $previous);
        }
    }

    /**
     * --offline's guard is COMPOSER_DISABLE_NETWORK, set for the duration of the run and then put
     * back — including when the caller had already set it to something of their own, which clearing
     * it would silently discard.
     */
    public function testOfflineSetsTheNetworkGuardForTheRunAndPutsBackWhatWasThere(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');
        $previousNetwork = Platform::getEnv('COMPOSER_DISABLE_NETWORK');
        $previousRootVersion = Platform::getEnv('COMPOSER_ROOT_VERSION');
        Platform::putEnv('COMPOSER_DISABLE_NETWORK', '0');
        Platform::clearEnv('COMPOSER_ROOT_VERSION');
        try {
            $observed = $this->observeDuringRun(['--offline' => true, '--target-php' => '8.4'], $this->loader());

            self::assertSame('1', $observed['disableNetwork']);
            self::assertTrue($observed['offline'], '--offline has to reach the analyzer, not only the environment');
            self::assertSame('0', Platform::getEnv('COMPOSER_DISABLE_NETWORK'), 'a value the caller already set is put back, not cleared');
        } finally {
            $this->restoreGlobalEnv('COMPOSER_DISABLE_NETWORK', $previousNetwork);
            $this->restoreGlobalEnv('COMPOSER_ROOT_VERSION', $previousRootVersion);
        }
    }

    /**
     * A writable copy of the fixture project $lockPath belongs to, with the working directory moved
     * into it: the baseline file is written next to composer.json, and the fixture directories are
     * checked in, so every test that writes runs on a copy. With $extraLockrot, the copy's
     * composer.json carries that as its `extra.lockrot` instead of the fixture's own.
     *
     * @param null|array<string, mixed> $extraLockrot
     */
    private function fixtureCopy(string $lockPath, ?array $extraLockrot = null): string
    {
        $dir = $this->tempDir('lockrot-fixture-copy-');
        $source = \dirname($lockPath);
        copy($source.'/composer.lock', $dir.'/composer.lock');
        if ($extraLockrot === null) {
            copy($source.'/composer.json', $dir.'/composer.json');
        } else {
            $this->writeLockrotConfig($dir, $source, $extraLockrot);
        }
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
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);

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
        $this->fixtureCopy(self::WALLABAG_LOCK);
        self::assertSame(0, $this->tester($this->loader())->execute(['--generate-baseline' => true, '--target-php' => '8.4']));

        $tester = $this->tester($this->loader());
        $code = $tester->execute(['--fail-on' => 'stale', '--target-php' => '8.4']);

        self::assertSame(0, $code, $tester->getDisplay());
        self::assertStringContainsString('baseline: ', $tester->getDisplay());
        self::assertStringContainsString('0 new · 0 worsened', $tester->getDisplay());
    }

    public function testAWorsenedFindingExitsOneEvenWithABaseline(): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);
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
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);
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
        $this->fixtureCopy(self::WALLABAG_LOCK);
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
        $this->fixtureCopy(self::WALLABAG_LOCK);
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
        $this->fixtureCopy(self::WALLABAG_LOCK);
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
        $this->fixtureCopy(self::WALLABAG_LOCK);

        [$code, $stdout, $stderr] = $this->runWithSplitStreams(
            ['--baseline' => 'ci/missing.json', '--target-php' => '8.4'],
            $this->loader()
        );

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        // The path the caller asked for, and what is wrong with it, in that order.
        self::assertStringContainsString('ci/missing.json not found', $stderr);
    }

    public function testAnEmptyBaselineOptionIsExit2(): void
    {
        $this->fixtureCopy(self::WALLABAG_LOCK);

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
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);
        file_put_contents($dir.'/lockrot-baseline.json', '{"findings": ');

        [$code, $stdout, $stderr] = $this->runWithSplitStreams(['--target-php' => '8.4'], $this->loader());

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('is not valid JSON', $stderr);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function baselinesTheSchemaCouldNotSee(): iterable
    {
        // json_decode() into objects cannot keep a property whose name starts with a NUL byte: the
        // validator was handed `(object) null`, and the file was called invalid for missing the
        // `lockrot` and `findings` it had.
        yield 'a key starting with a NUL byte' => [
            '{"lockrot": {"version": "0.1.0", "schema": 1}, "findings": {"\u0000acme/a": {}}}',
            'findings.\000acme/a: a key starting with a NUL byte cannot be read',
        ];
        // 1e400 reads as INF, which json_encode() refuses: the validator's own conversion threw a
        // library exception, reported as `lockrot failed:`.
        yield 'a number too large for a float' => [
            '{"lockrot": {"version": "0.1.0", "schema": 1e400}, "findings": {}}',
            '  - lockrot.schema: ',
        ];
    }

    /** @dataProvider baselinesTheSchemaCouldNotSee */
    #[DataProvider('baselinesTheSchemaCouldNotSee')]
    public function testABaselineTheValidatorCouldNotReadIsExitTwo(string $baseline, string $reason): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);
        file_put_contents($dir.'/lockrot-baseline.json', $baseline);

        [$code, $stdout, $stderr] = $this->runWithSplitStreams(['--target-php' => '8.4'], $this->loader());

        self::assertSame(2, $code, $stderr);
        self::assertSame('', $stdout);
        self::assertStringStartsWith("lockrot: baseline file is invalid:\n", $stderr);
        self::assertStringContainsString($reason, $stderr);
        self::assertSame(1, preg_match_all('/^lockrot/m', $stderr), $stderr);
    }

    public function testAnExplicitBaselinePathIsHonouredAndReportedRelative(): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);
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

    /** Without --offline nothing is guarded and nothing is offline: a normal run may use the network. */
    public function testWithoutOfflineOptionComposerDisableNetworkEnvStaysUnset(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');
        $previous = Platform::getEnv('COMPOSER_DISABLE_NETWORK');
        Platform::clearEnv('COMPOSER_DISABLE_NETWORK');
        try {
            $observed = $this->observeDuringRun(['--fail-on' => 'silent', '--target-php' => '8.4'], $this->loader());

            self::assertFalse($observed['disableNetwork']);
            self::assertFalse($observed['offline']);
        } finally {
            $this->restoreGlobalEnv('COMPOSER_DISABLE_NETWORK', $previous);
        }
    }

    /**
     * A configuration error is `lockrot: <what is wrong>`. Both failures exit 2, so the prefix is
     * the only thing telling a user whether to fix their own config or report a bug. The message is
     * written as it is, with no console tags for the formatter to act on — it can quote the
     * project's own keys — and coloured whole by lockrot itself
     * ({@see self::testADecoratedConfigErrorIsColouredLineByLine()}).
     */
    public function testAConfigErrorIsOneLockrotErrorLineOnStderr(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');

        [$code, $stdout, $errors] = $this->runRecordingErrorMessages(['--fail-on' => 'dead']);

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertCount(1, $errors);
        self::assertStringStartsWith('lockrot: fail-on must be one of', $errors[0]);
        self::assertStringEndsWith('got "dead"', $errors[0]);
    }

    /**
     * Anything the command did not anticipate is still exit 2 and a line a user can read. Symfony's
     * Command::run() has no try/catch of its own, so an exception left to reach it would surface as
     * a Composer crash instead.
     */
    public function testAnUnexpectedFailureIsExit2AndSaysWhichKindOfFailureItWas(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');
        $factory = static function (): Analyzer {
            throw new \RuntimeException('the analyzer blew up');
        };

        [$code, $stdout, $errors] = $this->runRecordingErrorMessages(['--target-php' => '8.4'], $factory);

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertCount(1, $errors);
        // Written raw, like a config error: the message is not console markup.
        self::assertSame('lockrot failed: the analyzer blew up', $errors[0]);
    }

    /**
     * Without `--all` the table lists what was flagged; with it, every package the run checked gets
     * a row. The laravel skeleton has one finding among 76 packages, so the two are far apart.
     */
    public function testAllListsEveryCheckedPackageAndTheDefaultOnlyTheFlaggedOnes(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');

        $flaggedOnly = $this->tester($this->loader());
        $flaggedOnly->execute(['--target-php' => '8.4']);
        $everything = $this->tester($this->loader());
        $everything->execute(['--all' => true, '--target-php' => '8.4']);

        // The rows, not the whole display: the footer's libyears line names the package furthest
        // behind whether or not it is flagged — brick/math, here — and that is the point of the line.
        $display = $flaggedOnly->getDisplay();
        $summaryAt = strpos($display, ' packages checked');
        self::assertNotFalse($summaryAt);
        self::assertStringNotContainsString('brick/math', substr($display, 0, $summaryAt), 'brick/math has nothing to flag');
        self::assertStringContainsString('furthest behind brick/math 0.18.0', $display, 'but it is the one furthest behind');
        self::assertStringContainsString('brick/math', $everything->getDisplay());
    }

    /**
     * The report goes out exactly as the formatter produced it. Every format already ends in its own
     * newline, so a second one would leave a blank line under the table and a stray line in a JSON
     * document on its way into a parser.
     */
    public function testTheReportIsWrittenWithoutAnAddedNewline(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');

        [, $stdout] = $this->runWithSplitStreams(['--format' => 'json', '--target-php' => '8.4'], $this->loader());

        self::assertStringEndsWith("}\n", $stdout);
        self::assertStringEndsNotWith("\n\n", $stdout);
    }

    /**
     * A factory that remembers whether it was called, and can run something of its own first — the
     * seam the --output tests use to show a refusal came before the analysis, or to change the disk
     * between the checks and the write.
     *
     * @param null|callable(): void $before run inside the factory, before the analyzer is built
     */
    private function recordingCommand(bool &$called, ?callable $before = null): LockrotCommand
    {
        $loader = $this->loader();
        $factory = static function (IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, Tokens $tokens, Clock $clock) use ($loader, &$called, $before): Analyzer {
            $called = true;
            if ($before !== null) {
                $before();
            }

            return self::testAnalyzer($loader, $lockrot, $clock);
        };

        return $this->buildCommand($factory);
    }

    /**
     * Runs $body with an environment variable set, and puts back what was there.
     *
     * @param callable(): void $body
     */
    private function withEnv(string $name, string $value, callable $body): void
    {
        $previous = getenv($name);
        putenv($name.'='.$value);
        try {
            $body();
        } finally {
            putenv($previous === false ? $name : $name.'='.$previous);
        }
    }

    /**
     * The JSON the html page carries: its `report` key is the `--format=json` document.
     *
     * @return array<string, mixed>
     */
    private static function pagePayload(string $html): array
    {
        if (preg_match('{<script id="lockrot-data" type="application/json">(.*?)</script>}s', $html, $match) !== 1) {
            self::fail('the page carries no data');
        }
        $payload = json_decode($match[1], true);
        self::assertIsArray($payload);

        return JsonReader::stringKeyed($payload);
    }

    public function testTheHelpTextOfOutputNamesTheSyntaxAndTheBase(): void
    {
        $option = $this->command()->getDefinition()->getOption('output');

        self::assertTrue($option->isArray(), '--output is repeatable');
        self::assertTrue($option->isValueRequired());
        self::assertStringContainsString('<format>:<path>', $option->getDescription());
        self::assertStringContainsString('relative to the project directory', $option->getDescription());
    }

    public function testOutputWritesTheFileWhileStdoutKeepsItsFormat(): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);

        [$code, $stdout, $stderr] = $this->runWithSplitStreams(['--output' => ['json:r.json'], '--target-php' => '8.4'], $this->loader());

        self::assertSame(0, $code, $stderr);
        self::assertStringContainsString('200 packages checked', $stdout, 'stdout is still the table');
        self::assertSame("lockrot: json report written to r.json\n", $stderr);
        $json = $this->readJsonFile($dir.'/r.json');
        self::assertIsArray($json['counts']);
        self::assertSame(19, $json['counts']['abandoned']);
    }

    /** Without --output nothing new reaches stderr: a report run says nothing there. */
    public function testWithoutOutputStderrStaysEmpty(): void
    {
        $this->fixtureCopy(self::WALLABAG_LOCK);

        [$code, , $stderr] = $this->runWithSplitStreams(['--target-php' => '8.4'], $this->loader());

        self::assertSame(0, $code);
        self::assertSame('', $stderr);
    }

    /**
     * @dataProvider machineReadableFormatProvider
     */
    #[DataProvider('machineReadableFormatProvider')]
    public function testAFileIsByteIdenticalToWhatThatFormatPrintsOnStdout(string $format): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);

        [$code, $stdout, $stderr] = $this->runWithSplitStreams(['--format' => $format, '--output' => [$format.':r.out'], '--fail-on' => 'silent', '--target-php' => '8.4'], $this->loader());

        self::assertSame(1, $code, $stderr);
        self::assertSame($stdout, file_get_contents($dir.'/r.out'));
    }

    /**
     * The page needs the facts behind each finding, which only an html run collects. A run whose
     * stdout is the table has to collect them too when a file asks for html, or the page loses its
     * release branches.
     */
    public function testTheHtmlFileIsThePageAnHtmlRunWouldPrint(): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);
        [, $page] = $this->runWithSplitStreams(['--format' => 'html', '--target-php' => '8.4'], $this->loader());

        [$code, , $stderr] = $this->runWithSplitStreams(['--output' => ['html:r.html'], '--target-php' => '8.4'], $this->loader());

        self::assertSame(0, $code, $stderr);
        self::assertSame($page, file_get_contents($dir.'/r.html'));
        self::assertNotSame([], self::pagePayload($page)['details'], 'the page carries the facts behind its findings');
    }

    public function testAnHtmlRunWritingAnHtmlFileGivesBothTheFacts(): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);

        [$code, $stdout, $stderr] = $this->runWithSplitStreams(['--format' => 'html', '--output' => ['html:r.html'], '--target-php' => '8.4'], $this->loader());

        self::assertSame(0, $code, $stderr);
        self::assertSame($stdout, file_get_contents($dir.'/r.html'));
        self::assertNotSame([], self::pagePayload($stdout)['details']);
    }

    public function testEveryFileOfOneRunCarriesTheSameReport(): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);

        [$code, $stdout, $stderr] = $this->runWithSplitStreams(['--format' => 'json', '--output' => ['json:r.json', 'html:r.html'], '--target-php' => '8.4'], $this->loader());

        self::assertSame(0, $code, $stderr);
        $json = $this->readJsonFile($dir.'/r.json');
        self::assertSame($json, self::pagePayload((string) file_get_contents($dir.'/r.html'))['report']);
        self::assertSame($stdout, file_get_contents($dir.'/r.json'));
    }

    /**
     * A file has no terminal, so its width is not the terminal's: the table in a file is the table
     * a 120-column, uncoloured stdout gets, whatever COLUMNS says for the run that wrote it.
     */
    public function testATableFileIsTheDefaultWidthTableWhateverTheTerminal(): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);
        $narrow = $wide = '';
        $this->withEnv('COLUMNS', '60', function () use (&$narrow): void {
            [$code, $narrow, $stderr] = $this->runWithSplitStreams(['--output' => ['table:r.txt'], '--target-php' => '8.4'], $this->loader());
            self::assertSame(0, $code, $stderr);
        });
        $this->withEnv('COLUMNS', '120', function () use (&$wide): void {
            [, $wide] = $this->runWithSplitStreams(['--target-php' => '8.4'], $this->loader());
        });

        self::assertSame($wide, file_get_contents($dir.'/r.txt'));
        self::assertNotSame($narrow, $wide, 'stdout followed COLUMNS=60');
    }

    public function testATableFileHasNoAnsiEvenWhenStdoutIsDecorated(): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);
        $tester = $this->tester($this->loader());

        $code = $tester->execute(['--output' => ['table:r.txt'], '--target-php' => '8.4'], ['decorated' => true]);

        self::assertSame(0, $code, $tester->getDisplay());
        self::assertStringContainsString("\e[", $tester->getDisplay());
        $file = (string) file_get_contents($dir.'/r.txt');
        self::assertStringNotContainsString("\e[", $file);
        self::assertStringNotContainsString('<fg=', $file);
        // wallabag's open-ended php constraints, quoted by S5, are where a `>` shows up
        self::assertStringContainsString('(php ">=', $file);
        self::assertStringNotContainsString('\\>', $file);
    }

    public function testEachWrittenFileIsNamedOnStderrInTheOrderGiven(): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);
        mkdir($dir.'/out');

        [$code, , $stderr] = $this->runWithSplitStreams(['--output' => ['sarif:out/r.sarif', 'json:r.json'], '--target-php' => '8.4'], $this->loader());

        self::assertSame(0, $code, $stderr);
        self::assertSame("lockrot: sarif report written to out/r.sarif\nlockrot: json report written to r.json\n", $stderr);
        self::assertFileExists($dir.'/out/r.sarif');
    }

    /** A path is printed as given, even one a console would read as a style tag. */
    public function testAPathThatLooksLikeAConsoleTagIsPrintedAsGiven(): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);

        [$code, , $stderr] = $this->runWithSplitStreams(['--output' => ['json:<info>r.json'], '--target-php' => '8.4'], $this->loader());

        self::assertSame(0, $code, $stderr);
        self::assertSame("lockrot: json report written to <info>r.json\n", $stderr);
        self::assertFileExists($dir.'/<info>r.json');
    }

    public function testARefusalNamingAConsoleTagIsPrintedAsGiven(): void
    {
        $this->fixtureCopy(self::WALLABAG_LOCK);

        [$code, , $stderr] = $this->runWithSplitStreams(['--output' => ['json:<info>/r.json'], '--target-php' => '8.4'], $this->loader());

        self::assertSame(2, $code);
        self::assertSame("lockrot: --output=json:<info>/r.json: directory <info> does not exist; lockrot does not create directories\n", $stderr);
    }

    public function testTheExitCodeDoesNotDependOnOutput(): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);

        [$without] = $this->runWithSplitStreams(['--fail-on' => 'silent', '--target-php' => '8.4'], $this->loader());
        [$with] = $this->runWithSplitStreams(['--fail-on' => 'silent', '--output' => ['json:r.json'], '--target-php' => '8.4'], $this->loader());
        [$quiet] = $this->runWithSplitStreams(['--output' => ['json:r.json'], '--target-php' => '8.4'], $this->loader());

        self::assertSame(1, $without);
        self::assertSame(1, $with);
        self::assertSame(0, $quiet);
        self::assertFileExists($dir.'/r.json');
    }

    /** @return iterable<string, array{array<string, mixed>, string, string, ?array<string, mixed>}> */
    public static function protectedTargets(): iterable
    {
        yield 'composer.json' => [[], 'json:composer.json', 'lockrot never writes composer.json or composer.lock', null];
        yield 'composer.lock' => [[], 'sarif:composer.lock', 'lockrot never writes composer.json or composer.lock', null];
        yield 'the default baseline, which does not exist yet' => [[], 'json:lockrot-baseline.json', 'that is the baseline file, which lockrot writes only with --generate-baseline', null];
        yield 'the baseline --baseline names' => [['--baseline' => 'custom.json'], 'json:custom.json', 'that is the baseline file, which lockrot writes only with --generate-baseline', null];
        yield 'the baseline extra.lockrot names' => [[], 'json:ci-baseline.json', 'that is the baseline file, which lockrot writes only with --generate-baseline', ['baseline' => 'ci-baseline.json']];
        // --baseline points this run elsewhere; the project's committed baseline is still its baseline
        yield 'the baseline extra.lockrot names, when --baseline names another' => [['--baseline' => 'other.json'], 'json:ci-baseline.json', 'that is the baseline file, which lockrot writes only with --generate-baseline', ['baseline' => 'ci-baseline.json']];
        yield 'the default baseline, when --baseline names another' => [['--baseline' => 'other.json'], 'json:lockrot-baseline.json', 'that is the baseline file, which lockrot writes only with --generate-baseline', null];
    }

    /**
     * Refused before the analysis starts — the factory is never called — and nothing on disk moves.
     *
     * @param array<string, mixed>      $args
     * @param null|array<string, mixed> $extra written to the copy's extra.lockrot
     *
     * @dataProvider protectedTargets
     */
    #[DataProvider('protectedTargets')]
    public function testAnOutputThatNamesAProtectedFileIsExit2AndTouchesNothing(array $args, string $spec, string $reason, ?array $extra): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);
        if ($extra !== null) {
            $manifest = $this->readJsonFile($dir.'/composer.json');
            $manifest['extra'] = ['lockrot' => $extra];
            file_put_contents($dir.'/composer.json', (string) json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        }
        $before = [md5_file($dir.'/composer.json'), md5_file($dir.'/composer.lock')];
        $called = false;

        [$code, $stdout, $stderr] = $this->runCommandWithSplitStreams($this->recordingCommand($called), $args + ['--output' => [$spec], '--target-php' => '8.4']);

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertSame('lockrot: --output='.$spec.': '.$reason."\n", $stderr);
        self::assertFalse($called, 'refused before the analysis');
        self::assertSame($before, [md5_file($dir.'/composer.json'), md5_file($dir.'/composer.lock')]);
        self::assertSame(['composer.json', 'composer.lock'], array_values(array_diff((array) scandir($dir), ['.', '..'])));
    }

    /**
     * @return iterable<string, array{string, string, string}> COMPOSER, the manifest it names, the lock Composer pairs with it
     */
    public static function composerManifests(): iterable
    {
        yield 'a .json manifest: its lock swaps the extension' => ['composer-8.json', 'composer-8.json', 'composer-8.lock'];
        yield 'padded, as Composer trims it' => [' composer-8.json ', 'composer-8.json', 'composer-8.lock'];
        yield 'any other name: its lock appends .lock' => ['manifest', 'manifest', 'manifest.lock'];
    }

    /**
     * Composer reads the manifest COMPOSER names, and its lock beside it; neither is a place for a
     * report either.
     *
     * @dataProvider composerManifests
     */
    #[DataProvider('composerManifests')]
    public function testTheManifestComposerNamesAndItsLockAreProtected(string $composer, string $manifest, string $lock): void
    {
        // Composer reads the manifest COMPOSER names and the lock beside it, so they have to exist.
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);
        copy($dir.'/composer.json', $dir.'/'.$manifest);
        copy($dir.'/composer.lock', $dir.'/'.$lock);
        $this->withEnv('COMPOSER', $composer, function () use ($manifest, $lock): void {
            foreach (['json:'.$manifest => 'the manifest', 'json:'.$lock => 'the lock'] as $spec => $what) {
                [$code, , $stderr] = $this->runWithSplitStreams(['--output' => [$spec], '--target-php' => '8.4'], $this->loader());

                self::assertSame(2, $code, $spec);
                self::assertSame('lockrot: --output='.$spec.': that is '.$what.' COMPOSER names, which lockrot never writes'."\n", $stderr);
            }
        });
    }

    /** @return iterable<string, array{string}> */
    public static function projectFiles(): iterable
    {
        yield 'the lock' => ['composer.lock'];
        yield 'the manifest' => ['composer.json'];
    }

    /**
     * A second name on disk for the project's manifest or lock — a hard link here, in another
     * directory — is that file.
     *
     * @dataProvider projectFiles
     */
    #[DataProvider('projectFiles')]
    public function testAnOutputThatIsAProjectFileOnDiskIsExit2(string $file): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);
        mkdir($dir.'/out');
        link($dir.'/'.$file, $dir.'/out/r.json');
        $called = false;

        [$code, , $stderr] = $this->runCommandWithSplitStreams($this->recordingCommand($called), ['--output' => ['json:out/r.json'], '--target-php' => '8.4']);

        self::assertSame(2, $code);
        self::assertSame("lockrot: --output=json:out/r.json: lockrot never writes composer.json or composer.lock\n", $stderr);
        self::assertFalse($called);
    }

    /** Every line lockrot prints on stderr shows a path as it was given, `<` and all. */
    public function testTheBaselineLineShowsAPathThatLooksLikeAConsoleTagAsGiven(): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);

        [$code, , $stderr] = $this->runWithSplitStreams(['--generate-baseline' => true, '--baseline' => '<info>b.json', '--target-php' => '8.4'], $this->loader());

        self::assertSame(0, $code, $stderr);
        self::assertMatchesRegularExpression('/\Alockrot: baseline written to <info>b\.json \(\d+ findings\)\n\z/', $stderr);
        self::assertFileExists($dir.'/<info>b.json');
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function invalidOutputs(): iterable
    {
        yield 'no colon' => [['r.json'], '--output=r.json: expected <format>:<path>, e.g. --output=sarif:lockrot.sarif'];
        yield 'an unknown format' => [['xml:r.xml'], '--output=xml:r.xml: unknown format "xml"; the formats are table, json, github, sarif, gitlab, markdown, html'];
        yield 'an empty path' => [['json:'], '--output=json:: the path is empty'];
        yield 'the same file twice' => [['json:r.json', 'html:./r.json'], '--output=html:./r.json: the same file as --output=json:r.json'];
        yield 'a missing directory' => [['json:missing/r.json'], '--output=json:missing/r.json: directory missing does not exist; lockrot does not create directories'];
    }

    /**
     * @param list<string> $specs
     *
     * @dataProvider invalidOutputs
     */
    #[DataProvider('invalidOutputs')]
    public function testAnInvalidOutputIsExit2BeforeTheAnalysis(array $specs, string $message): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);
        $called = false;

        [$code, $stdout, $stderr] = $this->runCommandWithSplitStreams($this->recordingCommand($called), ['--output' => $specs, '--target-php' => '8.4']);

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertSame('lockrot: '.$message."\n", $stderr);
        self::assertFalse($called, 'refused before the analysis');
        self::assertDirectoryDoesNotExist($dir.'/missing');
    }

    /** A project without a lock is still told that first. */
    public function testAMissingLockIsReportedBeforeAnyOutput(): void
    {
        $dir = $this->tempDir('lockrot-nolock-output-');
        chdir($dir);

        [$code, , $stderr] = $this->runWithSplitStreams(['--output' => ['xml:r.xml']]);

        self::assertSame(2, $code);
        self::assertStringStartsWith('lockrot: composer.lock not found in ', $stderr);
    }

    public function testExplainWithOutputIsExit2AndWritesNothing(): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);
        $called = false;

        [$code, $stdout, $stderr] = $this->runCommandWithSplitStreams($this->recordingCommand($called), ['--explain' => 'phpzip/phpzip', '--output' => ['json:r.json'], '--target-php' => '8.4']);

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertSame("lockrot: --explain prints one package on stdout and --output writes whole-run reports; run them separately\n", $stderr);
        self::assertFalse($called);
        self::assertFileDoesNotExist($dir.'/r.json');
    }

    /**
     * The reports carry this run's findings with no baseline comparison — the baseline is what the
     * run is writing — and they are written before it, so an exit 2 never follows a replaced baseline.
     */
    public function testGenerateBaselineWithOutputWritesBothAndExitsZero(): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);

        [$code, $stdout, $stderr] = $this->runWithSplitStreams(['--generate-baseline' => true, '--fail-on' => 'stale', '--output' => ['json:r.json'], '--target-php' => '8.4'], $this->loader());

        self::assertSame(0, $code, $stderr);
        self::assertSame('', $stdout);
        self::assertMatchesRegularExpression('/\Alockrot: json report written to r\.json\nlockrot: baseline written to lockrot-baseline\.json \(\d+ findings\)\n\z/', $stderr);
        self::assertFileExists($dir.'/lockrot-baseline.json');
        $json = $this->readJsonFile($dir.'/r.json');
        self::assertNull($json['baseline']);
        self::assertIsArray($json['counts']);
        self::assertSame(19, $json['counts']['abandoned']);
    }

    public function testAReportThatCannotBeWrittenUnderGenerateBaselineLeavesTheBaselineAlone(): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);
        $called = false;
        $command = $this->recordingCommand($called, static function () use ($dir): void {
            mkdir($dir.'/r.json');
            touch($dir.'/r.json/occupied');
        });

        [$code, $stdout, $stderr] = $this->runCommandWithSplitStreams($command, ['--generate-baseline' => true, '--output' => ['json:r.json'], '--target-php' => '8.4']);

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertMatchesRegularExpression('/\Alockrot: Cannot write r\.json: \S[^\n]*\n\z/', $stderr);
        self::assertFileDoesNotExist($dir.'/lockrot-baseline.json');
    }

    /**
     * The checks run before the analysis; a path taken in the meantime fails at the write, with the
     * reason, as exit 2 — after the report is already on stdout, which is written first.
     */
    public function testAFileThatCannotBeWrittenIsExit2WithTheReason(): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);
        $called = false;
        $command = $this->recordingCommand($called, static function () use ($dir): void {
            mkdir($dir.'/r.json');
            touch($dir.'/r.json/occupied');
        });

        [$code, $stdout, $stderr] = $this->runCommandWithSplitStreams($command, ['--output' => ['json:r.json'], '--target-php' => '8.4']);

        self::assertTrue($called);
        self::assertSame(2, $code);
        self::assertStringContainsString('200 packages checked', $stdout, 'stdout already holds the report');
        self::assertMatchesRegularExpression('/\Alockrot: Cannot write r\.json: \S[^\n]*\n\z/', $stderr);
    }

    public function testLockrotDisableWritesNoFile(): void
    {
        $dir = $this->fixtureCopy(self::WALLABAG_LOCK);
        $this->withEnv('LOCKROT_DISABLE', '1', function (): void {
            [$code, $stdout] = $this->runWithSplitStreams(['--output' => ['json:r.json']]);

            self::assertSame(0, $code);
            self::assertSame('', $stdout);
        });

        self::assertFileDoesNotExist($dir.'/r.json');
    }

    /** An unexpected failure's message reaches stderr as it was, even when it looks like a console tag. */
    public function testAnUnexpectedFailureMessageIsPrintedAsGiven(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');
        $command = $this->buildCommand(static function (): Analyzer {
            throw new \RuntimeException('cannot open <info>here');
        });

        [$code, , $stderr] = $this->runCommandWithSplitStreams($command, ['--target-php' => '8.4']);

        self::assertSame(2, $code);
        self::assertSame("lockrot failed: cannot open <info>here\n", $stderr);
    }

    /**
     * Runs a command line the way the console application hands one over — unparsed, so it is the
     * command's own binding that reads it — with a factory that fails the test if the analysis is
     * ever reached.
     *
     * @return array{0: int, 1: string, 2: list<string>} exit code, stdout, the unformatted stderr messages
     */
    private function runCommandLine(string $commandLine): array
    {
        $command = $this->buildCommand(static function (): Analyzer {
            throw new \LogicException('a command line that cannot be read must stop before the analysis');
        });
        $errors = new RecordingOutput();
        $output = new SplitStreamOutput();
        $output->setErrorOutput($errors);

        $code = $command->run(new StringInput($commandLine), $output);

        return [$code, $output->fetch(), $errors->raw];
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function unreadableCommandLines(): iterable
    {
        yield 'an option the command does not have' => ['lockrot --nope', '"--nope" option does not exist'];
        yield 'an option missing its value' => ['lockrot --format', '"--format" option requires a value'];
        yield 'a value given to a flag' => ['lockrot --dev=yes', '"--dev" option does not accept a value'];
        yield 'an argument too many' => ['lockrot surplus', 'got "surplus"'];
        // Printed as typed: the line goes past the tag formatter, which would read this as a style.
        yield 'an option that looks like a console tag' => ['lockrot --<fg=red>', '"--<fg" option does not exist'];
    }

    /**
     * A command line the command cannot read is a usage error like any other configuration error:
     * exit 2 and one `lockrot:` line. Symfony binds the command line before initialize() and
     * execute() and lets the failure escape, which Composer rendered in its own box with exit 1 —
     * the code a CI gate reads as "findings".
     *
     * @dataProvider unreadableCommandLines
     */
    #[DataProvider('unreadableCommandLines')]
    public function testACommandLineTheCommandCannotReadIsExitTwoWithOneLockrotLine(string $commandLine, string $reason): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');

        [$code, $stdout, $errors] = $this->runCommandLine($commandLine);

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertCount(1, $errors);
        self::assertStringStartsWith('lockrot: ', $errors[0]);
        self::assertStringContainsString($reason, $errors[0]);
    }

    /** The options Composer's own application adds to every command are part of what the command reads. */
    public function testTheApplicationsOwnOptionsAreNotUsageErrors(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');
        $command = $this->command($this->loader());
        $output = new SplitStreamOutput();

        $code = $command->run(new StringInput('lockrot --no-interaction --no-ansi --target-php=8.4 --format=json'), $output);

        self::assertSame(0, $code, $output->fetchErrors());
        self::assertIsArray(json_decode($output->fetch(), true));
    }

    /** @return iterable<string, array{0: string, 1: array<string, mixed>}> */
    public static function brokenInputsUnderLockrotDisable(): iterable
    {
        yield 'an invalid extra.lockrot' => ['{"extra": {"lockrot": {"fail-on": "dead"}}}', []];
        yield 'an unparsable composer.json' => ['{broken', []];
        yield 'an invalid option value' => ['{}', ['--fail-on' => 'dead']];
        yield 'an empty --baseline' => ['{}', ['--baseline' => '']];
    }

    /**
     * LOCKROT_DISABLE is the off switch, and it is documented as skipping lockrot entirely: nothing
     * the run would read — composer.json, extra.lockrot, the option values, the lock — may turn it
     * into an exit 2. It used to be checked only after the configuration was read and resolved.
     *
     * @param array<string, mixed> $args
     *
     * @dataProvider brokenInputsUnderLockrotDisable
     */
    #[DataProvider('brokenInputsUnderLockrotDisable')]
    public function testLockrotDisableSkipsEverythingTheRunWouldRead(string $manifest, array $args): void
    {
        $dir = $this->tempDir('lockrot-disabled-');
        file_put_contents($dir.'/composer.json', $manifest);
        chdir($dir);
        putenv('LOCKROT_DISABLE=1');
        try {
            [$code, $stdout, $stderr] = $this->runWithSplitStreams($args);
        } finally {
            putenv('LOCKROT_DISABLE');
        }

        self::assertSame(0, $code, $stderr);
        self::assertSame('', $stdout);
        self::assertSame("lockrot disabled via LOCKROT_DISABLE\n", $stderr);
    }

    /** Even a command line the command cannot read: the switch is checked before anything is. */
    public function testLockrotDisableSkipsAnUnreadableCommandLineToo(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');
        putenv('LOCKROT_DISABLE=1');
        try {
            [$code, $stdout, $errors] = $this->runCommandLine('lockrot --nope');
        } finally {
            putenv('LOCKROT_DISABLE');
        }

        self::assertSame(0, $code);
        self::assertSame('', $stdout);
        self::assertSame(['lockrot disabled via LOCKROT_DISABLE'], $errors);
    }

    /**
     * Sets COMPOSER for the length of $body and puts back whatever was there.
     *
     * @param callable(): void $body
     */
    private function withComposerFile(string $value, callable $body): void
    {
        $previous = Platform::getEnv('COMPOSER');
        Platform::putEnv('COMPOSER', $value);
        try {
            $body();
        } finally {
            $this->restoreGlobalEnv('COMPOSER', $previous);
        }
    }

    /**
     * `COMPOSER=alt.json composer install` reads alt.json and alt.lock, and so must `composer
     * lockrot`: it inspects the lock Composer uses, not whatever composer.lock sits beside it. Here
     * composer.json is not even JSON and there is no composer.lock — neither may be touched.
     */
    public function testComposerTheEnvironmentVariableNamesTheManifestAndTheLock(): void
    {
        $dir = $this->tempDir('lockrot-composer-env-');
        $source = \dirname(self::WALLABAG_LOCK);
        copy($source.'/composer.json', $dir.'/alt.json');
        copy($source.'/composer.lock', $dir.'/alt.lock');
        file_put_contents($dir.'/composer.json', '{broken');
        chdir($dir);

        $this->withComposerFile('alt.json', function (): void {
            [$code, $stdout, $stderr] = $this->runWithSplitStreams(['--format' => 'json', '--fail-on' => 'silent', '--target-php' => '8.4'], $this->loader());

            self::assertSame(1, $code, $stderr);
            $json = json_decode($stdout, true);
            self::assertIsArray($json);
            self::assertSame(200, $json['packages_checked']);
            self::assertSame('alt.lock', JsonPath::stringAt($json, ['run', 'lock_file']));
        });
    }

    /** The baseline sits next to the manifest Composer reads, wherever COMPOSER puts that. */
    public function testTheBaselineLivesNextToTheManifestComposerNames(): void
    {
        $dir = $this->tempDir('lockrot-composer-env-baseline-');
        mkdir($dir.'/app');
        $source = \dirname(self::WALLABAG_LOCK);
        copy($source.'/composer.json', $dir.'/app/alt.json');
        copy($source.'/composer.lock', $dir.'/app/alt.lock');
        chdir($dir);

        $this->withComposerFile('app/alt.json', function () use ($dir): void {
            [$code, , $stderr] = $this->runWithSplitStreams(['--generate-baseline' => true, '--target-php' => '8.4'], $this->loader());

            self::assertSame(0, $code, $stderr);
            self::assertFileExists($dir.'/app/lockrot-baseline.json');
            self::assertFileDoesNotExist($dir.'/lockrot-baseline.json');
        });
    }

    /** A missing lock is named as the lock Composer would read, in the directory it would read it from. */
    public function testAMissingLockIsTheLockComposerWouldRead(): void
    {
        $dir = $this->tempDir('lockrot-composer-env-nolock-');
        file_put_contents($dir.'/alt.json', '{}');
        copy(self::LARAVEL_LOCK, $dir.'/composer.lock');
        chdir($dir);
        $cwd = (string) getcwd();

        $this->withComposerFile('alt.json', function () use ($cwd): void {
            [$code, $stdout, $stderr] = $this->runWithSplitStreams([]);

            self::assertSame(2, $code);
            self::assertSame('', $stdout);
            self::assertStringContainsString('lockrot: alt.lock not found in '.$cwd.';', $stderr);
        });
    }

    /** Whatever the manifest's own configuration says is read from the manifest COMPOSER names. */
    public function testTheConfigurationIsReadFromTheManifestComposerNames(): void
    {
        $dir = $this->tempDir('lockrot-composer-env-config-');
        file_put_contents($dir.'/alt.json', '{"extra": {"lockrot": {"fail-on": "dead"}}}');
        file_put_contents($dir.'/composer.json', '{}');
        copy(self::LARAVEL_LOCK, $dir.'/alt.lock');
        chdir($dir);

        $this->withComposerFile('alt.json', function (): void {
            [$code, $stdout, $stderr] = $this->runWithSplitStreams([]);

            self::assertSame(2, $code);
            self::assertSame('', $stdout);
            self::assertStringContainsString('extra.lockrot is invalid:', $stderr);
        });
    }

    /**
     * Composer 2.3 and later refuse a COMPOSER that names a directory, with an exception of their
     * own; that happens while the command is starting up, where only a ConfigException used to be
     * caught — so it escaped as a Composer crash, exit 1.
     */
    public function testNothingThatFailsWhileTheCommandStartsEscapesAsExitOne(): void
    {
        $dir = $this->tempDir('lockrot-composer-env-dir-');
        mkdir($dir.'/not-a-file');
        chdir($dir);

        $this->withComposerFile('not-a-file', function (): void {
            [$code, $stdout, $stderr] = $this->runWithSplitStreams([]);

            self::assertSame(2, $code, $stderr);
            self::assertSame('', $stdout);
            self::assertStringStartsWith('lockrot', $stderr);
        });
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function manifestsTheSchemaCouldNotSee(): iterable
    {
        // json_decode() into objects cannot keep a property whose name starts with a NUL byte, and
        // the validator used to be handed `(object) null` — an empty object, which is valid — so a
        // gate configured next to such a key ran with fail-on none and exited 0.
        yield 'a key starting with a NUL byte' => ['{"extra": {"lockrot": {"fail-on": "abandoned", "\u0000k": 1}}}', 'NUL byte'];
        // 1e400 reads as INF, which json_encode() refuses: the validator's own conversion threw a
        // library exception, and the command exited 1.
        yield 'a number too large for a float' => ['{"extra": {"lockrot": {"release-warn-years": 1e400}}}', 'release-warn-years'];
    }

    /** @dataProvider manifestsTheSchemaCouldNotSee */
    #[DataProvider('manifestsTheSchemaCouldNotSee')]
    public function testAConfigurationTheValidatorCouldNotReadIsExitTwo(string $manifest, string $reason): void
    {
        $dir = $this->tempDir('lockrot-unreadable-config-');
        file_put_contents($dir.'/composer.json', $manifest);
        copy(self::LARAVEL_LOCK, $dir.'/composer.lock');
        chdir($dir);

        [$code, $stdout, $stderr] = $this->runWithSplitStreams(['--target-php' => '8.4'], $this->loader());

        self::assertSame(2, $code, $stderr);
        self::assertSame('', $stdout);
        self::assertStringStartsWith('lockrot: extra.lockrot is invalid:', $stderr);
        self::assertStringContainsString($reason, $stderr);
    }
}
