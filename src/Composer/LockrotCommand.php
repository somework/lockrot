<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\Config;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\RepositoryInterface;
use Composer\Util\Platform;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Config\LockrotConfig;
use Lockrot\Config\Policy;
use Lockrot\Deadline;
use Lockrot\Exception\ConfigException;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Version;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class LockrotCommand extends BaseCommand
{
    /** @var callable(IOInterface, Config, list<RepositoryInterface>, LockrotConfig, ?string, Clock, Deadline): Analyzer */
    private $analyzerFactory;

    /** Set by initialize() when the project manifest is unusable; rethrown inside execute(). */
    private ?ConfigException $bootstrapError = null;

    /**
     * Snapshot of COMPOSER_DISABLE_NETWORK/COMPOSER_ROOT_VERSION taken at the top of initialize(),
     * before either is set; execute() restores exactly these values in a finally block so a
     * lockrot invocation never leaves the process environment changed behind it. null only before
     * initialize() has run.
     *
     * @var array<string, string|false>|null
     */
    private ?array $envSnapshot = null;

    /** @param null|callable(IOInterface, Config, list<RepositoryInterface>, LockrotConfig, ?string, Clock, Deadline): Analyzer $analyzerFactory */
    public function __construct(?callable $analyzerFactory = null)
    {
        $this->analyzerFactory = $analyzerFactory ?? [ServiceFactory::class, 'createAnalyzer'];
        parent::__construct('lockrot');
    }

    protected function configure(): void
    {
        $this
            ->setAliases(['rot'])
            ->setDescription('Shows dependency rot in composer.lock: abandoned, silent, pinned and old-promise packages')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, json, github (workflow annotations) or sarif (SARIF 2.1.0)')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Exit 1 when a finding reaches this verdict: none, abandoned, silent, pinned, old-promise, stale')
            ->addOption('target-php', null, InputOption::VALUE_REQUIRED, 'PHP version the project targets, e.g. 8.4 (default: config.platform.php or the running PHP)')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Include packages-dev')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Show every package, not only flagged ones')
            ->addOption('offline', null, InputOption::VALUE_NONE, 'Use cached data only, never go to the network')
            ->addOption('strict-network', null, InputOption::VALUE_NONE, 'Exit 1 when the repository or GitHub could not be reached');
    }

    /**
     * Composer's BaseCommand::initialize() bootstraps a Composer instance from the project's
     * composer.json (2.10.3 src/Composer/Command/BaseCommand.php:240, 2.2.25 :159) and lets a JSON
     * parse error escape as a Composer crash — exit 1, before execute() is ever reached. lockrot
     * documents a malformed manifest as a configuration error (exit 2, README "Exit codes"), so the
     * file is checked here first and the failure is carried into execute()'s error handling instead.
     *
     * --offline sets COMPOSER_DISABLE_NETWORK as early as this command can, but that alone is not
     * the mechanism, and cannot be: HttpDownloader reads the variable once, in its own constructor
     * (2.10.3 src/Composer/Util/HttpDownloader.php:73, 2.2.25 :74), and in plugin mode
     * Composer\Console\Application::doRun() has already built the Composer instance — with its
     * HttpDownloader and RepositoryManager — while collecting plugin commands
     * (getPluginCommands() -> getComposer()), long before any command's initialize() runs. What
     * makes --offline effective is composerBootstrap() rebuilding the repositories afterwards, so
     * that they get an HttpDownloader constructed after this point.
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->bootstrapError = null;
        // Captured before either variable is touched below, so execute()'s finally block can put
        // the environment back exactly as it found it regardless of which branches ran.
        $this->envSnapshot = [
            'COMPOSER_DISABLE_NETWORK' => Platform::getEnv('COMPOSER_DISABLE_NETWORK'),
            'COMPOSER_ROOT_VERSION' => Platform::getEnv('COMPOSER_ROOT_VERSION'),
        ];
        try {
            ProjectConfig::fromFile((string) getcwd().'/composer.json');
        } catch (ConfigException $e) {
            $this->bootstrapError = $e;

            return;
        }
        if ($input->getOption('offline') === true) {
            // Platform::putEnv() rather than putenv(): Composer reads its environment through
            // Platform::getEnv(), which consults $_SERVER and $_ENV first.
            Platform::putEnv('COMPOSER_DISABLE_NETWORK', '1');
        }
        $this->quietRootVersionGuessing();
        parent::initialize($input, $output);
    }

    /**
     * lockrot never reads the root package's own version (it inspects composer.lock, not the
     * project's own release), so there is nothing for it to lose by pre-empting Composer's guess.
     * Without COMPOSER_ROOT_VERSION set, RootPackageLoader falls back to VersionGuesser, which
     * shells out to git/hg/fossil/svn looking for a tag/branch to derive a version from (2.10.3
     * Package/Loader/RootPackageLoader.php:95-96, warning at :108-113; 2.2.25 :88-89, default
     * :100), and then warns "could not detect the root package version, defaulting to '1.0.0'".
     * Setting the variable to that same default up front skips both the probing and the warning.
     *
     * This has to run here, before parent::initialize(), rather than in resolveComposer(): Composer's
     * own BaseCommand::initialize() (called via parent::initialize() below) already builds the first
     * Composer instance itself, through tryComposer()/getComposer(false) (2.10.3
     * Command/BaseCommand.php:240, 2.2.25 :159) — before this command's execute() and
     * composerBootstrap()/resolveComposer() ever run. By the time resolveComposer() calls
     * tryComposer() again, that instance already exists and is returned as-is, so setting the
     * variable there is too late to prevent the guess that already happened during initialize().
     *
     * In plugin mode a Composer instance already exists by the time any of this runs — built while
     * Composer's own Console\Application::doRun() was collecting plugin commands, long before
     * initialize() — so tryComposer() here only returns that cached instance and never re-triggers
     * VersionGuesser regardless. This guard therefore only takes effect on the path that has no
     * pre-existing instance to reuse: the standalone PHAR, or any other lock-only invocation.
     */
    private function quietRootVersionGuessing(): void
    {
        if (Platform::getEnv('COMPOSER_ROOT_VERSION') === false || Platform::getEnv('COMPOSER_ROOT_VERSION') === '') {
            Platform::putEnv('COMPOSER_ROOT_VERSION', '1.0.0');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        try {
            if ($this->bootstrapError !== null) {
                throw $this->bootstrapError;
            }
            $env = getenv();
            $cwd = (string) getcwd();
            $project = ProjectConfig::fromFile($cwd.'/composer.json');
            [$config, $repositories] = $this->composerBootstrap($io, is_file($cwd.'/composer.json'));
            $lockrot = LockrotConfig::fromSources($project->lockrotExtra(), $env, $this->cliOptions($input), \PHP_VERSION, $project->platformPhp());
            if ($lockrot->isDisabled()) {
                $this->writeError($output, 'lockrot disabled via LOCKROT_DISABLE');

                return Policy::EXIT_OK;
            }
            $lockPath = $cwd.'/composer.lock';
            $lock = LockFile::fromFile($lockPath);
            // `composer lockrot` is the deliberate, full run: no time budget, unlike the
            // install-time summary (SPEC F2.6).
            $analyzer = AnalyzerBootstrap::create($this->analyzerFactory, $io, $config, $repositories, $project, $lockrot, $env, Deadline::never());
            $report = $analyzer->analyze($lock, $project, $lockrot->includeDev());
            // The annotation formats point back at the lock they were computed from; an unreadable
            // one throws ConfigException from here, which the catch below turns into exit 2 the
            // same way an unreadable lock does a few lines up.
            $context = FormatContext::create($lockPath, $lockrot->failOn(), Version::STRING);
            $output->write(Formatters::for($lockrot->format(), $context)->format($report, $input->getOption('all') === true));

            return Policy::exitCode($report, $lockrot);
        } catch (ConfigException $e) {
            $this->writeError($output, '<error>lockrot: '.$e->getMessage().'</error>');

            return Policy::EXIT_ERROR;
        } catch (\Throwable $e) {
            $this->writeError($output, '<error>lockrot failed: '.$e->getMessage().'</error>');
            if ($io->isVerbose()) {
                $this->writeError($output, $e->getTraceAsString());
            }

            return Policy::EXIT_ERROR;
        } finally {
            $this->restoreEnv();
        }
    }

    /**
     * Puts COMPOSER_DISABLE_NETWORK/COMPOSER_ROOT_VERSION back exactly as initialize() found them:
     * putEnv() when the snapshot was a string, clearEnv() when it was unset. Runs from execute()'s
     * finally block, so --offline's network guard is still in place for the whole run and only
     * disappears once this command is done with it.
     */
    private function restoreEnv(): void
    {
        if ($this->envSnapshot === null) {
            return;
        }
        foreach ($this->envSnapshot as $name => $value) {
            if ($value === false) {
                Platform::clearEnv($name);
            } else {
                Platform::putEnv($name, $value);
            }
        }
        $this->envSnapshot = null;
    }

    private function writeError(OutputInterface $output, string $message): void
    {
        $target = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $target->writeln($message);
    }

    /** @return array<string, mixed> */
    private function cliOptions(InputInterface $input): array
    {
        $format = $input->getOption('format');
        $failOn = $input->getOption('fail-on');
        $targetPhp = $input->getOption('target-php');

        return [
            'format' => \is_string($format) ? $format : null,
            'fail-on' => \is_string($failOn) ? $failOn : null,
            'target-php' => \is_string($targetPhp) ? $targetPhp : null,
            'dev' => $input->getOption('dev') === true,
            'offline' => $input->getOption('offline') === true,
            'strict-network' => $input->getOption('strict-network') === true,
        ];
    }

    /**
     * Reuses the project's own Composer configuration (and so its configured repositories, in their
     * configured order — the public package repository by default, but private repositories, Satis
     * instances and mirrors are honoured the same way) when a Composer instance is available; falls
     * back to Composer's own defaults for a lock-only directory with no Composer instance to reuse,
     * e.g. inside the standalone PHAR.
     *
     * The repositories are rebuilt from that Config rather than taken from the instance's
     * RepositoryManager, because in plugin mode the manager and its HttpDownloader predate this
     * command entirely (see initialize()), so --offline could never reach them.
     * RepositoryFactory::defaultRepos() reads Config::getRepositories() — the same list, in the
     * same order, that Composer itself builds the manager from (2.10.3
     * src/Composer/Repository/RepositoryFactory.php:81-100, 2.2.25 :96-104).
     *
     * @return array{0: Config, 1: list<RepositoryInterface>}
     */
    private function composerBootstrap(IOInterface $io, bool $hasComposerJson): array
    {
        $composer = $this->resolveComposer($hasComposerJson);
        $config = $composer instanceof Composer ? $composer->getConfig() : Factory::createConfig($io);
        // Thread the project's own EventDispatcher through so plugins hooking
        // PRE_FILE_DOWNLOAD/POST_FILE_DOWNLOAD (mirror/proxy/CDN plugins) still see lockrot's
        // metadata requests on the rebuilt manager; the lock-only path (no Composer instance) has no
        // plugins loaded to reach anyway, so it keeps rebuilding without one.
        $eventDispatcher = $composer instanceof Composer ? $composer->getEventDispatcher() : null;
        $manager = RepositoryFactory::manager($io, $config, Factory::createHttpDownloader($io, $config), $eventDispatcher);

        return [$config, array_values(RepositoryFactory::defaultRepos($io, $config, $manager))];
    }

    private function resolveComposer(bool $hasComposerJson): ?Composer
    {
        if (!$hasComposerJson) {
            return null;
        }

        // Composer >= 2.3 has tryComposer(); 2.2 LTS only has getComposer(bool $required) (BaseCommand.php:124 vs 2.2 :59)
        // @phpstan-ignore function.alreadyNarrowedType (tryComposer() does not exist in Composer 2.2 LTS; guard is load-bearing there)
        $composer = method_exists($this, 'tryComposer') ? $this->tryComposer() : $this->getComposer(false);

        return $composer instanceof Composer ? $composer : null;
    }
}
