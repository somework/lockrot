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
use Lockrot\Config\Gate;
use Lockrot\Config\LockrotConfig;
use Lockrot\Config\Policy;
use Lockrot\Config\UnknownKeys;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Deadline;
use Lockrot\Exception\ConfigException;
use Lockrot\Explain\Explanation;
use Lockrot\Filesystem\Path;
use Lockrot\Html\PageData;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\ConsoleMarkup;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Output\ReportTarget;
use Lockrot\Output\ReportTargets;
use Lockrot\Output\TerminalText;
use Lockrot\Output\TerminalWidth;
use Lockrot\Verdict\FailOn;
use Lockrot\Version;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
final class LockrotCommand extends BaseCommand
{
    use RejectsUnreadableInput;

    /** @var callable(IOInterface, Config, list<RepositoryInterface>, LockrotConfig, Tokens, Clock, Deadline, ?string): Analyzer */
    private $analyzerFactory;

    /**
     * A failure before parent::initialize(), rethrown in execute() so that its catch blocks choose the line and
     * the exit code.
     */
    private ?\Throwable $bootstrapError = null;

    /**
     * COMPOSER_DISABLE_NETWORK and COMPOSER_ROOT_VERSION as initialize() found them. execute()
     * restores them in a finally block, so a run leaves the process environment unchanged.
     *
     * @var array<string, string|false>|null
     */
    private ?array $envSnapshot = null;

    /** @var list<int>|null */
    private ?array $htmlReads;

    /**
     * @param null|callable(IOInterface, Config, list<RepositoryInterface>, LockrotConfig, Tokens, Clock, Deadline, ?string): Analyzer $analyzerFactory
     * @param list<int>|null                                                                                                       $htmlReads       the report numbers the html renderer reads, its manifest's unless given
     */
    public function __construct(?callable $analyzerFactory = null, ?array $htmlReads = null)
    {
        $this->htmlReads = $htmlReads;
        $this->analyzerFactory = $analyzerFactory ?? [ServiceFactory::class, 'createAnalyzer'];
        parent::__construct('lockrot');
    }

    protected function configure(): void
    {
        $this
            ->setAliases(['rot'])
            ->setDescription('Shows dependency rot in composer.lock: abandoned, silent, pinned, left-behind and old-promise packages, with the security advisories no fix is coming for')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, json, github (workflow annotations), sarif (SARIF 2.1.0), gitlab (Code Quality JSON), markdown (PR comment) or html (one self-contained page)')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Exit 1 when a finding reaches this verdict (none, abandoned, silent, pinned, left-behind, old-promise, stale), priority (critical, high, medium, low) or `unchecked`, a finding whose check did not run')
            ->addOption('target-php', null, InputOption::VALUE_REQUIRED, 'PHP version the project targets, e.g. 8.4 (default: config.platform.php or the running PHP)')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Include packages-dev')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Show every package, not only flagged ones')
            ->addOption('offline', null, InputOption::VALUE_NONE, 'Use cached data only, never go to the network')
            ->addOption('strict-network', null, InputOption::VALUE_NONE, 'Exit 1 when a repository or a repository host (GitHub, GitLab, Bitbucket) could not be reached')
            ->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'Baseline file to read or write (default: lockrot-baseline.json next to composer.json)')
            ->addOption('generate-baseline', null, InputOption::VALUE_NONE, 'Write the current findings to the baseline file and exit 0')
            ->addOption('explain', null, InputOption::VALUE_REQUIRED, 'Explain one package: its verdict, every signal with its raw data, and the repository facts they were read from (text, or JSON with --format=json); exit 0')
            ->addOption('output', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Also write the report to a file, as <format>:<path> (e.g. sarif:lockrot.sarif), in any format --format takes; repeatable. A relative path is relative to the project directory lockrot runs in; the directory must exist. --format still decides stdout');
    }

    /**
     * Check LOCKROT_DISABLE before any read of the run: no unreadable input can turn a disabled run
     * into exit 2 (docs/configuration.md#environment-overrides). The command line is bound here,
     * before Symfony's run(), so an unreadable one is exit 2 ({@see RejectsUnreadableInput}).
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        if (LockrotConfig::isDisabledByEnvironment(getenv())) {
            $this->writeError($output, 'lockrot disabled via LOCKROT_DISABLE');

            return Policy::EXIT_OK;
        }

        return $this->unreadableInput($input, $output) ?? parent::run($input, $output);
    }

    /**
     * Composer's initialize() lets a manifest error escape as a crash with exit 1, before execute()
     * runs. This method carries it, and any failure before the bootstrap, into execute() as exit 2
     * (docs/ci.md#exit-codes).
     *
     * COMPOSER_DISABLE_NETWORK alone cannot make --offline work: in plugin mode Composer built its
     * HttpDownloader before this runs. composerBootstrap() rebuilds the repositories to cover that.
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->bootstrapError = null;
        // Before this method sets either variable.
        $this->envSnapshot = [
            'COMPOSER_DISABLE_NETWORK' => Platform::getEnv('COMPOSER_DISABLE_NETWORK'),
            'COMPOSER_ROOT_VERSION' => Platform::getEnv('COMPOSER_ROOT_VERSION'),
        ];
        try {
            ProjectConfig::fromFile(self::composerFile());
        } catch (\Throwable $e) {
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
            // After a failure here execute() and its finally block do not run, so restore the
            // environment here and rethrow the original failure to Composer.
            $this->restoreEnv();

            throw $e;
        }
    }

    /**
     * lockrot never reads the root package's version, so it sets Composer's default up front. That
     * skips the VersionGuesser probe of git, hg, fossil and svn, and its warning "could not detect
     * the root package version". Call it before parent::initialize(), which builds the first
     * Composer instance. In plugin mode Composer already built its instance, so this takes effect
     * only without one.
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
            // --output paths are relative to the directory that lockrot runs in. The manifest, its
            // lock and the baseline are where Composer reads them.
            $cwd = (string) getcwd();
            $composerFile = self::composerFile();
            $project = ProjectConfig::fromFile($composerFile);
            [$config, $repositories] = $this->composerBootstrap($io, is_file($composerFile));
            // LOCKROT_DISABLE needs no check here: run() answered it before any of this was read.
            $lockrot = LockrotConfig::fromSources($project->lockrotExtra(), $env, $this->cliOptions($input), \PHP_VERSION, $project->platformPhp());
            // Here and not in initialize(), which reads the same manifest without printing: once per
            // run, on stderr only, after a config error has had its say. Never a failure.
            foreach (UnknownKeys::warnings($project->lockrotExtra()) as $warning) {
                $this->writeWarning($output, 'lockrot: '.$warning);
            }
            // The lock that Composer reads: alt.lock beside COMPOSER=alt.json.
            $lockPath = Factory::getLockFile($composerFile);
            if (!is_file($lockPath)) {
                // Unlike `composer`, lockrot never walks up to a parent project (the PHAR hides the
                // manifest from Composer's preamble, see bin/lockrot), so say where it looked.
                throw new ConfigException(basename($lockPath).' not found in '.\dirname($lockPath).'; lockrot does not look in parent directories: run it from the project root or pass -d <dir>');
            }
            $lock = LockFile::fromFile($lockPath);
            $explain = $input->getOption('explain');
            $specs = self::outputSpecs($input);
            if (\is_string($explain) && $specs !== []) {
                throw new ConfigException('--explain prints one package on stdout and --output writes whole-run reports; run them separately');
            }
            // Resolving the path touches nothing, and the files that --output must not name include it.
            $baselineFile = BaselineFile::resolve(\dirname($composerFile), $lockrot->baseline());
            // Checked before the analysis, like the baseline: a typo in a path fails before a
            // full repository round.
            $targets = ReportTargets::resolve($specs, $cwd, $specs === [] ? [] : self::protectedFiles($cwd, $composerFile, $lockPath, $baselineFile, $project));
            // No time budget, unlike the install-time summary (docs/install-time.md#time-budget).
            $analyzer = AnalyzerBootstrap::create($this->analyzerFactory, $io, $config, $repositories, $project, $lockrot, $env, Deadline::never());
            // Before the baseline is read: an explanation ignores it, so a missing or unreadable
            // baseline must not block it.
            if (\is_string($explain)) {
                return $this->explain($output, $explain, $analyzer, $lock, $project, $lockrot);
            }
            // Read before the analysis, so a missing explicit path or an unreadable file fails before
            // a full repository round. A generate run can name a file that does not exist: it creates it.
            $generate = $input->getOption('generate-baseline') === true;
            $existingBaseline = $this->readBaseline($baselineFile, $lockrot->baseline() !== null && !$generate);
            $format = $lockrot->format();
            // Only `html` needs the facts: it draws a release-branch timeline per package.
            $analysis = $format === 'html' || $targets->wants('html')
                ? $analyzer->analyzeWithFacts($lock->packages($lockrot->includeDev()), $lock, $project, $lockrot->includeDev())
                : null;
            $report = $analysis === null ? $analyzer->analyze($lock, $project, $lockrot->includeDev()) : $analysis->report();

            // One threshold for the report's gate and for the annotation formats.
            $failOn = FailOn::fromString($lockrot->failOn());
            // Before the baseline and any formatter: every document must record the settings that
            // its verdicts were decided against.
            $report = $report->withRun(new RunSettings(
                // The report's name for the project, then Composer's, which no lockrot config renames.
                $lockrot->project() ?? $project->name(),
                $project->name(),
                $lockrot->targetPhp(),
                $lockrot->targetPhpSource(),
                $lockPath,
                $failOn,
                $lockrot->failOnSource(),
                $lockrot->thresholds(),
                // The project's require.php, which the page and --explain test branch rows against.
                $project->requirePhp(),
                $lockrot->strictNetwork(),
                $generate ? Gate::MODE_GENERATE_BASELINE : Gate::MODE_CHECK,
                $lockrot->includeDev()
            ));

            $page = $analysis === null
                ? null
                : new PageData($analysis, $lockrot->thresholds(), $lockrot->targetPhp(), $project->requirePhp(), $this->htmlReads);
            $showAll = $input->getOption('all') === true;
            // The annotation formats name the lock relative to the directory lockrot runs in, as the
            // checkout does. A file has no terminal, so a table in one uses the default width: the
            // same file on a laptop and on a CI runner.
            $fileContext = FormatContext::forFailOn($lockPath, $failOn, Version::STRING, FormatContext::DEFAULT_WIDTH, $cwd);

            if ($generate) {
                // Before the baseline, so an exit 2 from a report never follows a replaced baseline.
                $this->writeReports($output, $targets, $report, $fileContext, $page, $showAll);

                return $this->generateBaseline($output, $baselineFile, $report, $existingBaseline);
            }
            if ($existingBaseline !== null) {
                $report = $report->withBaseline(BaselineComparison::compare(
                    $existingBaseline,
                    $report,
                    $baselineFile->reportedPath(),
                    self::lockPackageNames($lock)
                ));
            }

            // The annotation formats point back at the lock: an unreadable one throws
            // ConfigException here, which is exit 2.
            $context = FormatContext::forFailOn($lockPath, $failOn, Version::STRING, TerminalWidth::detect($env, $this->getApplication()), $cwd);
            // Written raw: lockrot renders console markup itself (see ConsoleMarkup), so a `<` in a
            // constraint or a package name reaches the terminal or the parser untouched.
            $rendered = Formatters::for($format, $context, $page)->format($report, $showAll);
            $output->write(
                Formatters::carriesConsoleMarkup($format) ? ConsoleMarkup::render($rendered, $output->isDecorated()) : $rendered,
                false,
                OutputInterface::OUTPUT_RAW
            );
            // After stdout, so the report is in the log even when a file cannot be written.
            $this->writeReports($output, $targets, $report, $fileContext, $page, $showAll);

            return self::gateOf($report)->fails() ? Policy::EXIT_FINDINGS : Policy::EXIT_OK;
        } catch (ConfigException $e) {
            // Raw: a config error can quote the project's own keys (see ProjectConfig) or a path,
            // which are text, not console markup.
            $this->writeFailure($output, 'lockrot: '.$e->getMessage());

            return Policy::EXIT_ERROR;
        } catch (\Throwable $e) {
            $this->writeFailure($output, 'lockrot failed: '.$e->getMessage());
            if ($io->isVerbose()) {
                $this->writeError($output, $e->getTraceAsString());
            }

            return Policy::EXIT_ERROR;
        } finally {
            $this->restoreEnv();
        }
    }

    /** `--explain <package>`: docs/configuration.md#explaining-one-package. */
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
        // Both come from the same pass, so one is null exactly when the other is: the check
        // narrows the types.
        if ($finding === null || $facts === null) {
            throw new ConfigException($name.' was not analysed');
        }
        $report = $analysis->report()->withRun(new RunSettings(
            $lockrot->project() ?? $project->name(),
            $project->name(),
            $lockrot->targetPhp(),
            $lockrot->targetPhpSource(),
            null,
            FailOn::fromString($lockrot->failOn()),
            $lockrot->failOnSource(),
            $lockrot->thresholds(),
            $project->requirePhp(),
            $lockrot->strictNetwork(),
            Gate::MODE_CHECK,
            $lockrot->includeDev()
        ));
        $explanation = new Explanation($finding, $facts, $lockrot->thresholds(), $lockrot->targetPhp(), $report, $project->requirePhp());
        $formatter = new ExplainFormatter();
        $output->write(
            $format === 'json' ? $formatter->json($explanation) : ConsoleMarkup::render($formatter->text($explanation), $output->isDecorated()),
            false,
            OutputInterface::OUTPUT_RAW
        );

        return Policy::EXIT_OK;
    }

    /**
     * Null when the default baseline file does not exist. A missing explicit path is a
     * configuration error: a typo must not turn a gated build into an ungated one. An unreadable
     * file is an error too (docs/baseline.md#when-lockrot-cannot-read-the-file).
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
     * `--generate-baseline`: docs/baseline.md#what-generate-baseline-does. `--strict-network` still
     * applies, because a baseline written from metadata that never arrived accepts findings
     * that lockrot could not check.
     */
    private function generateBaseline(OutputInterface $output, BaselineFile $file, Report $report, ?Baseline $existing): int
    {
        $baseline = Baseline::fromReport($report, $existing);
        $file->write($baseline);
        $this->writeError($output, \sprintf(
            'lockrot: baseline written to %s (%d findings)',
            $file->displayPath(),
            $baseline->count()
        ));

        return self::gateOf($report)->fails() ? Policy::EXIT_FINDINGS : Policy::EXIT_OK;
    }

    /** The gate that the run's documents write is also the exit code. */
    private static function gateOf(Report $report): Gate
    {
        $gate = $report->gate();
        if ($gate === null) {
            throw new \LogicException('the report carries no run, so it has no gate');
        }

        return $gate;
    }

    private function writeReports(OutputInterface $output, ReportTargets $targets, Report $report, FormatContext $context, ?PageData $page, bool $showAll): void
    {
        $targets->write(
            $report,
            $context,
            $page,
            $showAll,
            function (ReportTarget $target) use ($output): void {
                $this->writeError($output, 'lockrot: '.$target->format().' report written to '.$target->displayPath());
            }
        );
    }

    /**
     * The files that an `--output` must not name, besides each composer.json and composer.lock that
     * {@see ReportTargets} refuses by name: docs/configuration.md#writing-reports-to-files.
     * The order sets the reason that a refusal prints: the working directory's composer.json and
     * composer.lock come before the pair that `COMPOSER` names, so without `COMPOSER` they keep the
     * plain reason. A path entry also catches a second name of the file on disk.
     *
     * @return list<array{0: string, 1: string}> path, reason
     */
    private static function protectedFiles(string $cwd, string $composerFile, string $lockPath, BaselineFile $baseline, ProjectConfig $project): array
    {
        $configured = $project->lockrotExtra()['baseline'] ?? null;
        $baselineReason = 'that is the baseline file, which lockrot writes only with --generate-baseline';

        return [
            [$baseline->path(), $baselineReason],
            [BaselineFile::resolve(\dirname($composerFile), \is_string($configured) ? $configured : null)->path(), $baselineReason],
            [$cwd.'/composer.json', ReportTargets::COMPOSER_REASON],
            [$cwd.'/composer.lock', ReportTargets::COMPOSER_REASON],
            [$composerFile, 'that is the manifest COMPOSER names, which lockrot never writes'],
            [$lockPath, 'that is the lock COMPOSER names, which lockrot never writes'],
        ];
    }

    /**
     * Drops a value that is not a string: only the console library can produce one.
     *
     * @return list<string>
     */
    private static function outputSpecs(InputInterface $input): array
    {
        $specs = [];
        foreach ((array) $input->getOption('output') as $spec) {
            if (\is_string($spec)) {
                $specs[] = $spec;
            }
        }

        return $specs;
    }

    /**
     * Every package the lock holds, `packages-dev` included whatever `--dev` says. A baselined
     * package that is `ok`, or that this run did not analyse, is still in the lock, so it is not
     * stale. {@see InstallTimeSummary::withBaseline()} measures the same way.
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

    /**
     * Written past Symfony's tag formatter: $message quotes text that is not console markup, such
     * as a path, an exception message or a stack trace ({@see TerminalText}).
     */
    private function writeError(OutputInterface $output, string $message): void
    {
        self::errorOutput($output)->writeln($message, OutputInterface::OUTPUT_RAW);
    }

    /** Raw for the reason of writeError(). */
    private function writeWarning(OutputInterface $output, string $line): void
    {
        $target = self::errorOutput($output);
        $target->writeln(TerminalText::warning($line, $target->isDecorated()), OutputInterface::OUTPUT_RAW);
    }

    /** Raw for the reason of writeError(). */
    private function writeFailure(OutputInterface $output, string $text): void
    {
        $target = self::errorOutput($output);
        $target->writeln(TerminalText::error($text, $target->isDecorated()), OutputInterface::OUTPUT_RAW);
    }

    private static function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
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
     * Reuses the project's Composer configuration and its repositories, in their order. Without a
     * Composer instance (a lock-only directory, the standalone PHAR) it uses Composer's defaults.
     * The repositories are rebuilt from Config, not taken from the instance's RepositoryManager: in
     * plugin mode its HttpDownloader predates this command, so --offline could not reach it.
     *
     * @return array{0: Config, 1: list<RepositoryInterface>}
     */
    private function composerBootstrap(IOInterface $io, bool $hasComposerJson): array
    {
        $composer = $this->resolveComposer($hasComposerJson);
        $config = $composer instanceof Composer ? $composer->getConfig() : Factory::createConfig($io);
        // Pass the project's EventDispatcher so plugins on PRE_FILE_DOWNLOAD/POST_FILE_DOWNLOAD
        // (mirror, proxy, CDN) still see lockrot's metadata requests. The lock-only path has no
        // plugins.
        $eventDispatcher = $composer instanceof Composer ? $composer->getEventDispatcher() : null;
        $manager = RepositoryFactory::manager($io, $config, Factory::createHttpDownloader($io, $config), $eventDispatcher);

        return [$config, array_values(RepositoryFactory::defaultRepos($io, $config, $manager))];
    }

    /**
     * The manifest that Composer reads (`COMPOSER`, else composer.json) as an absolute path, so the
     * lock, the baseline and every message point at the file that Composer uses.
     * {@see InstallTimeSummary} resolves it the same way.
     */
    private static function composerFile(): string
    {
        $file = Factory::getComposerFile();
        // Composer's default is `./composer.json`. Without the `./`, every path built from it reads
        // the way the working directory does.
        if (strpos($file, './') === 0) {
            $file = substr($file, 2);
        }

        return Path::resolve((string) getcwd(), $file);
    }

    private function resolveComposer(bool $hasComposerJson): ?Composer
    {
        if (!$hasComposerJson) {
            return null;
        }

        // Composer 2.3 and later has tryComposer(). Composer 2.2 LTS has only getComposer(bool $required).
        // @phpstan-ignore function.alreadyNarrowedType (the guard is for Composer 2.2 LTS)
        $composer = method_exists($this, 'tryComposer') ? $this->tryComposer() : $this->getComposer(false);

        return $composer instanceof Composer ? $composer : null;
    }
}
