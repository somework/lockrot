<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Config;
use Composer\Factory;
use Composer\Installer\InstallerEvent;
use Composer\IO\IOInterface;
use Composer\Repository\RepositoryInterface;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineFile;
use Lockrot\Clock;
use Lockrot\Config\Gate;
use Lockrot\Config\LockrotConfig;
use Lockrot\Config\UnknownKeys;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Deadline;
use Lockrot\Exception\ConfigException;
use Lockrot\Exception\InstallBlockedException;
use Lockrot\Lock\LockedPackage;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\ConsoleMarkup;
use Lockrot\Output\InstallSummaryFormatter;
use Lockrot\Output\RunGateText;
use Lockrot\Output\TerminalText;
use Lockrot\Solver\MoveCheckers;
use Lockrot\Verdict\MoveChecks;
use Lockrot\Version;

/**
 * Runs on InstallerEvents::PRE_OPERATIONS_EXEC and prints the install-time block, see
 * docs/install-time.md.
 *
 * During `update` and `require`, the project's ComposerRepository instances fetched the metadata
 * of these packages, and each one serves a file that it fetched in this process without a
 * request. A cold `composer install` runs under {@see LockrotConfig::installTimeBudgetSeconds()}.
 *
 * @internal
 */
final class InstallTimeSummary
{
    /** @var callable(IOInterface, Config, list<RepositoryInterface>, LockrotConfig, Tokens, Clock, Deadline, ?string, (\Closure(): MoveChecks)|null, ?string): Analyzer */
    private $analyzerFactory;
    /** @var null|callable(): float */
    private $monotonicNow;

    /**
     * @param null|callable(IOInterface, Config, list<RepositoryInterface>, LockrotConfig, Tokens, Clock, Deadline, ?string, (\Closure(): MoveChecks)|null, ?string): Analyzer $analyzerFactory
     * @param null|callable(): float $monotonicNow monotonic seconds, hrtime() when null
     */
    public function __construct(?callable $analyzerFactory = null, ?callable $monotonicNow = null)
    {
        $this->analyzerFactory = $analyzerFactory ?? [ServiceFactory::class, 'createAnalyzer'];
        $this->monotonicNow = $monotonicNow;
    }

    public function onPreOperationsExec(InstallerEvent $event): void
    {
        $io = $event->getIO();
        try {
            $this->run($event);
        } catch (InstallBlockedException $e) {
            // The one failure the project asked for: install-time-strict stops the install.
            throw $e;
        } catch (\Throwable $e) {
            // An unexpected failure must not break an install: it becomes one stderr line and the
            // install continues. Exception messages can embed newlines, so oneLine() collapses them.
            self::writeWarning($io, 'lockrot: install-time check skipped: '.$this->oneLine($e->getMessage()));
        }
    }

    /**
     * Raw output skips Composer's own sanitising (see {@see self::writeWarning()}), so
     * {@see TerminalText::neutralise()} removes what a terminal obeys.
     */
    private function oneLine(string $message): string
    {
        return TerminalText::neutralise(trim((string) preg_replace('/\s+/', ' ', $message)));
    }

    /**
     * The line is raw, past Symfony's tag formatter, because it quotes the project's own text,
     * which is not console markup (see {@see TerminalText}). IOInterface says only whether stdout is
     * decorated, so stderr is assumed to match. writeErrorRaw() needs Composer 2.2 or later.
     */
    private static function writeWarning(IOInterface $io, string $line): void
    {
        $io->writeErrorRaw(TerminalText::warning($line, $io->isDecorated()));
    }

    private function run(InstallerEvent $event): void
    {
        $env = getenv();
        if (LockrotConfig::isDisabledByEnvironment($env)) {
            // This runs before extra.lockrot is read, so that a malformed config cannot print a
            // "check skipped" line under LOCKROT_DISABLE.
            return;
        }
        $composerFile = Factory::getComposerFile();
        $project = ProjectConfig::fromFile($composerFile);
        $lockrot = LockrotConfig::fromSources($project->lockrotExtra(), $env, [], \PHP_VERSION, $project->platformPhp());
        if ($lockrot->isDisabled() || !$lockrot->installTime()) {
            return;
        }

        $transaction = $event->getTransaction();
        if ($transaction === null) {
            return;
        }
        $packages = TransactionPackages::fromTransaction($transaction);
        if ($packages === []) {
            return;
        }
        // The warning passes the same gates as the analysis. It prints before the analysis, does
        // not wait for a finding, and never stops an install.
        foreach (UnknownKeys::warnings($project->lockrotExtra()) as $warning) {
            self::writeWarning($event->getIO(), 'lockrot: '.$warning);
        }

        // `composer require` and `update` write the new lock before this event fires, so the file is
        // normally the post-transaction state. `--dry-run` writes no lock, and a new project has none.
        // LockFile::withPackages() overlays the transaction's packages in every case, so each
        // package gets a node to chain through.
        $lockPath = Factory::getLockFile($composerFile);
        $lock = (is_file($lockPath) ? LockFile::fromFile($lockPath) : LockFile::empty())->withPackages($packages);
        $packages = self::withDevFlagsFrom($lock, $packages);

        $deadline = Deadline::inSeconds((float) $lockrot->installTimeBudgetSeconds(), $this->monotonicNow);
        $composer = $event->getComposer();
        $config = $composer->getConfig();
        $repositories = array_values($composer->getRepositoryManager()->getRepositories());

        // The check counts against the same budget: a move that it reaches too late is not checked.
        $checks = static fn (): MoveChecks => MoveChecks::of(MoveCheckers::forProject($composer->getPackage(), $lock, $project, $config, $repositories, $lockrot->targetPhp(), $lockrot->offline(), $deadline, $lockrot->advisoryLookup()));
        $analyzer = AnalyzerBootstrap::create($this->analyzerFactory, $event->getIO(), $config, $repositories, $project, $lockrot, $env, $deadline, $checks, $lock->platform()['php'] ?? null);

        $report = $analyzer->analyzePackages($packages, $lock, $project, $event->isDevMode());
        // The baseline reaches the gate only: the block still reports everything that this
        // transaction brings in.
        // The run goes first: the baseline comparison reads its target PHP.
        $report = $this->withBaseline($report->withRun(new RunSettings(
            $lockrot->project(),
            $project->name(),
            $lockrot->targetPhp(),
            $lockrot->targetPhpSource(),
            $lockPath,
            $lockrot->failOn(),
            $lockrot->failOnSource(),
            $lockrot->thresholds(),
            $project->requirePhp(),
            $lockrot->strictNetwork(),
            Gate::MODE_CHECK,
            $event->isDevMode()
        )), \dirname($composerFile), $lockrot, $lock);
        $lines = (new InstallSummaryFormatter())->format($report);
        if ($lines !== []) {
            // Raw, rendered by lockrot: the block quotes the lock, which is not console markup
            // (see ConsoleMarkup).
            $decorated = $event->getIO()->isDecorated();
            $event->getIO()->writeErrorRaw(array_map(static fn (string $line): string => ConsoleMarkup::render($line, $decorated), $lines));
        }

        // isExecutingOperations() is false for a dry run, which has nothing to block.
        $gate = $report->gate();
        $run = $report->run();
        if ($lockrot->installTimeStrict() && $event->isExecutingOperations() && $gate !== null && $run !== null && $gate->fails()) {
            throw new InstallBlockedException('lockrot: install-time-strict stopped the install; '.RunGateText::render($gate, $run).'; run composer lockrot for details');
        }
    }

    /**
     * A transaction cannot say which section a package belongs to, so every entry arrives as prod
     * ({@see TransactionPackages::fromTransaction()}). Without this flag a `composer require --dev`
     * package ranks one priority step too high. The merged lock carries the flag. Under `--dry-run`
     * or in a project with no lock, the package stays prod ({@see LockFile::withPackages()}).
     *
     * @param list<LockedPackage> $packages
     *
     * @return list<LockedPackage>
     */
    private static function withDevFlagsFrom(LockFile $lock, array $packages): array
    {
        $out = [];
        foreach ($packages as $package) {
            $merged = $lock->find($package->name());
            $out[] = $merged === null ? $package : $package->withDev($merged->isDev());
        }

        return $out;
    }

    /**
     * A configured baseline that is missing, or a file that cannot be read, throws, and
     * onPreOperationsExec() turns that into the "install-time check skipped" line, never a blocked
     * install. Staleness is measured against the whole lock, because a transaction touches a few
     * packages and the rest of the lock is present, not gone.
     */
    private function withBaseline(Report $report, string $projectDir, LockrotConfig $lockrot, LockFile $lock): Report
    {
        $file = BaselineFile::resolve($projectDir, $lockrot->baseline(), Version::STRING);
        if (!$file->exists()) {
            if ($lockrot->baseline() !== null) {
                throw new ConfigException($file->displayPath().' not found');
            }

            return $report;
        }

        $names = [];
        foreach ($lock->packages(true) as $package) {
            $names[] = $package->name();
        }

        return $report->withBaseline(BaselineComparison::compare($file->read(), $report, $file->reportedPath(), $names));
    }
}
