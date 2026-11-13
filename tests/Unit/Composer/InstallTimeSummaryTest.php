<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Composer;

use Composer\Composer;
use Composer\Config;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Transaction;
use Composer\Factory;
use Composer\Installer\InstallationManager;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\IO\BufferIO;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Package\Locker;
use Composer\Package\RootPackage;
use Composer\Package\RootPackageInterface;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\RepositoryInterface;
use Composer\Util\Loop;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Composer\InstallTimeSummary;
use Lockrot\Config\Gate;
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
use Lockrot\Deadline;
use Lockrot\Exception\InstallBlockedException;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\SignalSet;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveCheckers;
use Lockrot\Tests\Support\BaselineEntries;
use Lockrot\Tests\Support\ColdConfigSchema;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\HermeticComposerConfig;
use Lockrot\Tests\Support\MarkupRefusingFormatter;
use Lockrot\Tests\Support\RecordingComposerRepository;
use Lockrot\Tests\Support\RecordingIO;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\ScoreModel;
use Lockrot\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

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

    /**
     * grandt/binstring 1.0.0 as it appears in the wallabag lock: last release 2015-08-13, and its
     * GitHub repository is recorded in tests/fixtures/http/github. Same `silent` verdict as
     * {@see self::PHPZIP}, and its name sorts *before* it — so the two rows can only be told apart
     * by the priority, which
     * {@see self::testADevRequirementInTheTransactionIsRankedBelowAnEqualProdRequirement()} pins.
     */
    private const BINSTRING = [
        'name' => 'grandt/binstring',
        'version' => '1.0.0',
        'source' => ['type' => 'git', 'url' => 'https://github.com/Grandt/PHPBinString.git', 'reference' => '825fe2ac8a68190f651fc2dbc07b6edde18bc431'],
        'require' => ['php' => '>=5.0'],
        'type' => 'library',
        'notification-url' => 'https://packagist.org/downloads/',
        'time' => '2015-08-13T06:14:41+00:00',
    ];

    /**
     * {@see self::PHPZIP} with its `source` removed, so {@see \Lockrot\Data\Forge\RepoLocator} finds
     * no repository to ask about and no activity round is planned. Everything the verdict needs
     * still comes from the fixture server's metadata, which makes this the one package a test can
     * hand to the real {@see \Lockrot\Composer\ServiceFactory} without reaching GitHub.
     */
    private const PHPZIP_WITHOUT_SOURCE = [
        'name' => 'phpzip/phpzip',
        'version' => '2.0.8',
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
     * A package nothing can be said about without repository metadata: released 2026-08-01, inside every threshold, a bounded
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
     * Temp project the summary reads through Factory::getComposerFile()/getLockFile(), relative
     * to the working directory. tearDown() chdirs back.
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

    /**
     * A project that requires one package for production and one for development, with both already
     * in their own section of the lock — the state Composer leaves behind after `composer require
     * --dev`, which writes the new lock before dispatching PRE_OPERATIONS_EXEC.
     *
     * @param array<string, mixed> $prodPackage  entry for `require` and `packages`
     * @param array<string, mixed> $devPackage   entry for `require-dev` and `packages-dev`
     * @param array<string, mixed> $extraLockrot merged on top of target-php 8.4
     */
    private function projectWithDevRequire(array $prodPackage, array $devPackage, array $extraLockrot = []): string
    {
        $dir = $this->tempDir('lockrot-install-time-dev-');
        [$prodName, $prodVersion] = self::nameAndVersionOf($prodPackage);
        [$devName, $devVersion] = self::nameAndVersionOf($devPackage);
        file_put_contents($dir.'/composer.json', (string) json_encode([
            'name' => 'lockrot/install-time-dev-test',
            'require' => [$prodName => $prodVersion],
            'require-dev' => [$devName => $devVersion],
            'extra' => ['lockrot' => array_merge(['target-php' => '8.4'], $extraLockrot)],
        ]));
        file_put_contents($dir.'/composer.lock', (string) json_encode([
            'content-hash' => 'install-time-dev-test',
            'packages' => [$prodPackage],
            'packages-dev' => [$devPackage],
        ]));
        chdir($dir);

        return $dir;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array{0: string, 1: string} name, version
     */
    private static function nameAndVersionOf(array $entry): array
    {
        $name = $entry['name'] ?? null;
        $version = $entry['version'] ?? null;
        if (!\is_string($name) || !\is_string($version)) {
            throw new \InvalidArgumentException('a lock entry needs a string name and version');
        }

        return [$name, $version];
    }

    /** @param array<string, mixed> $entry */
    private function loadPackage(array $entry): BasePackage
    {
        return (new ArrayLoader())->load($entry);
    }

    private function event(BufferIO $io, Transaction $transaction, bool $executeOperations = true, bool $devMode = false, ?Locker $locker = null, ?RootPackageInterface $root = null): InstallerEvent
    {
        $server = self::$server;
        self::assertNotNull($server);
        $config = $server->config();
        $composer = new Composer();
        $composer->setConfig($config);
        $composer->setPackage($root ?? new RootPackage('__root__', '1.0.0.0', '1.0.0'));
        $composer->setRepositoryManager(RepositoryFactory::manager($io, $config, Factory::createHttpDownloader($io, $config)));
        $composer->setLocker($locker ?? self::locker($io, $config));

        return new InstallerEvent(InstallerEvents::PRE_OPERATIONS_EXEC, $composer, $io, $devMode, $executeOperations, $transaction);
    }

    /** The Locker that Composer's Factory builds for the project in the working directory. */
    private static function locker(IOInterface $io, Config $config): Locker
    {
        $composerFile = Factory::getComposerFile();

        return new Locker($io, new JsonFile(Factory::getLockFile($composerFile)), new InstallationManager(new Loop(Factory::createHttpDownloader($io, $config)), $io), (string) file_get_contents($composerFile));
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

            public function load(array $installedByName): MetadataBatch
            {
                return new MetadataBatch([], [], array_fill_keys(array_keys($installedByName), $this->reason));
            }
        };
    }

    /**
     * The same shape LockrotCommandTest::command() uses: the class-level fixture-server loader for
     * repository metadata and recorded GitHub envelopes, so no test in this class touches the network.
     *
     * @return callable(IOInterface, Config, list<RepositoryInterface>, LockrotConfig, Tokens, Clock, Deadline): Analyzer
     */
    private function analyzerFactory(?MetadataLoaderInterface $loader = null): callable
    {
        if ($loader === null) {
            $loader = self::$loader;
            self::assertNotNull($loader);
        }

        return static function (IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, Tokens $tokens, Clock $clock, Deadline $deadline) use ($loader): Analyzer {
            $auth = ForgeAuth::withTokens(new Tokens('recorded', null));

            return new Analyzer(
                $loader,
                new ActivityClient(new RecordedHttpClient(__DIR__.'/../../fixtures/http/github'), $auth),
                new ActivityFetchPlanner($auth),
                new RepoLocator(),
                BuiltinAllowlist::load(),
                SignalSet::default($clock, $lockrot->thresholds(), $lockrot->targetPhp(), PhpReleaseDates::load()),
                $clock,
                $lockrot->offline()
            );
        };
    }

    /** The default target is `config.platform.php` of Composer's merged config, the global `config.json` included. */
    public function testTheDefaultTargetReadsThePlatformPhpOfTheGlobalComposerConfig(): void
    {
        $server = self::$server;
        self::assertNotNull($server);
        $dir = $this->project();
        file_put_contents($dir.'/composer.json', '{"name": "lockrot/install-time-test", "require": {"phpzip/phpzip": "2.0.8"}}');
        $home = $this->tempDir('lockrot-install-time-home-');
        file_put_contents($home.'/config.json', '{"config": {"platform": {"php": "8.1.5"}}}');
        $io = new BufferIO();
        $config = HermeticComposerConfig::create($home);
        $config->merge(['repositories' => ['packagist.org' => false, 'fixture' => ['type' => 'composer', 'url' => $server->url()]]]);
        $composer = new Composer();
        $composer->setConfig($config);
        $composer->setPackage(new RootPackage('__root__', '1.0.0.0', '1.0.0'));
        $composer->setRepositoryManager(RepositoryFactory::manager($io, $config, Factory::createHttpDownloader($io, $config)));
        $composer->setLocker(self::locker($io, $config));

        (new InstallTimeSummary(\Closure::fromCallable([$this, 'targetRecordingAnalyzerFactory'])))->onPreOperationsExec(new InstallerEvent(InstallerEvents::PRE_OPERATIONS_EXEC, $composer, $io, false, true, new Transaction([], [$this->loadPackage(self::PHPZIP)])));

        self::assertSame(['8.1'], $this->targetsAtHandOver);
    }

    public function testAFlaggedTransactionPackagePrintsTheCompactBlock(): void
    {
        $this->project();
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        $output = $io->getOutput();
        self::assertStringContainsString('lockrot: 1 of 1 changed package flagged', $output);
        self::assertStringContainsString('phpzip/phpzip 2.0.8', $output);
        self::assertStringContainsString('Run composer lockrot for details.', $output);
        self::assertLessThanOrEqual(10, \count(array_filter(explode("\n", trim($output)))), $output);
    }

    /**
     * The manifest's `repositories` is read on every install, for where each entry came from. A
     * block lockrot cannot read, or one that Composer refuses, changes nothing the summary prints.
     */
    public function testARepositoriesBlockLockrotCannotReadChangesNothingAtInstallTime(): void
    {
        $unreadable = [
            'https://github.com/acme/lib',
            ['url' => 'https://github.com/acme/lib'],
            ['type' => ['vcs']],
            ['type' => 'vcs', 'url' => ['https://github.com/acme/lib']],
            ['type' => 'vcs', 'url' => 'https://github.com/acme/lib', 'only' => 'acme/*'],
            ['type' => 'vcs', 'url' => 'https://github.com/acme/lib', 'only' => ['acme/*'], 'exclude' => ['other/*']],
            ['type' => 'vcs', 'url' => 'https://github.com/acme/lib', 'only' => [7]],
            ['packagist.org' => false],
            ['type' => 'package', 'package' => 'acme/lib'],
        ];
        $outputs = [];
        foreach ([null, $unreadable, 'not a list'] as $repositories) {
            $dir = $this->project();
            if ($repositories !== null) {
                $manifest = json_decode((string) file_get_contents($dir.'/composer.json'), true);
                self::assertIsArray($manifest);
                file_put_contents($dir.'/composer.json', (string) json_encode($manifest + ['repositories' => $repositories]));
            }
            $io = new BufferIO();
            (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)])));
            $outputs[] = $io->getOutput();
        }

        self::assertStringContainsString('phpzip/phpzip 2.0.8', $outputs[0]);
        self::assertSame($outputs[0], $outputs[1]);
        self::assertSame($outputs[0], $outputs[2]);
    }

    /**
     * A Composer transaction carries no `require-dev` membership, so every package in it is prod
     * until the lock is consulted. Both rows are `silent` root requires, so only the priority
     * orders them: grandt/binstring is a dev requirement, so it drops from `critical` to `high` and
     * sits below phpzip/phpzip, although its name sorts first. Read the wrong way round, the dev row
     * comes first on the name tie-break.
     */
    public function testADevRequirementInTheTransactionIsRankedBelowAnEqualProdRequirement(): void
    {
        $this->projectWithDevRequire(self::PHPZIP, self::BINSTRING, ['include-dev' => true]);
        $io = new BufferIO();
        $transaction = new Transaction([], [$this->loadPackage(self::BINSTRING), $this->loadPackage(self::PHPZIP)]);
        $event = $this->event($io, $transaction, true, true);

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        $output = $io->getOutput();
        $prod = strpos($output, 'phpzip/phpzip');
        $dev = strpos($output, 'grandt/binstring');
        self::assertIsInt($prod, $output);
        self::assertIsInt($dev, $output);
        self::assertLessThan($dev, $prod, 'the prod requirement outranks the equally flagged dev one:'."\n".$output);
    }

    /**
     * A dev-mode install brings in packages-dev, and `composer lockrot` reads them only with
     * include-dev. The install-time gate decides as `composer lockrot` does, so a strict install
     * does not fail on a package that a baseline written without --dev never saw.
     */
    public function testWithoutIncludeDevADevModeInstallLeavesPackagesDevOut(): void
    {
        $this->projectWithDevRequire(self::PHPZIP, self::BINSTRING, ['fail-on' => 'high', 'install-time-strict' => true]);
        $io = new BufferIO();
        $transaction = new Transaction([], [$this->loadPackage(self::BINSTRING)]);

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($this->event($io, $transaction, true, true));

        self::assertSame('', $io->getOutput());
    }

    public function testATransactionOfOnlyPackagesDevWithoutIncludeDevBuildsNoAnalyzer(): void
    {
        $this->projectWithDevRequire(self::PHPZIP, self::BINSTRING);
        $io = new BufferIO();
        $called = false;
        $recording = static function () use (&$called): Analyzer {
            $called = true;

            throw new \LogicException('the run analyses no package');
        };

        (new InstallTimeSummary($recording))->onPreOperationsExec($this->event($io, new Transaction([], [$this->loadPackage(self::BINSTRING)]), true, true));

        self::assertFalse($called);
        self::assertSame('', $io->getOutput());
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
     * Silence reads as a clean install, so the summary says what could not be checked instead.
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

    /** LOCKROT_DISABLE promises to silence all of lockrot — including the "check skipped" line a malformed extra.lockrot otherwise prints on every install. */
    public function testLockrotDisableAlsoSilencesAMalformedConfig(): void
    {
        $this->project(['include-dev' => 'yes']);
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
        self::assertStringStartsWith('lockrot: install-time-strict stopped the install; gate: fails · --fail-on=old-promise (extra.lockrot.fail-on) · 1 finding fails it · target php ', $thrown->getMessage());
        self::assertStringEndsWith('; run composer lockrot for details', $thrown->getMessage());
        self::assertStringContainsString('lockrot: 1 of 1 changed package flagged', $io->getOutput());
    }

    /**
     * The block is the gate a `composer lockrot` run is decided by, in check mode, over the report
     * the transaction gives: under every fail-on value it blocks exactly where that gate fails.
     */
    public function testStrictModeBlocksExactlyWhereTheGateFails(): void
    {
        $server = self::$server;
        self::assertNotNull($server);
        $outcomes = [];
        foreach (FailOn::allowed() as $value) {
            $dir = $this->project(['install-time-strict' => true, 'fail-on' => $value]);
            $project = ProjectConfig::fromFile($dir.'/composer.json');
            $lock = LockFile::fromFile($dir.'/composer.lock');
            $lockrot = LockrotConfig::fromSources($project->lockrotExtra(), [], [], \PHP_VERSION, $project->platformPhp());
            $analyzer = ($this->analyzerFactory())(new BufferIO(), $server->config(), [], $lockrot, new Tokens('recorded', null), Clock::fixed(self::FIXED_NOW), Deadline::inSeconds(60.0));
            $report = $analyzer->analyzePackages($lock->packages(false), $lock, $project, false);
            $fails = Gate::decide($report, FailOn::parse($value), false, Gate::MODE_CHECK)->fails();

            $blocked = false;
            try {
                (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($this->event(new BufferIO(), new Transaction([], [$this->loadPackage(self::PHPZIP)])));
            } catch (InstallBlockedException $e) {
                $blocked = true;
            }

            self::assertSame($fails, $blocked, 'fail-on='.$value);
            $outcomes[$blocked ? 'blocked' : 'passed'] = true;
        }

        self::assertArrayHasKey('blocked', $outcomes, 'some fail-on value blocks the install');
        self::assertArrayHasKey('passed', $outcomes, 'some fail-on value lets it through');
    }

    public function testStrictModeDoesNotBlockADryRunThatExecutesNoOperations(): void
    {
        $this->project(['install-time-strict' => true, 'fail-on' => 'old-promise']);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]), false);

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        self::assertStringContainsString('lockrot: 1 of 1 changed package flagged', $io->getOutput());
    }

    /**
     * install-time-strict is a gate on what the project has not already accepted: a finding the
     * baseline carries must not stop the transaction, while the compact block itself still lists it.
     */
    public function testStrictModeDoesNotBlockAFindingTheBaselineAlreadyCarries(): void
    {
        $dir = $this->project(['install-time-strict' => true, 'fail-on' => 'old-promise']);
        $this->writeBaseline($dir.'/lockrot-baseline.json', ['phpzip/phpzip' => 'silent']);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        self::assertStringContainsString('lockrot: 1 of 1 changed package flagged', $io->getOutput());
    }

    /** No PHP that runs the tests is 7.3, so the baseline matches only the configured target. */
    public function testTheBaselineIsComparedAgainstTheConfiguredTargetPhp(): void
    {
        $dir = $this->project(['target-php' => '7.3']);
        $this->writeBaseline($dir.'/lockrot-baseline.json', ['phpzip/phpzip' => 'silent'], '7.3');
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        self::assertStringContainsString('lockrot: 1 of 1 changed package flagged', $io->getOutput());
        self::assertStringNotContainsString('the baseline was written for', $io->getOutput());
    }

    public function testStrictModeStillBlocksAFindingWorseThanTheBaselinedOne(): void
    {
        $dir = $this->project(['install-time-strict' => true, 'fail-on' => 'old-promise']);
        $this->writeBaseline($dir.'/lockrot-baseline.json', ['phpzip/phpzip' => 'stale']);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        $thrown = null;
        try {
            (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);
        } catch (InstallBlockedException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(InstallBlockedException::class, $thrown);
    }

    public function testAConfiguredBaselinePathIsHonouredAtInstallTime(): void
    {
        $dir = $this->project(['install-time-strict' => true, 'fail-on' => 'old-promise', 'baseline' => 'ci/rot.json']);
        mkdir($dir.'/ci');
        $this->writeBaseline($dir.'/ci/rot.json', ['phpzip/phpzip' => 'silent']);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        self::assertStringContainsString('lockrot: 1 of 1 changed package flagged', $io->getOutput());
    }

    /**
     * A configured baseline path that does not exist is exit 2 for `composer lockrot`, but install
     * time never fails on a configuration problem: it becomes the one "check skipped" line, the
     * install continues, and the install-time-strict gate does not run for that install.
     */
    public function testAConfiguredButMissingBaselineIsReportedAsASkippedCheckAndDoesNotBlock(): void
    {
        $this->project(['install-time-strict' => true, 'fail-on' => 'old-promise', 'baseline' => 'ci/rot.json']);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        $output = $io->getOutput();
        self::assertStringContainsString('lockrot: install-time check skipped:', $output);
        self::assertStringContainsString('ci/rot.json not found', $output);
    }

    public function testTheSkippedLineSurvivesQuietMode(): void
    {
        // Under install-time-strict, `composer install -q` must still say that the gate did not run.
        $this->project(['install-time-strict' => true, 'fail-on' => 'old-promise', 'baseline' => 'ci/rot.json']);
        $io = new BufferIO('', OutputInterface::VERBOSITY_QUIET);
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        self::assertStringContainsString('lockrot: install-time check skipped:', $io->getOutput());
    }

    public function testAMalformedBaselineIsReportedAsASkippedCheckAndNeverBreaksTheInstall(): void
    {
        $dir = $this->project();
        file_put_contents($dir.'/lockrot-baseline.json', '{"findings": ');
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        $output = $io->getOutput();
        self::assertStringContainsString('lockrot: install-time check skipped:', $output);
        self::assertStringContainsString('is not valid JSON', $output);
    }

    /**
     * SPEC-0.14.md §6.4 "Reading" (O10): at install time a baseline-1 file is an unreadable
     * baseline. The plugin prints the skip line with the refusal, the install continues, and
     * install-time-strict does not block on it.
     */
    public function testABaseline1FileSkipsTheCheckAndNeverBlocksAStrictInstall(): void
    {
        $dir = $this->project(['install-time-strict' => true, 'fail-on' => 'old-promise']);
        file_put_contents($dir.'/lockrot-baseline.json', '{"lockrot": {"version": "0.13.0", "schema": 1}, "findings": {"phpzip/phpzip": {"version": "1.0.0", "verdict": "silent", "first_seen": "2025-01-01"}}}');
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));
        [$major, $minor] = explode('.', Version::STRING);

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        self::assertStringContainsString('lockrot: install-time check skipped: lockrot '.$major.'.'.$minor.' reads baseline-2 only, and lockrot-baseline.json is a baseline-1 file.', $io->getOutput());
    }

    /**
     * A file written under another score model has grades that this lockrot cannot compare, so the
     * install-time check skips with the refusal and the strict gate never blocks on it.
     */
    public function testABaselineOfAnotherScoreModelSkipsTheCheckAndNeverBlocksAStrictInstall(): void
    {
        $dir = $this->project(['install-time-strict' => true, 'fail-on' => 'old-promise']);
        $this->writeBaseline($dir.'/lockrot-baseline.json', ['phpzip/phpzip' => 'stale'], '8.4', ScoreModel::ID + 1);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        self::assertStringContainsString('lockrot: install-time check skipped: lockrot-baseline.json was written under score model '.(ScoreModel::ID + 1).' and this lockrot uses score model '.ScoreModel::ID.'.', $io->getOutput());
    }

    /** @param array<string, string> $verdicts package => verdict */
    private function writeBaseline(string $path, array $verdicts, string $targetPhp = '8.4', int $scoreModelId = ScoreModel::ID): void
    {
        $findings = [];
        foreach ($verdicts as $package => $verdict) {
            $findings[$package] = BaselineEntries::of($package, '2.0.8', $verdict, '2026-01-15');
        }
        file_put_contents($path, (string) json_encode([
            '$schema' => 'https://lockrot.dev/schema/baseline-2.json',
            'lockrot' => ['version' => '0.14.0', 'schema' => 2],
            'generated_at' => self::FIXED_NOW,
            'target_php' => $targetPhp,
            'target_php_source' => 'option',
            'score_model_id' => $scoreModelId,
            'fix_model' => 1,
            'findings' => $findings,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
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

    public function testAFailureMessageThatLooksLikeAConsoleTagIsPrintedAsGiven(): void
    {
        $this->project();
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));
        $factory = static function (): Analyzer {
            throw new \RuntimeException('cannot open <info>here</info>');
        };

        (new InstallTimeSummary($factory))->onPreOperationsExec($event);

        self::assertStringContainsString('lockrot: install-time check skipped: cannot open <info>here</info>', $io->getOutput());
    }

    /**
     * A multi-line exception message (a wrapped exception's chain, a library's own multi-line error)
     * must not split the one promised warning line into several, and the collapsing must not leave
     * the indentation of the lines it folded in behind either — the message starts where the label
     * ends.
     */
    public function testAMultilineExceptionMessageStaysOnOneWarningLine(): void
    {
        $this->project();
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));
        $factory = static function (): Analyzer {
            throw new \RuntimeException("\n  boom\nsecond line\n  third line  ");
        };

        (new InstallTimeSummary($factory))->onPreOperationsExec($event);

        $output = $io->getOutput();
        // The newline pins the far end: collapsed whitespace must not survive as a trailing space,
        // and `skipped: boom` pins the near end against a leading one.
        self::assertStringContainsString("lockrot: install-time check skipped: boom second line third line\n", $output);
        self::assertCount(1, array_filter(explode("\n", trim($output))), $output);
    }

    /**
     * The skipped-check line is in the `warning` colours from end to end: a message that falls
     * outside them prints as plain text in the middle of an install. It is written raw — it can
     * quote the project's own keys — so the colour is lockrot's, and so is the job that Composer's
     * sanitising does: nothing in the message is left for the terminal to obey.
     */
    public function testTheSkippedCheckLineIsColouredWholeAndCarriesNoControlCharacters(): void
    {
        $this->project();
        $io = new BufferIO('', StreamOutput::VERBOSITY_NORMAL, new OutputFormatter(true));
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));
        $factory = static function (): Analyzer {
            throw new \RuntimeException("boom \033[31mred\u{202E} C:\\path");
        };

        (new InstallTimeSummary($factory))->onPreOperationsExec($event);

        self::assertSame("\033[30;43mlockrot: install-time check skipped: boom \\x1B[31mred\\u{202E} C:\\path\033[39;49m\n", $io->getOutput());
    }

    /**
     * The plugin's own default wiring: constructed the way {@see \Lockrot\Composer\LockrotPlugin}
     * constructs it, with no analyzer factory, the summary must build one through
     * {@see \Lockrot\Composer\ServiceFactory::createAnalyzer()} and print a real block — not fall
     * into the "check skipped" line because the default is not callable.
     * {@see self::PHPZIP_WITHOUT_SOURCE} has no `source` URL, so no repository activity round is
     * planned and nothing reaches GitHub.
     */
    public function testTheDefaultAnalyzerFactoryBuildsAWorkingAnalyzer(): void
    {
        $this->project([], [self::PHPZIP_WITHOUT_SOURCE]);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP_WITHOUT_SOURCE)]));

        (new InstallTimeSummary())->onPreOperationsExec($event);

        $output = $io->getOutput();
        self::assertStringNotContainsString('install-time check skipped', $output);
        self::assertStringContainsString('lockrot: 1 of 1 changed package flagged', $output);
        self::assertStringContainsString('phpzip/phpzip 2.0.8', $output);
    }

    /** `install-tme: off` is the typo nobody understands from the install: the block keeps printing. */
    public function testAnUnknownKeyIsWarnedAboutAboveTheBlock(): void
    {
        $this->project(['install-tme' => 'off']);
        $io = new RecordingIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        self::assertGreaterThan(1, \count($io->errors), implode("\n", $io->errors));
        self::assertSame('lockrot: unknown key extra.lockrot.install-tme ignored (did you mean install-time?)', $io->errors[0]);
        self::assertStringContainsString('lockrot: 1 of 1 changed package flagged', $io->errors[1]);
    }

    public function testAnUnknownKeyCannotHoldAWorkflowCommand(): void
    {
        $this->project(['##[error]forged' => 1]);
        $io = new RecordingIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        self::assertSame('lockrot: unknown key extra.lockrot.##\\x5Berror]forged ignored', $io->errors[0]);
    }

    public function testASkippedCheckLineCannotHoldAWorkflowCommandAlsoUnderQuiet(): void
    {
        $this->project();
        $io = new BufferIO('', OutputInterface::VERBOSITY_QUIET);
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));
        $factory = static function (): Analyzer {
            throw new \RuntimeException('##[error]forged');
        };

        (new InstallTimeSummary($factory))->onPreOperationsExec($event);

        self::assertStringContainsString('lockrot: install-time check skipped: ##\\x5Berror]forged', $io->getOutput());
        self::assertStringNotContainsString('##[', $io->getOutput());
    }

    /** The warning is about the config, not about what the transaction found: a clean one still says it. */
    public function testAnUnknownKeyIsWarnedAboutWhenNothingIsFlagged(): void
    {
        $this->project(['slack-webhook' => 'https://example.com'], [self::PSR_LOG]);
        $io = new RecordingIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PSR_LOG)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        self::assertSame('lockrot: unknown key extra.lockrot.slack-webhook ignored', $io->onlyError());
    }

    /**
     * The key is the project's text. The line never reaches a console formatter, so a formatter
     * that chokes on the key cannot skip the install-time-strict block.
     */
    public function testAKeyThatLooksLikeMarkupNeverReachesTheFormatter(): void
    {
        $this->project(['<<fg=red>>' => 1, 'install-time-strict' => true, 'fail-on' => 'silent']);
        $io = new BufferIO('', StreamOutput::VERBOSITY_NORMAL, new MarkupRefusingFormatter('<<'));
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        $thrown = null;
        try {
            (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);
        } catch (InstallBlockedException $e) {
            $thrown = $e;
        }

        $output = $io->getOutput();
        self::assertInstanceOf(InstallBlockedException::class, $thrown, $output);
        self::assertStringStartsWith("lockrot: unknown key extra.lockrot.<<fg=red>> ignored\n", $output);
        self::assertStringNotContainsString('check skipped', $output);
    }

    /**
     * The block quotes the lock — names, versions, evidence, chains — and is written past Composer's
     * formatter, rendered by lockrot: a formatter that refuses the block is not even asked, and the
     * block is printed rather than turned into "check skipped".
     */
    public function testTheBlockNeverReachesTheFormatter(): void
    {
        $this->project();
        $io = new BufferIO('', StreamOutput::VERBOSITY_NORMAL, new MarkupRefusingFormatter('phpzip/phpzip'));
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        $output = $io->getOutput();
        self::assertStringStartsWith("lockrot: 1 of 1 changed package flagged — critical 1\n", $output);
        self::assertStringContainsString("\n  critical [36] phpzip/phpzip 2.0.8: silent · old-promise — ", $output);
        self::assertStringNotContainsString('check skipped', $output);
    }

    /** Decorated, the block has colours applied by lockrot: the header in Composer's `warning`, the grade in its colour. */
    public function testTheBlockIsColouredWhenTheOutputIsDecorated(): void
    {
        $this->project();
        $io = new BufferIO('', StreamOutput::VERBOSITY_NORMAL, new OutputFormatter(true));
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        $output = $io->getOutput();
        self::assertStringStartsWith("\033[30;43mlockrot: 1 of 1 changed package flagged — critical 1\033[39;49m\n  \033[31mcritical\033[39m [36] phpzip/phpzip 2.0.8: ", $output);
        self::assertStringEndsWith("\nRun composer lockrot for details.\n", $output);
    }

    /** Written raw, the line is coloured by lockrot itself, in Composer's `warning` style, when the output is decorated. */
    public function testTheWarningIsColouredWhenTheOutputIsDecorated(): void
    {
        $this->project(['<b>' => 1], [self::PSR_LOG]);
        $io = new BufferIO('', StreamOutput::VERBOSITY_NORMAL, new OutputFormatter(true));
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PSR_LOG)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        self::assertSame("\033[30;43mlockrot: unknown key extra.lockrot.<b> ignored\033[39;49m\n", $io->getOutput());
    }

    public function testInstallTimeOffStaysSilentDespiteAnUnknownKey(): void
    {
        $this->project(['install-time' => 'off', 'slack-webhook' => 'https://example.com']);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        self::assertSame('', $io->getOutput());
    }

    public function testLockrotDisableSilencesTheUnknownKeyWarning(): void
    {
        $this->project(['install-tme' => 'off']);
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

    /** `composer remove` and a no-op install print no block, and no warning either. */
    public function testAnUninstallOnlyTransactionPrintsNoUnknownKeyWarning(): void
    {
        $this->project(['install-tme' => 'off']);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([$this->loadPackage(self::PHPZIP)], []));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        self::assertSame('', $io->getOutput());
    }

    /**
     * Every transaction warns about the config it read. A process can run several — bamarni's
     * composer-bin-plugin runs an install per manifest — and a line printed for one is no reason to
     * keep quiet about the next.
     */
    public function testEveryTransactionInAProcessWarnsAboutItsOwnConfig(): void
    {
        $this->project(['install-tme' => 'off'], [self::PSR_LOG]);
        $first = new RecordingIO();
        $second = new RecordingIO();
        $summary = new InstallTimeSummary($this->analyzerFactory());

        $summary->onPreOperationsExec($this->event($first, new Transaction([], [$this->loadPackage(self::PSR_LOG)])));
        $summary->onPreOperationsExec($this->event($second, new Transaction([], [$this->loadPackage(self::PSR_LOG)])));

        $line = 'lockrot: unknown key extra.lockrot.install-tme ignored (did you mean install-time?)';
        self::assertSame([$line], $first->errors);
        self::assertSame([$line], $second->errors);
    }

    /**
     * A config the schema rejects still gets its suggestion, inside the one skipped-check line, and
     * that line is raw too: the suggestion quotes the key.
     */
    public function testASchemaErrorNamesTheUnknownKeyInTheSkippedCheckLine(): void
    {
        $this->project(['<<fg=red>>' => 1, 'ignore' => [['package' => 'a/b', 'reasn' => 'legacy']]]);
        $io = new BufferIO('', StreamOutput::VERBOSITY_NORMAL, new MarkupRefusingFormatter('<<'));
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        $output = $io->getOutput();
        self::assertStringStartsWith('lockrot: install-time check skipped: extra.lockrot is invalid: - ignore[0].reason: ', $output);
        self::assertStringContainsString(' - unknown key extra.lockrot.<<fg=red>> ignored - unknown key extra.lockrot.ignore[0].reasn ignored (did you mean reason?)', $output);
        self::assertCount(1, array_filter(explode("\n", $output)), $output);
    }

    /**
     * The install-time path validates the configuration once per process, so that first validation
     * holds `format` to the formats lockrot writes: the same skipped-check line as any other schema
     * error, with the unknown keys in it, and not the resolver's shorter message.
     */
    public function testAnUnknownFormatOnAColdSchemaIsTheSchemaErrorInTheSkippedCheckLine(): void
    {
        $this->project(['format' => 'xml', 'install-tme' => 'off']);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));
        ColdConfigSchema::forget();

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        $output = $io->getOutput();
        self::assertStringStartsWith('lockrot: install-time check skipped: extra.lockrot is invalid: - format: Does not have a value in the enumeration ["table",', $output);
        self::assertStringContainsString(' - unknown key extra.lockrot.install-tme ignored (did you mean install-time?)', $output);
        self::assertCount(1, array_filter(explode("\n", $output)), $output);
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
        $this->project(['include-dev' => 'yes']);
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

    /**
     * With `config.lock: false`, Composer keeps the lock that the update resolved in memory only.
     * The dev package keeps its dev weight, and a stale file on disk does not decide it.
     */
    public function testWithLockFalseTheLockComesFromComposersLocker(): void
    {
        $server = self::$server;
        self::assertNotNull($server);
        $dir = $this->projectWithDevRequire(self::PHPZIP, self::BINSTRING, ['include-dev' => true]);
        file_put_contents($dir.'/composer.lock', (string) json_encode(['packages' => [self::BINSTRING], 'packages-dev' => []]));
        $io = new BufferIO();
        $locker = self::locker($io, $server->config());
        $locker->setLockData([$this->loadPackage(self::PHPZIP)], [$this->loadPackage(self::BINSTRING)], [], [], [], 'stable', [], false, false, [], false);

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($this->event($io, new Transaction([], [$this->loadPackage(self::BINSTRING)]), true, true, $locker));

        $output = $io->getOutput();
        self::assertStringContainsString('grandt/binstring 1.0.0', $output);
        self::assertStringNotContainsString('critical', $output);
    }

    /**
     * The pre-transaction lock on disk knows nothing about the transaction's own new packages or
     * requirements — the normal state during a `--dry-run`, or whenever the lock is behind the
     * transaction. LockFile::withPackages() overlays the transaction onto the chain source so that
     * a package new to the graph still resolves a "via" chain.
     */
    public function testAPreTransactionLockMissingTheTransitivePackageStillShowsTheViaChain(): void
    {
        $dir = $this->project([], [[
            'name' => 'vendor/direct-req',
            'version' => '1.0.0',
            'require' => ['php' => '>=5.3.0'],
            'type' => 'library',
            'notification-url' => 'https://packagist.org/downloads/',
            'time' => '2026-01-01T00:00:00+00:00',
        ]]);
        file_put_contents($dir.'/composer.json', (string) json_encode([
            'name' => 'lockrot/install-time-test',
            'require' => ['vendor/direct-req' => '1.1.0'],
            'extra' => ['lockrot' => ['target-php' => '8.4']],
        ]));
        $io = new BufferIO();
        $updatedDirectReq = $this->loadPackage([
            'name' => 'vendor/direct-req',
            'version' => '1.1.0',
            'require' => ['php' => '>=5.3.0', 'phpzip/phpzip' => '2.0.8'],
            'type' => 'library',
            'notification-url' => 'https://packagist.org/downloads/',
            'time' => '2026-01-01T00:00:00+00:00',
        ]);
        $event = $this->event($io, new Transaction([], [$updatedDirectReq, $this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

        $output = $io->getOutput();
        self::assertStringContainsString('  high     [18] phpzip/phpzip 2.0.8: silent · old-promise', $output, 'the chain halves its maintenance: it is transitive');
    }

    /**
     * Composer 2.10 loads a path or private repository without a name check, and the lock path
     * skips such a name. Composer 2.11 refuses it when it builds the transaction, so a stub stands in.
     */
    public function testATransactionPackageThatIsNoComposerNameIsNotAnalysed(): void
    {
        $this->project([], [], false);
        $io = new BufferIO();
        $option = $this->loadPackage(['name' => '-x/y', 'version' => '1.0.0', 'type' => 'library', 'time' => '2015-01-01T00:00:00+00:00']);
        $transaction = $this->createStub(Transaction::class);
        $transaction->method('getOperations')->willReturn([new InstallOperation($this->loadPackage(self::PHPZIP)), new InstallOperation($option)]);

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($this->event($io, $transaction));

        $output = $io->getOutput();
        self::assertStringContainsString('lockrot: 1 of 1 changed package flagged', $output, $output);
        self::assertStringContainsString('note: lock: lockrot skips 1 entry whose name is no Composer package name', $output, $output);
    }

    /**
     * `composer update foo/foo:dev-main#abc` gives the root a stability flag and a reference that
     * composer.json does not hold. A later `composer update bar/bar` reloads composer.json, and
     * Composer refuses it: the locked foo/foo dev-main is below the minimum stability.
     */
    public function testTheMoveCheckSolvesWithoutTheInlineFlagsOfAnUpdate(): void
    {
        $loader = new ArrayLoader();
        $root = $loader->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => ['foo/foo' => '*', 'bar/bar' => '^1.0']], RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $root);
        $inline = ['foo/foo' => 'dev-main#abc'];
        $root->setStabilityFlags(RootPackageLoader::extractStabilityFlags($inline, $root->getMinimumStability(), $root->getStabilityFlags()));
        $root->setReferences(RootPackageLoader::extractReferences($inline, $root->getReferences()));
        $packagist = ['notification-url' => 'https://packagist.org/downloads/'];
        $lock = LockFile::fromArray(['packages' => [['name' => 'foo/foo', 'version' => 'dev-main'] + $packagist, ['name' => 'bar/bar', 'version' => '1.0.0'] + $packagist]]);
        $remote = [$loader->load(['name' => 'foo/foo', 'version' => 'dev-main']), $loader->load(['name' => 'bar/bar', 'version' => '1.0.0']), $loader->load(['name' => 'bar/bar', 'version' => '1.1.0'])];

        $written = InstallTimeSummary::rootAsWritten($root);
        $baseline = MoveCheckers::forProject($written, $lock, ProjectConfig::empty(), new Config(false), [new RecordingComposerRepository($remote)], '8.4', false, Deadline::never())->baseline()->check();

        self::assertSame(MoveCheck::REFUSED, $baseline->result());
        self::assertSame([[], []], [$written->getStabilityFlags(), $written->getReferences()]);
        self::assertSame([['foo/foo' => BasePackage::STABILITY_DEV], ['foo/foo' => 'abc']], [$root->getStabilityFlags(), $root->getReferences()], 'the running command keeps its root');
    }

    /** The move check of the install-time block gets the root that the next command reads. */
    public function testTheInstallTimeMoveCheckSolvesWithoutTheInlineFlagsOfAnUpdate(): void
    {
        $loader = new ArrayLoader();
        $require = ['foo/foo' => '*', 'bar/bar' => '^1.0'];
        $dir = $this->project();
        file_put_contents($dir.'/composer.json', (string) json_encode(['name' => 'acme/app', 'require' => $require, 'extra' => ['lockrot' => ['target-php' => '8.4']]]));
        $packagist = ['notification-url' => 'https://packagist.org/downloads/'];
        file_put_contents($dir.'/composer.lock', (string) json_encode(['packages' => [['name' => 'foo/foo', 'version' => 'dev-main'] + $packagist, ['name' => 'bar/bar', 'version' => '1.0.0'] + $packagist], 'packages-dev' => []]));
        $root = $loader->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => $require], RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $root);
        $inline = ['foo/foo' => 'dev-main#abc'];
        $root->setStabilityFlags(RootPackageLoader::extractStabilityFlags($inline, $root->getMinimumStability(), $root->getStabilityFlags()));
        $root->setReferences(RootPackageLoader::extractReferences($inline, $root->getReferences()));
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$loader->load(['name' => 'bar/bar', 'version' => '1.0.0'])]), true, false, null, $root);
        $event->getComposer()->getRepositoryManager()->addRepository(new RecordingComposerRepository([$loader->load(['name' => 'foo/foo', 'version' => 'dev-main']), $loader->load(['name' => 'bar/bar', 'version' => '1.0.0']), $loader->load(['name' => 'bar/bar', 'version' => '1.1.0'])]));
        $checks = null;
        $factory = $this->analyzerFactory();
        $capturing = static function (IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, Tokens $tokens, Clock $clock, Deadline $deadline, ?string $requirePhp, ?\Closure $moveChecks) use ($factory, &$checks): Analyzer {
            $checks = $moveChecks;

            return $factory($io, $config, $repositories, $lockrot, $tokens, $clock, $deadline);
        };

        (new InstallTimeSummary($capturing, static fn (): float => 0.0))->onPreOperationsExec($event);

        self::assertInstanceOf(\Closure::class, $checks, $io->getOutput());
        self::assertSame(MoveCheck::REFUSED, $checks()->checker()->baseline()->check()->result());
    }

    /**
     * `composer require foo/foo:dev-main#abc --dry-run` puts the link in the root and in no file.
     * The flag and the reference come from that link, as the written composer.json gives them.
     */
    public function testARootLinkKeepsTheFlagAndTheReferenceOfItsConstraint(): void
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require-dev' => ['foo/foo' => 'dev-main#abc']], RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $root);

        $written = InstallTimeSummary::rootAsWritten($root);

        self::assertSame([['foo/foo' => BasePackage::STABILITY_DEV], ['foo/foo' => 'abc']], [$written->getStabilityFlags(), $written->getReferences()]);
    }

    /**
     * `composer require phpzip/phpzip --dry-run` adds the requirement to the root in memory and
     * writes no composer.json. The block reads the manifest that the command would write, so it
     * equals the block of the `composer require` that writes it.
     */
    public function testARequireDryRunJudgesTheNewRequirementAsTheWrittenManifestDoes(): void
    {
        $manifest = ['name' => 'lockrot/install-time-test', 'require' => ['vendor/direct-req' => '1.0.0'], 'extra' => ['lockrot' => ['target-php' => '8.4']]];
        $written = $manifest;
        $written['require']['phpzip/phpzip'] = '2.0.8';
        $direct = ['name' => 'vendor/direct-req', 'version' => '1.0.0', 'require' => ['php' => '>=5.3.0'], 'type' => 'library', 'notification-url' => 'https://packagist.org/downloads/', 'time' => '2026-01-01T00:00:00+00:00'];
        $root = (new ArrayLoader())->load(['name' => 'lockrot/install-time-test', 'version' => '1.0.0', 'require' => $written['require']], RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $root);
        $outputs = [];
        foreach ([[$written, true], [$manifest, false]] as [$json, $executeOperations]) {
            $dir = $this->project([], [$direct, self::PHPZIP]);
            $contents = (string) json_encode($json);
            file_put_contents($dir.'/composer.json', $contents);
            // The lock of the update carries the hash of the composer.json that Composer's Locker holds.
            file_put_contents($dir.'/composer.lock', (string) json_encode(['content-hash' => Locker::getContentHash($contents), 'packages' => [$direct, self::PHPZIP], 'packages-dev' => []]));
            $io = new BufferIO();
            $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]), $executeOperations, false, null, $root);

            (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($event);

            $outputs[] = $io->getOutput();
        }

        self::assertStringContainsString('phpzip/phpzip 2.0.8', $outputs[0]);
        self::assertSame($outputs[0], $outputs[1]);
    }

    /** A constraint keeps its text as written, `self.version` included, and a listed name keeps its spelling. */
    public function testTheManifestAsWrittenKeepsEachConstraintAsWritten(): void
    {
        $dir = $this->tempDir('lockrot-install-time-');
        file_put_contents($dir.'/composer.json', (string) json_encode(['name' => 'acme/app', 'require' => ['Acme/Sib' => 'self.version', 'phpzip/phpzip' => '2.0.8']]));
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.2.0', 'require' => ['Acme/Sib' => 'self.version', 'phpzip/phpzip' => '2.0.8', 'new/pkg' => '^1.0']], RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $root);

        $written = InstallTimeSummary::withRootRequirements(ProjectConfig::fromFile($dir.'/composer.json'), $dir.'/composer.json', $root);

        self::assertSame(['acme/sib' => 'self.version', 'phpzip/phpzip' => '2.0.8', 'new/pkg' => '^1.0'], $written->constraints(ProjectConfig::REQUIRE));
        self::assertSame([ProjectConfig::REQUIRE => 'Acme/Sib'], $written->listedIn('acme/sib'));
    }

    /** A dry run whose root holds the manifest's links reads composer.json as written, its lock hash included. */
    public function testADryRunWithTheLinksOfTheManifestStillComparesTheLockHash(): void
    {
        $root = (new ArrayLoader())->load(['name' => 'lockrot/install-time-test', 'version' => '1.0.0', 'require' => ['phpzip/phpzip' => '2.0.8']], RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $root);
        $this->project();
        $io = new BufferIO();

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]), false, false, null, $root));

        self::assertStringContainsString('note: lock: the lock file does not match composer.json', $io->getOutput());
    }

    public function testInstallTimeBudgetComesFromConfig(): void
    {
        $this->project(['install-time-budget' => 1]);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        self::assertSame(1.0, $this->remainingAtHandOver($event));
    }

    public function testInstallTimeBudgetDefaultsToFiveSeconds(): void
    {
        $this->project();
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        self::assertSame(5.0, $this->remainingAtHandOver($event));
    }

    private ?float $remainingAtHandOver = null;

    /** @var list<string> the target minor of each LockrotConfig that reaches the factory */
    private array $targetsAtHandOver = [];

    /** The seconds the deadline has left when the factory gets it, read before the analysis runs. */
    private function remainingAtHandOver(InstallerEvent $event): ?float
    {
        $monotonicNow = static fn (): float => 1000.0;
        (new InstallTimeSummary(\Closure::fromCallable([$this, 'recordingAnalyzerFactory']), $monotonicNow))->onPreOperationsExec($event);

        return $this->remainingAtHandOver;
    }

    /** @param list<RepositoryInterface> $repositories */
    private function targetRecordingAnalyzerFactory(IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, Tokens $tokens, Clock $clock, Deadline $deadline): Analyzer
    {
        $this->targetsAtHandOver[] = $lockrot->targetPhp();

        return ($this->analyzerFactory())($io, $config, $repositories, $lockrot, $tokens, $clock, $deadline);
    }

    /** @param list<RepositoryInterface> $repositories */
    private function recordingAnalyzerFactory(IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, Tokens $tokens, Clock $clock, Deadline $deadline): Analyzer
    {
        $this->remainingAtHandOver = $deadline->remainingSeconds();

        return ($this->analyzerFactory())($io, $config, $repositories, $lockrot, $tokens, $clock, $deadline);
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(8));
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
