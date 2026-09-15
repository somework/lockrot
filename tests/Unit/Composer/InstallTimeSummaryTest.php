<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Composer;

use Composer\Composer;
use Composer\Config;
use Composer\DependencyResolver\Transaction;
use Composer\Factory;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\IO\BufferIO;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\RepositoryInterface;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Composer\InstallTimeSummary;
use Lockrot\Config\LockrotConfig;
use Lockrot\Data\GitHub\GitHubClient;
use Lockrot\Data\GitHub\GitHubFetchPlanner;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Deadline;
use Lockrot\Exception\InstallBlockedException;
use Lockrot\Signal\SignalSet;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\TestCase;

final class InstallTimeSummaryTest extends TestCase
{
    private const WALLABAG_LOCK = __DIR__.'/../../fixtures/apps/wallabag_wallabag/composer.lock';
    private const FIXED_NOW = '2026-09-14T00:00:00+00:00';

    /** phpzip/phpzip 2.0.8 as it appears in the wallabag lock: last release and last push 2015-11-16. */
    private const PHPZIP = [
        'name' => 'phpzip/phpzip',
        'version' => '2.0.8',
        'source' => ['type' => 'git', 'url' => 'https://github.com/Grandt/PHPZip.git', 'reference' => '936f93d656f68e29c231a39e19fd59a636fe7e47'],
        'require' => ['php' => '>=5.3.0'],
        'type' => 'library',
        'notification-url' => 'https://packagist.org/downloads/',
        'time' => '2015-11-16T16:30:51+00:00',
    ];

    /** psr/log 1.1.4 — matched by the built-in allowlist (`psr/*`), so it can never be flagged. */
    private const PSR_LOG = [
        'name' => 'psr/log',
        'version' => '1.1.4',
        'source' => ['type' => 'git', 'url' => 'https://github.com/php-fig/log.git', 'reference' => 'd49695b909c3b7628b6289db5479a1c204601f11'],
        'require' => ['php' => '>=5.3.0'],
        'type' => 'library',
        'notification-url' => 'https://packagist.org/downloads/',
        'time' => '2021-05-03T11:20:27+00:00',
    ];

    /**
     * A package nothing can be said about without repository metadata: released recently, a bounded
     * `php` constraint (so S5 never fires) and no source URL (so no GitHub round is even planned).
     * With the metadata missing it lands on `unknown`, which is *below* the flagged threshold.
     */
    private const UNCHECKABLE = [
        'name' => 'vendor/fresh',
        'version' => '1.0.0',
        'require' => ['php' => '^8.1'],
        'type' => 'library',
        'notification-url' => 'https://packagist.org/downloads/',
        'time' => '2026-08-01T00:00:00+00:00',
    ];

    private static ?FixtureRepositoryServer $server = null;
    private static ?RepositoryMetadataLoader $loader = null;

    private string $cwd;
    /** @var list<string> */
    private array $tempDirs = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        self::$server->start();
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

    /**
     * Temp project the summary reads through Factory::getComposerFile()/getLockFile(), i.e. relative
     * to the working directory; tearDown() chdirs back.
     *
     * @param array<string, mixed>       $extraLockrot merged on top of target-php 8.4
     * @param list<array<string, mixed>> $lockPackages entries for composer.lock
     */
    private function project(array $extraLockrot = [], array $lockPackages = [self::PHPZIP], bool $writeLock = true): string
    {
        $dir = $this->tempDir('lockrot-install-time-');
        file_put_contents($dir.'/composer.json', (string) json_encode([
            'name' => 'lockrot/install-time-test',
            'require' => ['phpzip/phpzip' => '2.0.8'],
            'extra' => ['lockrot' => array_merge(['target-php' => '8.4'], $extraLockrot)],
        ]));
        if ($writeLock) {
            file_put_contents($dir.'/composer.lock', (string) json_encode([
                'content-hash' => 'install-time-test',
                'packages' => $lockPackages,
                'packages-dev' => [],
            ]));
        }
        chdir($dir);

        return $dir;
    }

    /** @param array<string, mixed> $entry */
    private function loadPackage(array $entry): BasePackage
    {
        return (new ArrayLoader())->load($entry);
    }

    private function event(BufferIO $io, Transaction $transaction, bool $executeOperations = true): InstallerEvent
    {
        $server = self::$server;
        self::assertNotNull($server);
        $config = $server->config();
        $composer = new Composer();
        $composer->setConfig($config);
        $composer->setRepositoryManager(RepositoryFactory::manager($io, $config, Factory::createHttpDownloader($io, $config)));

        return new InstallerEvent(InstallerEvents::PRE_OPERATIONS_EXEC, $composer, $io, false, $executeOperations, $transaction);
    }

    /**
     * A loader that reaches no repository at all and reports every name failed with the same reason
     * — what the install-time path sees when the budget runs out before the first chunk, or when
     * every configured repository is unreachable.
     */
    private function failingLoader(string $reason): MetadataLoaderInterface
    {
        return new class ($reason) implements MetadataLoaderInterface {
            private string $reason;

            public function __construct(string $reason)
            {
                $this->reason = $reason;
            }

            public function load(array $names): MetadataBatch
            {
                return new MetadataBatch([], [], array_fill_keys($names, $this->reason));
            }
        };
    }

    /**
     * The same shape LockrotCommandTest::command() uses: the class-level fixture-server loader for
     * repository metadata and recorded GitHub envelopes, so no test in this class touches the network.
     *
     * @return callable(IOInterface, Config, list<RepositoryInterface>, LockrotConfig, ?string, Clock, Deadline): Analyzer
     */
    private function analyzerFactory(?MetadataLoaderInterface $loader = null): callable
    {
        if ($loader === null) {
            $loader = self::$loader;
            self::assertNotNull($loader);
        }

        return static function (IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, ?string $token, Clock $clock, Deadline $deadline) use ($loader): Analyzer {
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
    }

    public function testAFlaggedTransactionPackagePrintsTheCompactBlock(): void
    {
        $this->project();
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        $output = $io->getOutput();
        self::assertStringContainsString('lockrot: dependency rot in 1 of 1 changed package', $output);
        self::assertStringContainsString('phpzip/phpzip 2.0.8', $output);
        self::assertStringContainsString('Run composer lockrot for details.', $output);
        self::assertLessThanOrEqual(10, \count(array_filter(explode("\n", trim($output)))), $output);
    }

    public function testAPackageWithNothingToFlagPrintsNothing(): void
    {
        $this->project([], [self::PSR_LOG]);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PSR_LOG)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        self::assertSame('', $io->getOutput());
    }

    /**
     * A package whose metadata was never fetched is `unknown`, which is below the flagged threshold.
     * Silence would read as a clean install, so the summary says what could not be checked instead.
     */
    public function testAnExhaustedBudgetPrintsWhatCouldNotBeCheckedRatherThanNothing(): void
    {
        $this->project([], [self::UNCHECKABLE]);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::UNCHECKABLE)]));
        $factory = $this->analyzerFactory($this->failingLoader(MetadataLoaderInterface::BUDGET_REASON));

        (new InstallTimeSummary($factory))->onPreOperationsExec($event);

        $output = $io->getOutput();
        self::assertStringContainsString('lockrot: 1 of 1 changed package could not be checked', $output);
        self::assertStringContainsString('not checked: install-time budget exhausted', $output);
        self::assertStringContainsString('Run composer lockrot for details.', $output);
    }

    public function testLockrotDisableSilencesTheSummaryCompletely(): void
    {
        $this->project();
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        putenv('LOCKROT_DISABLE=1');
        try {
            (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);
        } finally {
            putenv('LOCKROT_DISABLE');
        }

        self::assertSame('', $io->getOutput());
    }

    /** LOCKROT_DISABLE promises to silence all of lockrot — including the "check skipped" line a malformed extra.lockrot would otherwise print on every install. */
    public function testLockrotDisableAlsoSilencesAMalformedConfig(): void
    {
        $this->project(['fail-on' => 'dead']);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        putenv('LOCKROT_DISABLE=1');
        try {
            (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);
        } finally {
            putenv('LOCKROT_DISABLE');
        }

        self::assertSame('', $io->getOutput());
    }

    public function testInstallTimeOffSilencesTheSummaryCompletely(): void
    {
        $this->project(['install-time' => 'off']);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        self::assertSame('', $io->getOutput());
    }

    public function testStrictModePrintsTheBlockAndThenBlocksTheInstall(): void
    {
        $this->project(['install-time-strict' => true, 'fail-on' => 'old-promise']);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        $thrown = null;
        try {
            (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);
        } catch (InstallBlockedException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(InstallBlockedException::class, $thrown);
        self::assertStringContainsString('install-time-strict', $thrown->getMessage());
        self::assertStringContainsString('fail-on=old-promise', $thrown->getMessage());
        self::assertStringContainsString('lockrot: dependency rot in 1 of 1 changed package', $io->getOutput());
    }

    public function testStrictModeDoesNotBlockADryRunThatExecutesNoOperations(): void
    {
        $this->project(['install-time-strict' => true, 'fail-on' => 'old-promise']);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]), false);

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        self::assertStringContainsString('lockrot: dependency rot in 1 of 1 changed package', $io->getOutput());
    }

    public function testAnUnexpectedFailureBecomesOneWarningLineAndNeverBreaksTheInstall(): void
    {
        $this->project();
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));
        $factory = static function (): Analyzer {
            throw new \RuntimeException('boom');
        };

        (new InstallTimeSummary($factory))->onPreOperationsExec($event);

        self::assertStringContainsString('lockrot: install-time check skipped: boom', $io->getOutput());
    }

    public function testAnUninstallOnlyTransactionNeverBuildsAnAnalyzer(): void
    {
        $this->project();
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([$this->loadPackage(self::PHPZIP)], []));
        $called = false;
        $factory = static function () use (&$called): Analyzer {
            $called = true;

            throw new \LogicException('the analyzer must not be built for an uninstall-only transaction');
        };

        (new InstallTimeSummary($factory))->onPreOperationsExec($event);

        self::assertFalse($called);
        self::assertSame('', $io->getOutput());
    }

    public function testAMalformedLockrotConfigIsReportedAsASkippedCheck(): void
    {
        $this->project(['fail-on' => 'dead']);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        $output = $io->getOutput();
        self::assertStringContainsString('lockrot: install-time check skipped:', $output);
        self::assertStringContainsString('extra.lockrot is invalid', $output);
    }

    public function testWithoutALockFileTheTransactionItselfIsTheChainSource(): void
    {
        $this->project([], [], false);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        self::assertStringContainsString('phpzip/phpzip', $io->getOutput());
    }

    public function testInstallTimeBudgetComesFromConfig(): void
    {
        $this->project(['install-time-budget' => 1]);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        $deadline = $this->recordDeadline($event);

        self::assertNotNull($deadline);
        self::assertGreaterThan(0.0, $deadline->remainingSeconds());
        self::assertLessThanOrEqual(1.0, $deadline->remainingSeconds());
    }

    public function testInstallTimeBudgetDefaultsToFiveSeconds(): void
    {
        $this->project();
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        $deadline = $this->recordDeadline($event);

        self::assertNotNull($deadline);
        self::assertGreaterThan(4.0, $deadline->remainingSeconds());
        self::assertLessThanOrEqual(5.0, $deadline->remainingSeconds());
    }

    /** Set by {@see recordingAnalyzerFactory()} while {@see recordDeadline()} runs. */
    private ?Deadline $recordedDeadline = null;

    /** Runs the summary with a factory that records the {@see Deadline} it is handed, then returns it. */
    private function recordDeadline(InstallerEvent $event): ?Deadline
    {
        (new InstallTimeSummary(\Closure::fromCallable([$this, 'recordingAnalyzerFactory'])))->onPreOperationsExec($event);

        return $this->recordedDeadline;
    }

    /** @param list<RepositoryInterface> $repositories */
    private function recordingAnalyzerFactory(IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, ?string $token, Clock $clock, Deadline $deadline): Analyzer
    {
        $this->recordedDeadline = $deadline;

        return ($this->analyzerFactory())($io, $config, $repositories, $lockrot, $token, $clock, $deadline);
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
}
