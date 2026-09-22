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
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineFile;
use Lockrot\Clock;
use Lockrot\Config\LockrotConfig;
use Lockrot\Config\Policy;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Deadline;
use Lockrot\Exception\ConfigException;
use Lockrot\Explain\Explanation;
use Lockrot\Html\PageData;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Output\TerminalWidth;
use Lockrot\Version;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** `composer lockrot` — analyses composer.lock and prints a report in the configured format. */
final class LockrotCommand extends BaseCommand
{
    /** @var callable(IOInterface, Config, list<RepositoryInterface>, LockrotConfig, Tokens, Clock, Deadline, ?string): Analyzer */
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

    /** @param null|callable(IOInterface, Config, list<RepositoryInterface>, LockrotConfig, Tokens, Clock, Deadline, ?string): Analyzer $analyzerFactory */
    public function __construct(?callable $analyzerFactory = null)
    {
        $this->analyzerFactory = $analyzerFactory ?? [ServiceFactory::class, 'createAnalyzer'];
        parent::__construct('lockrot');
    }

    protected function configure(): void
    {
        $this
            ->setAliases(['rot'])
            ->setDescription('Shows dependency rot in composer.lock: abandoned, silent, pinned, left-behind and old-promise packages, with the security advisories no fix is coming for')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, json, github (workflow annotations), sarif (SARIF 2.1.0), gitlab (Code Quality JSON), markdown (PR comment) or html (one self-contained page)')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Exit 1 when a finding reaches this verdict (none, abandoned, silent, pinned, left-behind, old-promise, stale) or priority (critical, high, medium, low)')
            ->addOption('target-php', null, InputOption::VALUE_REQUIRED, 'PHP version the project targets, e.g. 8.4 (default: config.platform.php or the running PHP)')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Include packages-dev')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Show every package, not only flagged ones')
            ->addOption('offline', null, InputOption::VALUE_NONE, 'Use cached data only, never go to the network')
            ->addOption('strict-network', null, InputOption::VALUE_NONE, 'Exit 1 when a repository or a repository host (GitHub, GitLab, Bitbucket) could not be reached')
            ->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'Baseline file to read or write (default: lockrot-baseline.json next to composer.json)')
            ->addOption('generate-baseline', null, InputOption::VALUE_NONE, 'Write the current findings to the baseline file and exit 0')
            ->addOption('explain', null, InputOption::VALUE_REQUIRED, 'Explain one package: its verdict, every signal with its raw data, and the repository facts they were read from (text, or JSON with --format=json); exit 0');
    }

    /**
     * Composer's BaseCommand::initialize() bootstraps a Composer instance from the project's
     * composer.json and lets a JSON parse error escape as a Composer crash — exit 1, before
     * execute() is ever reached. lockrot documents a malformed manifest as a configuration error
     * (exit 2, README "Exit codes"), so the file is checked here first and the failure is carried
     * into execute()'s error handling instead.
     *
     * --offline sets COMPOSER_DISABLE_NETWORK as early as this command can, but that alone is not
     * the mechanism, and cannot be: HttpDownloader reads the variable once, in its own constructor,
     * and in plugin mode Composer\Console\Application::doRun() has already built the Composer
     * instance — with its HttpDownloader and RepositoryManager — while collecting plugin commands,
     * long before any command's initialize() runs. What makes --offline effective is
     * composerBootstrap() rebuilding the repositories afterwards, so that they get an
     * HttpDownloader constructed after this point.
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
        try {
            parent::initialize($input, $output);
        } catch (\Throwable $e) {
            // Symfony's Command::run() calls initialize() outside any try/catch of its own, so a
            // failure here would otherwise skip execute() entirely — and with it the finally block
            // that normally restores the environment. Restored here instead, then rethrown
            // unchanged so Composer's own error handling still sees the original failure.
            $this->restoreEnv();

            throw $e;
        }
    }

    /**
     * lockrot never reads the root package's own version (it inspects composer.lock, not the
     * project's own release), so there is nothing for it to lose by pre-empting Composer's guess.
     * Without COMPOSER_ROOT_VERSION set, RootPackageLoader falls back to VersionGuesser, which
     * shells out to git/hg/fossil/svn looking for a tag or branch to derive a version from, and
     * then warns "could not detect the root package version, defaulting to '1.0.0'". Setting the
     * variable to that same default up front skips both the probing and the warning.
     *
     * This has to run before parent::initialize() rather than in resolveComposer(): Composer's own
     * BaseCommand::initialize() already builds the first Composer instance itself, so by the time
     * resolveComposer() runs that instance exists and is returned as-is.
     *
     * In plugin mode a Composer instance already exists before any of this runs — built while
     * Composer's own Application was collecting plugin commands — so the guess never re-triggers
     * there regardless. This guard therefore only takes effect on the path that has no pre-existing
     * instance to reuse: the standalone PHAR, or any other lock-only invocation.
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
            if (!is_file($lockPath)) {
                // Unlike `composer`, lockrot never walks up to a parent project (the PHAR hides the
                // manifest from Composer's preamble, see bin/lockrot), so say where it looked.
                throw new ConfigException('composer.lock not found in '.$cwd.'; lockrot does not look in parent directories: run it from the project root or pass -d <dir>');
            }
            $lock = LockFile::fromFile($lockPath);
            // `composer lockrot` is the deliberate, full run: no time budget, unlike the
            // install-time summary.
            $analyzer = AnalyzerBootstrap::create($this->analyzerFactory, $io, $config, $repositories, $project, $lockrot, $env, Deadline::never());
            // Before the baseline is even resolved: an explanation does not consult it, so a
            // baseline that is missing or unreadable must not stand between the question and the answer.
            $explain = $input->getOption('explain');
            if (\is_string($explain)) {
                return $this->explain($output, $explain, $analyzer, $lock, $project, $lockrot);
            }
            // Resolved and read before the analysis so a missing explicit path or an unreadable
            // file fails immediately, rather than after a full repository round. A generate run is the
            // one case where the target is allowed not to exist yet: it is about to be created.
            $generate = $input->getOption('generate-baseline') === true;
            $baselineFile = BaselineFile::resolve($cwd, $lockrot->baseline());
            $existingBaseline = $this->readBaseline($baselineFile, $lockrot->baseline() !== null && !$generate);
            $format = $lockrot->format();
            // `html` draws a release-branch timeline per package, which is the one thing only the
            // facts carry; every other format is happy with the report and lets them go.
            $analysis = $format === 'html'
                ? $analyzer->analyzeWithFacts($lock->packages($lockrot->includeDev()), $lock, $project, $lockrot->includeDev())
                : null;
            $report = $analysis === null ? $analyzer->analyze($lock, $project, $lockrot->includeDev()) : $analysis->report();

            // Before the baseline and before any formatter: every document the run writes should say
            // what the run was told to do, because that is what its verdicts were decided against.
            $report = $report->withRun(new RunSettings(
                // What the project calls itself, unless the manifest's lockrot config says otherwise.
                $lockrot->project() ?? $project->name(),
                $lockrot->targetPhp(),
                $lockPath,
                $lockrot->failOn(),
                $lockrot->thresholds()
            ));

            if ($generate) {
                return $this->generateBaseline($output, $baselineFile, $report, $existingBaseline, $lockrot);
            }
            if ($existingBaseline !== null) {
                $report = $report->withBaseline(BaselineComparison::compare(
                    $existingBaseline,
                    $report,
                    $baselineFile->displayPath(),
                    self::lockPackageNames($lock)
                ));
            }

            // The annotation formats point back at the lock they were computed from; an unreadable
            // one throws ConfigException from here, which the catch below turns into exit 2 the
            // same way an unreadable lock does a few lines up.
            $context = FormatContext::create($lockPath, $lockrot->failOn(), Version::STRING, TerminalWidth::detect($env, $this->getApplication()));
            $page = $analysis === null
                ? null
                : new PageData($analysis, $lockrot->thresholds(), $lockrot->targetPhp());
            // Only `table` is meant to go through the tag formatter; every machine-readable format
            // is written raw, so a `<` in a constraint or a package name reaches the parser on the
            // other end untouched.
            $output->write(
                Formatters::for($format, $context, $page)->format($report, $input->getOption('all') === true),
                false,
                $format === 'table' ? OutputInterface::OUTPUT_NORMAL : OutputInterface::OUTPUT_RAW
            );

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
     * `--explain <package>`: the same run every other invocation makes — the whole lock, so the
     * chain, the transitive exposure and the priority are the report's — then one package's
     * finding printed with the facts it was decided on ({@see Explanation}), and exit 0: the run
     * answers a question, it does not gate anything. A package the run did not analyse is a
     * configuration error (exit 2) with the reason: not in the lock, or in `packages-dev` without
     * `--dev`. The baseline is not consulted — what the project has accepted is not what is asked.
     */
    private function explain(OutputInterface $output, string $name, Analyzer $analyzer, LockFile $lock, ProjectConfig $project, LockrotConfig $lockrot): int
    {
        $format = $lockrot->format();
        if ($format !== 'table' && $format !== 'json') {
            throw new ConfigException('--explain prints text or, with --format=json, JSON; --format='.$format.' has no explanation form');
        }
        $name = strtolower(trim($name));
        if ($name === '') {
            throw new ConfigException('--explain needs a package name, e.g. --explain=vendor/package');
        }
        $locked = $lock->find($name);
        if ($locked === null) {
            throw new ConfigException($name.' is not in composer.lock');
        }
        if ($locked->isDev() && !$lockrot->includeDev()) {
            throw new ConfigException($name.' is in packages-dev; pass --dev to explain it');
        }
        $analysis = $analyzer->analyzeWithFacts($lock->packages($lockrot->includeDev()), $lock, $project, $lockrot->includeDev());
        $finding = $analysis->finding($name);
        $facts = $analysis->facts($name);
        // Both come from the same pass over the same packages, so one is null exactly when the
        // other is; the check is for the types, the lock lookup above already ruled the case out.
        if ($finding === null || $facts === null) {
            throw new ConfigException($name.' was not analysed');
        }
        $explanation = new Explanation($finding, $facts, $lockrot->thresholds(), $lockrot->targetPhp(), $analysis->report());
        $formatter = new ExplainFormatter();
        if ($format === 'json') {
            $output->write($formatter->json($explanation), false, OutputInterface::OUTPUT_RAW);
        } else {
            $output->write($formatter->text($explanation));
        }

        return Policy::EXIT_OK;
    }

    /**
     * The baseline on disk, or null when the default file simply does not exist yet — the state of
     * every project that has not generated one.
     *
     * A path the project asked for explicitly is different: a typo in `--baseline` or in
     * `extra.lockrot.baseline` would otherwise silently turn a gated build into an ungated one, so
     * a missing file there is a configuration error. So is a file that exists but cannot be read:
     * an unreadable baseline is never treated as an empty one.
     */
    private function readBaseline(BaselineFile $file, bool $explicit): ?Baseline
    {
        if (!$file->exists()) {
            if ($explicit) {
                throw new ConfigException($file->displayPath().' not found');
            }

            return null;
        }

        return $file->read();
    }

    /**
     * `--generate-baseline`: write what this run found, say so on stderr, print nothing on stdout,
     * and exit 0 whatever `fail-on` says — the point of the run is to record the findings, not to
     * judge them. `--strict-network` still applies: a baseline written from metadata that never
     * arrived would accept findings lockrot could not actually check.
     */
    private function generateBaseline(OutputInterface $output, BaselineFile $file, Report $report, ?Baseline $existing, LockrotConfig $lockrot): int
    {
        $baseline = Baseline::fromReport($report, $existing);
        $file->write($baseline);
        $this->writeError($output, \sprintf(
            'lockrot: baseline written to %s (%d findings)',
            $file->displayPath(),
            $baseline->count()
        ));

        return Policy::strictNetworkTripped($report, $lockrot) ? Policy::EXIT_FINDINGS : Policy::EXIT_OK;
    }

    /**
     * Every package the lock holds, `packages-dev` included and whatever this run's `--dev` says.
     *
     * "Stale" is a statement about composer.lock, not about the scope of one run: a baselined
     * package that is now `ok`, and a dev package a run without `--dev` never analysed, are both
     * still in the lock and must not be reported as gone. {@see InstallTimeSummary::withBaseline()}
     * measures it the same way, for the same reason.
     *
     * @return list<string>
     */
    private static function lockPackageNames(LockFile $lock): array
    {
        $names = [];
        foreach ($lock->packages(true) as $package) {
            $names[] = $package->name();
        }

        return $names;
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
        $baseline = $input->getOption('baseline');

        return [
            'format' => \is_string($format) ? $format : null,
            'fail-on' => \is_string($failOn) ? $failOn : null,
            'target-php' => \is_string($targetPhp) ? $targetPhp : null,
            'baseline' => \is_string($baseline) ? $baseline : null,
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
     * same order, that Composer itself builds the manager from.
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

        // Composer >= 2.3 has tryComposer(); 2.2 LTS only has getComposer(bool $required).
        // @phpstan-ignore function.alreadyNarrowedType (tryComposer() does not exist in Composer 2.2 LTS; guard is load-bearing there)
        $composer = method_exists($this, 'tryComposer') ? $this->tryComposer() : $this->getComposer(false);

        return $composer instanceof Composer ? $composer : null;
    }
}
