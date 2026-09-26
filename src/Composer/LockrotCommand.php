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
use Lockrot\Version;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `composer lockrot` — analyses composer.lock and prints a report in the configured format.
 *
 * @internal
 */
final class LockrotCommand extends BaseCommand
{
    use RejectsUnreadableInput;

    /** @var callable(IOInterface, Config, list<RepositoryInterface>, LockrotConfig, Tokens, Clock, Deadline, ?string): Analyzer */
    private $analyzerFactory;

    /**
     * Set by initialize() when the project manifest is unusable, or anything else fails before
     * Composer has been bootstrapped; rethrown inside execute(), whose catch blocks decide the line
     * and the exit code.
     */
    private ?\Throwable $bootstrapError = null;

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
     * LOCKROT_DISABLE is the off switch, documented as skipping lockrot entirely, so it is checked
     * before anything the run would read: the command line, composer.json and its extra.lockrot, the
     * option values, the lock. None of them can turn a disabled run into an exit 2.
     *
     * Then the command line is bound here, before Symfony's run() does it, so one this command
     * cannot read is exit 2 and a `lockrot:` line ({@see RejectsUnreadableInput}).
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
     * Composer's BaseCommand::initialize() bootstraps a Composer instance from the project's
     * composer.json and lets a JSON parse error escape as a Composer crash — exit 1, before
     * execute() is ever reached. lockrot documents a malformed manifest as a configuration error
     * (exit 2, README "Exit codes"), so the file is checked here first and the failure is carried
     * into execute()'s error handling instead. So is any other failure on the way there — Composer
     * refusing a COMPOSER that names a directory, say: Symfony calls initialize() outside any
     * try/catch, and nothing this command does may leave it as a Composer crash.
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
            // --output paths are relative to the directory lockrot runs in; the manifest, its lock
            // and the baseline are where Composer reads them.
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
            // The lock Composer itself would read: alt.lock beside COMPOSER=alt.json.
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
            // Resolving the path touches nothing, and the files --output may not name include it.
            $baselineFile = BaselineFile::resolve(\dirname($composerFile), $lockrot->baseline());
            // Checked before the analysis, like the baseline below: a typo in a path fails now,
            // not after a full repository round.
            $targets = ReportTargets::resolve($specs, $cwd, $specs === [] ? [] : self::protectedFiles($cwd, $composerFile, $lockPath, $baselineFile, $project));
            // `composer lockrot` is the deliberate, full run: no time budget, unlike the
            // install-time summary.
            $analyzer = AnalyzerBootstrap::create($this->analyzerFactory, $io, $config, $repositories, $project, $lockrot, $env, Deadline::never());
            // Before the baseline is even read: an explanation does not consult it, so a
            // baseline that is missing or unreadable must not stand between the question and the answer.
            if (\is_string($explain)) {
                return $this->explain($output, $explain, $analyzer, $lock, $project, $lockrot);
            }
            // Read before the analysis so a missing explicit path or an unreadable file fails
            // immediately, rather than after a full repository round. A generate run is the one case
            // where the target is allowed not to exist yet: it is about to be created.
            $generate = $input->getOption('generate-baseline') === true;
            $existingBaseline = $this->readBaseline($baselineFile, $lockrot->baseline() !== null && !$generate);
            $format = $lockrot->format();
            // `html` draws a release-branch timeline per package, which is the one thing only the
            // facts carry; every other format is happy with the report and lets them go.
            $analysis = $format === 'html' || $targets->wants('html')
                ? $analyzer->analyzeWithFacts($lock->packages($lockrot->includeDev()), $lock, $project, $lockrot->includeDev())
                : null;
            $report = $analysis === null ? $analyzer->analyze($lock, $project, $lockrot->includeDev()) : $analysis->report();

            // Before the baseline and before any formatter: every document the run writes should say
            // what the run was told to do, because that is what its verdicts were decided against.
            $report = $report->withRun(new RunSettings(
                // The report's name for the project, then Composer's, which no lockrot config renames.
                $lockrot->project() ?? $project->name(),
                $project->name(),
                $lockrot->targetPhp(),
                $lockPath,
                $lockrot->failOn(),
                $lockrot->thresholds()
            ));

            $page = $analysis === null
                ? null
                : new PageData($analysis, $lockrot->thresholds(), $lockrot->targetPhp());
            $showAll = $input->getOption('all') === true;
            // The annotation formats name the lock relative to the directory lockrot runs in, as the
            // checkout does: alt.lock under COMPOSER=alt.json, and composer.lock as ever without it.
            // A file has no terminal, so a table in one is rendered at the default width whatever
            // this run's terminal is: the same file on a laptop and on a CI runner.
            $fileContext = FormatContext::create($lockPath, $lockrot->failOn(), Version::STRING, FormatContext::DEFAULT_WIDTH, $cwd);

            if ($generate) {
                // Before the baseline, so an exit 2 from a report never follows a replaced baseline.
                $this->writeReports($output, $targets, $report, $fileContext, $page, $showAll);

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
            $context = FormatContext::create($lockPath, $lockrot->failOn(), Version::STRING, TerminalWidth::detect($env, $this->getApplication()), $cwd);
            // Every format is written raw: the one that carries console markup is rendered by
            // lockrot rather than Symfony's tag formatter (see ConsoleMarkup), every
            // machine-readable one as it is, so a `<` in a constraint or a package name reaches
            // the terminal or the parser on the other end untouched.
            $rendered = Formatters::for($format, $context, $page)->format($report, $showAll);
            $output->write(
                Formatters::carriesConsoleMarkup($format) ? ConsoleMarkup::render($rendered, $output->isDecorated()) : $rendered,
                false,
                OutputInterface::OUTPUT_RAW
            );
            // After stdout, so the report is in the log even when a file cannot be written.
            $this->writeReports($output, $targets, $report, $fileContext, $page, $showAll);

            return Policy::exitCode($report, $lockrot);
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
        $output->write(
            $format === 'json' ? $formatter->json($explanation) : ConsoleMarkup::render($formatter->text($explanation), $output->isDecorated()),
            false,
            OutputInterface::OUTPUT_RAW
        );

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

    /** The `--output` files, each followed by one line on stderr naming it, as the baseline's is. */
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
     * The files an `--output` may not name, besides every composer.json and composer.lock by name,
     * which {@see ReportTargets} refuses on its own:
     *
     * - every baseline the project names: this run's (`--baseline`, else `extra.lockrot.baseline`,
     *   else `lockrot-baseline.json`) and the project's own (`extra.lockrot.baseline`, else
     *   `lockrot-baseline.json`), which `--baseline` does not stop being the committed one — each
     *   whether or not it exists yet;
     * - the composer.json and composer.lock this run reads, so a second name for either on disk is
     *   caught wherever the report would go;
     * - the manifest Composer reads, as {@see self::composerFile()} names it (`COMPOSER`), and the
     *   lock {@see Factory::getLockFile()} pairs with it (`composer-8.json` -> `composer-8.lock`),
     *   the two this run reads. Without `COMPOSER` these are the two above, which come first and
     *   keep their reason.
     *
     * @return list<array{0: string, 1: string}>
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
     * Every `--output` value. The option is declared as an array of values, so anything else is
     * a console library's doing, not the user's, and is dropped.
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

    /**
     * One line on stderr, written past Symfony's tag formatter: $message is lockrot's words around
     * text it did not write — a path, a package name, an exception's message, a stack trace — and
     * none of that is console markup (see {@see TerminalText}), so a `<` in it prints as given and
     * nothing needs escaping.
     */
    private function writeError(OutputInterface $output, string $message): void
    {
        self::errorOutput($output)->writeln($message, OutputInterface::OUTPUT_RAW);
    }

    /**
     * $line on stderr in the `warning` colours, past Symfony's tag formatter: it quotes the
     * project's text, which is not console markup (see {@see TerminalText}).
     */
    private function writeWarning(OutputInterface $output, string $line): void
    {
        $target = self::errorOutput($output);
        $target->writeln(TerminalText::warning($line, $target->isDecorated()), OutputInterface::OUTPUT_RAW);
    }

    /** $text on stderr in the `error` colours, past the formatter for the same reason. */
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

    /**
     * The manifest Composer itself reads — the one the COMPOSER environment variable names, else
     * composer.json — as an absolute path, so the lock beside it, the baseline next to it and every
     * message naming it point at the file Composer would use (`COMPOSER=alt.json` means alt.json and
     * alt.lock, exactly as `composer install` reads them). {@see InstallTimeSummary} resolves it the
     * same way, and the files `--output` may not name are derived from it ({@see self::protectedFiles()}).
     */
    private static function composerFile(): string
    {
        $file = Factory::getComposerFile();
        // Composer's default is `./composer.json`; without the `./` every path built from it reads
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

        // Composer >= 2.3 has tryComposer(); 2.2 LTS only has getComposer(bool $required).
        // @phpstan-ignore function.alreadyNarrowedType (tryComposer() does not exist in Composer 2.2 LTS; guard is load-bearing there)
        $composer = method_exists($this, 'tryComposer') ? $this->tryComposer() : $this->getComposer(false);

        return $composer instanceof Composer ? $composer : null;
    }
}
