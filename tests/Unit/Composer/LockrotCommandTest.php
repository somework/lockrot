<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Composer;

use Composer\Config;
use Composer\Console\Application;
use Composer\IO\IOInterface;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Composer\LockrotCommand;
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

    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
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

    /**
     * Runs $body against a command wired to a fixture-server-backed loader for $lockFile, built and
     * torn down just for this one call. Each of the three tests that actually run a full analysis
     * needs its own private, single-use server: reusing one `php -S` process (or one loader) across
     * more than one full ~200-package load was measured to wedge the server (see
     * RepositoryMetadataLoaderTest and AcceptanceTest::testWallabag for the same constraint) — a
     * server used for exactly one load, then stopped, avoids it entirely.
     */
    private function withRealMetadata(string $lockFile, callable $body): void
    {
        $server = FixtureRepositoryServer::fromLockFiles([$lockFile]);
        $server->start();
        try {
            $loader = new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::FIXED_NOW));
            $body($this->tester($loader));
        } finally {
            $server->stop();
        }
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
        $this->withRealMetadata(self::WALLABAG_LOCK, function (CommandTester $tester): void {
            $code = $tester->execute(['--fail-on' => 'silent', '--target-php' => '8.4']);
            self::assertSame(1, $code, $tester->getDisplay());
            self::assertStringContainsString('phpzip/phpzip', $tester->getDisplay());
            self::assertStringContainsString('200 packages checked', $tester->getDisplay());
        });
    }

    public function testJsonOutputAndDefaultExitZero(): void
    {
        chdir(__DIR__.'/../../fixtures/apps/wallabag_wallabag');
        $this->withRealMetadata(self::WALLABAG_LOCK, function (CommandTester $tester): void {
            $code = $tester->execute(['--format' => 'json', '--target-php' => '8.4']);
            self::assertSame(0, $code);
            $json = json_decode($tester->getDisplay(), true);
            self::assertIsArray($json);
            self::assertIsArray($json['lockrot']);
            self::assertIsArray($json['counts']);
            self::assertSame(1, $json['lockrot']['schema']);
            self::assertSame(19, $json['counts']['abandoned']);
        });
    }

    public function testCleanProjectExitZero(): void
    {
        chdir(__DIR__.'/../../fixtures/skeletons/laravel');
        $this->withRealMetadata(self::LARAVEL_LOCK, function (CommandTester $tester): void {
            self::assertSame(0, $tester->execute(['--fail-on' => 'silent', '--target-php' => '8.4']));
        });
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
}
