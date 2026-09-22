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
use Lockrot\Tests\Support\MemoisingMetadataLoader;
use Lockrot\Tests\Support\RecordingOutput;
use Lockrot\Tests\Support\SplitStreamOutput;
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
        // The baseline tests write next to composer.json of the *copy* wallabagCopy() makes; if one
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
        self::assertStringContainsString("\n    branch     highest tag        released           newest dated release\n", $display);
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
        // The path the caller asked for, and what is wrong with it, in that order.
        self::assertStringContainsString('ci/missing.json not found', $stderr);
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
     * the only thing telling a user whether to fix their own config or report a bug — and the
     * `<error>` tags have to wrap the whole line, or Composer colours only part of it.
     */
    public function testAConfigErrorIsOneLockrotErrorLineOnStderr(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');

        [$code, $stdout, $errors] = $this->runRecordingErrorMessages(['--fail-on' => 'dead']);

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertCount(1, $errors);
        self::assertStringStartsWith('<error>lockrot: ', $errors[0]);
        self::assertStringContainsString('fail-on must be one of', $errors[0]);
        self::assertStringEndsWith('</error>', $errors[0]);
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
        self::assertStringStartsWith('<error>lockrot failed: ', $errors[0]);
        self::assertStringContainsString('the analyzer blew up', $errors[0]);
        self::assertStringEndsWith('</error>', $errors[0]);
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
}
