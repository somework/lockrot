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
use Lockrot\Config\UnknownKeyWarnings;
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
use Lockrot\Signal\SignalSet;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\RecordingIO;
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

    /**
     * grandt/binstring 1.0.0 as it appears in the wallabag lock: last release 2015-08-13, and its
     * GitHub repository is recorded in tests/fixtures/http/github. Same `silent` verdict as
     * {@see self::PHPZIP}, and its name sorts *before* it — so the two rows can only be told apart
     * by the priority, which is the point of the dev-flag test below.
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

    /**
     * A project that requires one package for production and one for development, with both already
     * in their own section of the lock — the state Composer leaves behind after `composer require
     * --dev`, which writes the new lock before dispatching PRE_OPERATIONS_EXEC.
     *
     * @param array<string, mixed> $prodPackage entry for `require` and `packages`
     * @param array<string, mixed> $devPackage  entry for `require-dev` and `packages-dev`
     */
    private function projectWithDevRequire(array $prodPackage, array $devPackage): string
    {
        $dir = $this->tempDir('lockrot-install-time-dev-');
        [$prodName, $prodVersion] = self::nameAndVersionOf($prodPackage);
        [$devName, $devVersion] = self::nameAndVersionOf($devPackage);
        file_put_contents($dir.'/composer.json', (string) json_encode([
            'name' => 'lockrot/install-time-dev-test',
            'require' => [$prodName => $prodVersion],
            'require-dev' => [$devName => $devVersion],
            'extra' => ['lockrot' => ['target-php' => '8.4']],
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
     * The two fields a lock entry has to carry to be turned into a root requirement.
     *
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

    private function event(BufferIO $io, Transaction $transaction, bool $executeOperations = true, bool $devMode = false): InstallerEvent
    {
        $server = self::$server;
        self::assertNotNull($server);
        $config = $server->config();
        $composer = new Composer();
        $composer->setConfig($config);
        $composer->setRepositoryManager(RepositoryFactory::manager($io, $config, Factory::createHttpDownloader($io, $config)));

        return new InstallerEvent(InstallerEvents::PRE_OPERATIONS_EXEC, $composer, $io, $devMode, $executeOperations, $transaction);
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

    /**
     * A Composer transaction carries no `require-dev` membership, so the packages it hands over are
     * all prod until the lock is consulted. Both rows here are `silent` and both are root requires,
     * which leaves the priority as the only thing that can order them: grandt/binstring is a dev
     * requirement, so it drops `critical → high` and sits below phpzip/phpzip even though its name
     * sorts first. Read the wrong way round, the dev row would come first on the name tie-break.
     */
    public function testADevRequirementInTheTransactionIsRankedBelowAnEqualProdRequirement(): void
    {
        $this->projectWithDevRequire(self::PHPZIP, self::BINSTRING);
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

        self::assertStringContainsString('lockrot: dependency rot in 1 of 1 changed package', $io->getOutput());
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

        self::assertStringContainsString('lockrot: dependency rot in 1 of 1 changed package', $io->getOutput());
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

    /** @param array<string, string> $verdicts package => verdict */
    private function writeBaseline(string $path, array $verdicts): void
    {
        $findings = [];
        foreach ($verdicts as $package => $verdict) {
            $findings[$package] = ['version' => '2.0.8', 'verdict' => $verdict, 'first_seen' => '2026-01-15'];
        }
        file_put_contents($path, (string) json_encode([
            'lockrot' => ['version' => '0.1.0', 'schema' => 1],
            'generated_at' => self::FIXED_NOW,
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
        // The `</warning>` pins the far end: collapsed whitespace must not survive as a trailing
        // space, and `skipped: boom` pins the near end against a leading one.
        self::assertStringContainsString('lockrot: install-time check skipped: boom second line third line</warning>', $output);
        self::assertCount(1, array_filter(explode("\n", trim($output))), $output);
    }

    /**
     * The skipped-check line is a `<warning>` from end to end: Composer colours what the tags wrap,
     * so a message that fell outside them would print as plain text in the middle of an install.
     *
     * Read before Composer's formatter sees it, because an undecorated formatter strips the tags and
     * renders `<warning>text`, `text</warning>` and `<warning>text</warning>` identically.
     */
    public function testTheSkippedCheckLineIsWrappedInAWarningTag(): void
    {
        $this->project();
        $io = new RecordingIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));
        $factory = static function (): Analyzer {
            throw new \RuntimeException('boom');
        };

        (new InstallTimeSummary($factory))->onPreOperationsExec($event);

        $message = $io->onlyError();
        self::assertStringStartsWith('<warning>lockrot: install-time check skipped: ', $message);
        self::assertStringContainsString('boom', $message);
        self::assertStringEndsWith('</warning>', $message);
    }

    /**
     * The plugin's own default wiring: constructed the way {@see \Lockrot\Composer\LockrotPlugin}
     * constructs it, with no analyzer factory, the summary has to build one through
     * {@see \Lockrot\Composer\ServiceFactory::createAnalyzer()} and print a real block — not fall
     * into the "check skipped" line because the default is not callable.
     *
     * {@see self::PHPZIP_WITHOUT_SOURCE} keeps this off the network: its metadata comes from the
     * class-level fixture server, and with no `source` URL no repository activity round is planned,
     * so nothing reaches GitHub.
     */
    public function testTheDefaultAnalyzerFactoryBuildsAWorkingAnalyzer(): void
    {
        $this->project([], [self::PHPZIP_WITHOUT_SOURCE]);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP_WITHOUT_SOURCE)]));

        (new InstallTimeSummary())->onPreOperationsExec($event);

        $output = $io->getOutput();
        self::assertStringNotContainsString('install-time check skipped', $output);
        self::assertStringContainsString('lockrot: dependency rot in 1 of 1 changed package', $output);
        self::assertStringContainsString('phpzip/phpzip 2.0.8', $output);
    }

    /** `install-tme: off` is the typo nobody would understand from the install: the block keeps printing. */
    public function testAnUnknownKeyIsWarnedAboutAboveTheBlock(): void
    {
        $this->project(['install-tme' => 'off']);
        $io = new RecordingIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory(), new UnknownKeyWarnings()))->onPreOperationsExec($event);

        self::assertGreaterThan(1, \count($io->errors), implode("\n", $io->errors));
        self::assertSame('<warning>lockrot: unknown key extra.lockrot.install-tme ignored (did you mean install-time?)</warning>', $io->errors[0]);
        self::assertStringContainsString('lockrot: dependency rot in 1 of 1 changed package', $io->errors[1]);
    }

    /** The warning is about the config, not about what the transaction found: a clean one still says it. */
    public function testAnUnknownKeyIsWarnedAboutWhenNothingIsFlagged(): void
    {
        $this->project(['slack-webhook' => 'https://example.com'], [self::PSR_LOG]);
        $io = new RecordingIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PSR_LOG)]));

        (new InstallTimeSummary($this->analyzerFactory(), new UnknownKeyWarnings()))->onPreOperationsExec($event);

        self::assertSame('<warning>lockrot: unknown key extra.lockrot.slack-webhook ignored</warning>', $io->onlyError());
    }

    /** With install-time correctly off, the project asked for silence and gets it, unknown key or not. */
    public function testInstallTimeOffStaysSilentDespiteAnUnknownKey(): void
    {
        $this->project(['install-time' => 'off', 'slack-webhook' => 'https://example.com']);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        (new InstallTimeSummary($this->analyzerFactory(), new UnknownKeyWarnings()))->onPreOperationsExec($event);

        self::assertSame('', $io->getOutput());
    }

    public function testLockrotDisableSilencesTheUnknownKeyWarning(): void
    {
        $this->project(['install-tme' => 'off']);
        $io = new BufferIO();
        $event = $this->event($io, new Transaction([], [$this->loadPackage(self::PHPZIP)]));

        putenv('LOCKROT_DISABLE=1');
        try {
            (new InstallTimeSummary($this->analyzerFactory(), new UnknownKeyWarnings()))->onPreOperationsExec($event);
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

        (new InstallTimeSummary($this->analyzerFactory(), new UnknownKeyWarnings()))->onPreOperationsExec($event);

        self::assertSame('', $io->getOutput());
    }

    public function testASecondTransactionInTheSameProcessDoesNotRepeatTheWarning(): void
    {
        $this->project(['install-tme' => 'off'], [self::PSR_LOG]);
        $warnings = new UnknownKeyWarnings();
        $first = new RecordingIO();
        $second = new RecordingIO();

        (new InstallTimeSummary($this->analyzerFactory(), $warnings))->onPreOperationsExec($this->event($first, new Transaction([], [$this->loadPackage(self::PSR_LOG)])));
        (new InstallTimeSummary($this->analyzerFactory(), $warnings))->onPreOperationsExec($this->event($second, new Transaction([], [$this->loadPackage(self::PSR_LOG)])));

        self::assertCount(1, $first->errors);
        self::assertSame([], $second->errors);
    }

    /** Constructed the way the plugin constructs it, the summary shares the process-wide guard. */
    public function testTheDefaultWiringUsesTheProcessWideGuard(): void
    {
        $this->project(['install-time-default-guard' => 1], [self::PSR_LOG]);
        UnknownKeyWarnings::process()->lines(['install-time-default-guard' => 1]);
        $io = new RecordingIO();

        (new InstallTimeSummary($this->analyzerFactory()))->onPreOperationsExec($this->event($io, new Transaction([], [$this->loadPackage(self::PSR_LOG)])));

        self::assertSame([], $io->errors);
    }

    public function testAGuardHandedInIsTheOneUsed(): void
    {
        $this->project(['install-time-own-guard' => 1], [self::PSR_LOG]);
        UnknownKeyWarnings::process()->lines(['install-time-own-guard' => 1]);
        $io = new RecordingIO();

        (new InstallTimeSummary($this->analyzerFactory(), new UnknownKeyWarnings()))->onPreOperationsExec($this->event($io, new Transaction([], [$this->loadPackage(self::PSR_LOG)])));

        self::assertSame(['<warning>lockrot: unknown key extra.lockrot.install-time-own-guard ignored</warning>'], $io->errors);
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

    /**
     * The pre-transaction lock on disk knows nothing about the transaction's own new packages or
     * requirements — the normal state during a `--dry-run`, or whenever the lock has not caught up
     * yet. LockFile::withPackages() overlays the transaction onto the chain source so that a package
     * new to the graph still resolves a "via" chain.
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
        self::assertStringContainsString('phpzip/phpzip 2.0.8', $output);
        self::assertStringContainsString('(via vendor/direct-req)', $output);
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
    private function recordingAnalyzerFactory(IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, Tokens $tokens, Clock $clock, Deadline $deadline): Analyzer
    {
        $this->recordedDeadline = $deadline;

        return ($this->analyzerFactory())($io, $config, $repositories, $lockrot, $tokens, $clock, $deadline);
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
