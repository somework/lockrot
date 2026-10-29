<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\Config;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
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
use Lockrot\Output\RunGateText;
use Lockrot\Output\TerminalText;
use Lockrot\Output\TerminalWidth;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveCheckers;
use Lockrot\Solver\UncheckedMoves;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\MoveChecks;
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
    public const UPGRADE_NOTICE = 'lockrot 0.14: the verdict is now a grade, the cause word is in .lead, documents are report-2/baseline-2; see https://lockrot.dev/changes/0.14/';

    /** The formats whose stdout is a document: a failing gate also gets a stderr line. */
    private const DOCUMENT_FORMATS = ['json', 'sarif', 'gitlab', 'html'];

    /**
     * The options whose repeat is exit 2, an identical repeat included: a later value silently
     * changes the gate. Every other option keeps the console's rule that the last value wins.
     */
    public const ONCE_ONLY_OPTIONS = ['fail-on', 'baseline', 'target-php'];

    /** The formats a CI step parses: writing one prints the upgrade notice. */
    private const NOTICE_FORMATS = ['json', 'sarif', 'gitlab'];

    private const MANIFEST_REASON = 'that is the manifest COMPOSER names, which lockrot never writes';
    private const LOCK_REASON = 'that is the lock COMPOSER names, which lockrot never writes';

    /** @var callable(IOInterface, Config, list<RepositoryInterface>, LockrotConfig, Tokens, Clock, Deadline, ?string, (\Closure(): MoveChecks)|null, ?string): Analyzer */
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
     * @param null|callable(IOInterface, Config, list<RepositoryInterface>, LockrotConfig, Tokens, Clock, Deadline, ?string, (\Closure(): MoveChecks)|null, ?string): Analyzer $analyzerFactory
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
            ->setDescription('Shows dependency rot in composer.lock: abandoned, silent, pinned, left-behind, old-promise, stale, capped and vulnerable packages, each with a score and a grade')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, json, github (workflow annotations), sarif (SARIF 2.1.0), gitlab (Code Quality JSON), markdown (PR comment) or html (one self-contained page)')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Exit 1 when a finding reaches one of these values, comma-separated, at most one of each kind: a grade (critical, high, medium, low), a flag (abandoned, silent, pinned, left-behind, old-promise, stale, capped), an advisory severity (vulnerable, vulnerable-medium, vulnerable-high, vulnerable-critical) or `unchecked`, a finding whose check did not run. The default `none` never fails')
            ->addOption('target-php', null, InputOption::VALUE_REQUIRED, 'PHP version the project targets, e.g. 8.4 (default: config.platform.php or the running PHP)')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Include packages-dev')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Show every package, not only graded ones')
            ->addOption('offline', null, InputOption::VALUE_NONE, 'Use cached data only, never go to the network')
            ->addOption('strict-network', null, InputOption::VALUE_NONE, 'Exit 1 when a repository or a repository host (GitHub, GitLab, Bitbucket) could not be reached')
            ->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'Baseline file to read or write (default: lockrot-baseline.json next to composer.json)')
            ->addOption('generate-baseline', null, InputOption::VALUE_NONE, 'Write the current findings to the baseline file. It exits 1 only when --strict-network trips the gate')
            ->addOption('explain', null, InputOption::VALUE_REQUIRED, 'Explain one package: its verdict, every signal with its raw data, and the repository facts they were read from (text, or JSON with --format=json); exit 0')
            ->addOption('output', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Also write the report to a file, as <format>:<path> (e.g. sarif:lockrot.sarif), in any format --format takes; repeatable. A relative path is relative to the project directory lockrot runs in; the directory must exist. --format still decides stdout')
            ->addOption('markdown-max-bytes', null, InputOption::VALUE_REQUIRED, 'Keep each markdown report written through --output within this many bytes: rows are left out from the end, and a last row counts them. The heading, the table head, that row and the footer always print');
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

    /** The rules of the command line: docs/configuration.md#cli-options. */
    protected function inputRefusal(InputInterface $input): ?string
    {
        $repeated = $this->repeatedOption($input, self::ONCE_ONLY_OPTIONS);
        if ($repeated !== null || !\is_string($input->getOption('explain'))) {
            return $repeated;
        }
        if (self::outputSpecs($input) !== []) {
            return '--explain prints one package on stdout and --output writes whole-run reports; run them separately';
        }
        if ($input->getOption('generate-baseline') === true) {
            return '--explain prints one package and --generate-baseline writes the whole run\'s baseline; run them separately';
        }

        return null;
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
     * Sets Composer's default root version up front, unless COMPOSER_ROOT_VERSION holds one. That
     * skips the VersionGuesser probe of git, hg, fossil and svn, and its warning "could not detect
     * the root package version". The move check solves the root at this version, so a `self.version`
     * link reads 1.0.0. Call it before parent::initialize(). In plugin mode
     * Composer already built its instance, so this takes effect only without one.
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
            $hasComposerJson = is_file($composerFile);
            // LOCKROT_DISABLE needs no check here: run() answered it before any of this was read.
            $lockrot = LockrotConfig::fromSources($project->lockrotExtra(), $env, $this->cliOptions($input), \PHP_VERSION, $project->platformPhp());
            [$config, $repositories, $root] = $this->composerBootstrap($io, $hasComposerJson, $lockrot->offline());
            if (self::wantsUpgradeNotice($input, $lockrot)) {
                $this->writeError($output, self::UPGRADE_NOTICE);
            }
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
            $lockName = FormatContext::lockNameOf($lockPath, $cwd);
            $lock = LockFile::fromFile($lockPath, $lockName);
            $explain = $input->getOption('explain');
            $specs = self::outputSpecs($input);
            // Resolving the path touches nothing, and the files that --output must not name include it.
            $baselineFile = BaselineFile::resolve(\dirname($composerFile), $lockrot->baseline(), Version::STRING);
            $generate = $input->getOption('generate-baseline') === true;
            if ($generate) {
                self::refuseComposerBaseline($input, $lockrot, $baselineFile, $composerFile, $lockPath);
            }
            // Checked before the analysis, like the baseline: a typo in a path fails before a
            // full repository round.
            $targets = ReportTargets::resolve($specs, $cwd, $specs === [] ? [] : self::protectedFiles($cwd, $composerFile, $lockPath, $baselineFile, $project));
            $markdownMaxBytes = self::markdownMaxBytes($input);
            // No time budget, unlike the install-time summary (docs/install-time.md#time-budget).
            $checks = static fn (): MoveChecks => MoveChecks::of($root === null ? new UncheckedMoves($hasComposerJson ? MoveCheck::COMPOSER_JSON_INVALID : MoveCheck::NO_COMPOSER_JSON) : MoveCheckers::forProject($root, $lock, $project, $config, $repositories, $lockrot->targetPhp(), $lockrot->offline(), Deadline::never(), $lockrot->advisoryLookup()));
            $analyzer = AnalyzerBootstrap::create($this->analyzerFactory, $io, $config, $repositories, $project, $lockrot, $env, Deadline::never(), $checks, $lock->platform()['php'] ?? null);
            // Before the baseline is read: an explanation ignores it, so a missing or unreadable
            // baseline must not block it.
            if (\is_string($explain)) {
                return $this->explain($output, $explain, $analyzer, $lock, $lockName, $project, $lockrot);
            }
            // Read before the analysis, so a missing explicit path or an unreadable file fails before
            // a full repository round. A generate run can name a file that does not exist: it creates it.
            $existingBaseline = $this->readBaseline($baselineFile, $lockrot->baseline() !== null && !$generate, $generate);
            $format = $lockrot->format();
            // Only `html` needs the facts: it draws a release-branch timeline per package.
            $analysis = $format === 'html' || $targets->wants('html')
                ? $analyzer->analyzeWithFacts($lock->packages($lockrot->includeDev()), $lock, $project, $lockrot->includeDev())
                : null;
            $report = $analysis === null ? $analyzer->analyze($lock, $project, $lockrot->includeDev()) : $analysis->report();

            // One threshold for the report's gate and for the annotation formats.
            $failOn = $lockrot->failOn();
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
            $fileContext = FormatContext::forFailOn($lockPath, $failOn, Version::STRING, FormatContext::DEFAULT_WIDTH, $cwd)->withMarkdownMaxBytes($markdownMaxBytes);

            // A generate run compares with the file it read before it rewrites it, as a check run would.
            if ($existingBaseline !== null) {
                $report = $report->withBaseline(BaselineComparison::compare(
                    $existingBaseline,
                    $report,
                    $baselineFile->reportedPath(),
                    self::lockPackageNames($lock)
                ));
            }
            if ($generate) {
                // Before the baseline, so an exit 2 from a report never follows a replaced baseline.
                $this->writeReports($output, $targets, $report, $fileContext, $page, $showAll);

                return $this->generateBaseline($output, $baselineFile, $report, $existingBaseline);
            }

            // The annotation formats point back at the lock: an unreadable one throws
            // ConfigException here, which is exit 2.
            $context = FormatContext::forFailOn($lockPath, $failOn, Version::STRING, TerminalWidth::detect($env), $cwd);
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
            $gate = self::gateOf($report);
            // A document on stdout says nothing a CI log shows: this line says why the job exits 1.
            if ($gate->fails() && \in_array($format, self::DOCUMENT_FORMATS, true)) {
                $this->writeError($output, 'lockrot: '.$this->gateText($report, $gate));
            }

            return Policy::exitCode($gate);
        } catch (ConfigException $e) {
            // Raw: a config error can quote the project's own keys (see ProjectConfig) or a path,
            // which are text, not console markup.
            $this->writeFailure($output, 'lockrot: '.$e->getMessage());
            if ($io->isVerbose()) {
                foreach ($e->details() as $detail) {
                    $this->writeError($output, $detail);
                }
            }

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
    private function explain(OutputInterface $output, string $name, Analyzer $analyzer, LockFile $lock, string $lockName, ProjectConfig $project, LockrotConfig $lockrot): int
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
            throw new ConfigException($name.' is not in '.$lockName);
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
            $lockrot->failOn(),
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
     * file is an error too (docs/baseline.md#when-lockrot-cannot-read-the-file). A generate run
     * never reads a baseline-1 file: it writes a fresh baseline-2 and carries nothing forward.
     */
    private function readBaseline(BaselineFile $file, bool $explicit, bool $generate): ?Baseline
    {
        if (!$file->exists()) {
            if ($explicit) {
                throw new ConfigException($file->displayPath().' not found');
            }

            return null;
        }
        if ($generate && $file->schemaNumber() === 1) {
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
        foreach ($baseline->carriedHolding() as $kept => $carried) {
            $this->writeError($output, \sprintf(
                'lockrot: the advisory lookup did not complete, so the baseline keeps the recorded %s of %d %s: %s',
                $kept,
                \count($carried),
                \count($carried) === 1 ? 'package' : 'packages',
                implode(', ', $carried)
            ));
        }
        $this->writeError($output, \sprintf(
            'lockrot: baseline written to %s (%d findings)',
            $file->displayPath(),
            $baseline->count()
        ));
        $gate = self::gateOf($report);
        if ($gate->fails()) {
            $this->writeError($output, 'lockrot: '.$this->gateText($report, $gate));
        }

        return Policy::exitCode($gate);
    }

    /** `gate.text`, the `gate:` line that every format prints. */
    private function gateText(Report $report, Gate $gate): string
    {
        $run = $report->run();
        if ($run === null) {
            throw new \LogicException('the report carries no run, so it has no gate');
        }

        return RunGateText::render($gate, $run);
    }

    /**
     * The upgrade notice of the 0.14.x line: a run with a gate active, or one that writes
     * json, sarif, gitlab or a baseline. lockrot keeps no state, so it prints on every such run.
     */
    private static function wantsUpgradeNotice(InputInterface $input, LockrotConfig $lockrot): bool
    {
        if ($input->getOption('generate-baseline') === true || \in_array($lockrot->format(), self::NOTICE_FORMATS, true)) {
            return true;
        }
        foreach (self::outputSpecs($input) as $spec) {
            if (\in_array(strstr($spec, ':', true), self::NOTICE_FORMATS, true)) {
                return true;
            }
        }

        return !\is_string($input->getOption('explain')) && $lockrot->failOn()->value() !== FailOn::NONE;
    }

    /** @throws ConfigException for a value that is not a whole number of bytes above zero */
    private static function markdownMaxBytes(InputInterface $input): ?int
    {
        $bytes = $input->getOption('markdown-max-bytes');
        if ($bytes === null) {
            return null;
        }
        if (!\is_string($bytes) || preg_match('/^[1-9][0-9]{0,17}$/D', $bytes) !== 1) {
            throw new ConfigException('--markdown-max-bytes must be a whole number of bytes above zero');
        }

        return (int) $bytes;
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
            [BaselineFile::resolve(\dirname($composerFile), \is_string($configured) ? $configured : null, Version::STRING)->path(), $baselineReason],
            [$cwd.'/composer.json', ReportTargets::COMPOSER_REASON],
            [$cwd.'/composer.lock', ReportTargets::COMPOSER_REASON],
            [$composerFile, self::MANIFEST_REASON],
            [$lockPath, self::LOCK_REASON],
        ];
    }

    /**
     * A generated baseline can create its file, so it must not be a composer file or a file that
     * COMPOSER names: SECURITY.md, "What lockrot does and does not do".
     *
     * @throws ConfigException naming the option, the key or the default file that gave the path
     */
    private static function refuseComposerBaseline(InputInterface $input, LockrotConfig $lockrot, BaselineFile $baseline, string $composerFile, string $lockPath): void
    {
        $source = \is_string($input->getOption('baseline')) ? '--baseline=' : ($lockrot->baseline() !== null ? 'extra.lockrot.baseline ' : 'the baseline ');
        $label = $source.$baseline->displayPath();
        ReportTargets::refuseComposerFile($baseline->path(), $label);
        foreach ([[$composerFile, self::MANIFEST_REASON], [$lockPath, self::LOCK_REASON]] as [$path, $reason]) {
            if (Path::canonical($path) === Path::canonical($baseline->path()) || Path::sameFile($baseline->path(), $path)) {
                throw new ConfigException($label.': '.$reason);
            }
        }
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
     * Written past Symfony's tag formatter and neutralised: $message quotes text from outside lockrot
     * that is not console markup, such as a path, an exception message or a stack trace ({@see TerminalText}).
     */
    private function writeError(OutputInterface $output, string $message): void
    {
        self::errorOutput($output)->writeln(TerminalText::neutraliseLogLines($message), OutputInterface::OUTPUT_RAW);
    }

    /** Raw for the reason of writeError(). */
    private function writeWarning(OutputInterface $output, string $line): void
    {
        $target = self::errorOutput($output);
        $target->writeln(TerminalText::warning(TerminalText::neutraliseLogLines($line), $target->isDecorated()), OutputInterface::OUTPUT_RAW);
    }

    /** Raw for the reason of writeError(). */
    private function writeFailure(OutputInterface $output, string $text): void
    {
        $target = self::errorOutput($output);
        $target->writeln(TerminalText::error(TerminalText::neutraliseLogLines($text), $target->isDecorated()), OutputInterface::OUTPUT_RAW);
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
     * The repositories are those of the instance's RepositoryManager, which `update` and `audit`
     * read, with the ones that plugins add. Offline they are rebuilt from Config: in plugin mode
     * the instance's HttpDownloader predates this command, so --offline could not reach it.
     *
     * The root package is Composer's, null without composer.json: then no move is checked.
     *
     * @return array{0: Config, 1: list<RepositoryInterface>, 2: ?RootPackageInterface}
     */
    private function composerBootstrap(IOInterface $io, bool $hasComposerJson, bool $offline): array
    {
        $composer = $this->resolveComposer($hasComposerJson);
        if ($composer instanceof Composer && !$offline) {
            return [$composer->getConfig(), array_values($composer->getRepositoryManager()->getRepositories()), $composer->getPackage()];
        }
        $config = $composer instanceof Composer ? $composer->getConfig() : Factory::createConfig($io);
        // Pass the project's EventDispatcher so plugins on PRE_FILE_DOWNLOAD/POST_FILE_DOWNLOAD
        // (mirror, proxy, CDN) still see lockrot's metadata requests. The lock-only path has no
        // plugins.
        $eventDispatcher = $composer instanceof Composer ? $composer->getEventDispatcher() : null;
        $manager = RepositoryFactory::manager($io, $config, Factory::createHttpDownloader($io, $config), $eventDispatcher);

        return [$config, array_values(RepositoryFactory::defaultRepos($io, $config, $manager)), $composer instanceof Composer ? $composer->getPackage() : null];
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

        $composer = $this->tryComposer();

        return $composer instanceof Composer ? $composer : null;
    }
}
