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
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineFile;
use Lockrot\Clock;
use Lockrot\Config\LockrotConfig;
use Lockrot\Config\Policy;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Deadline;
use Lockrot\Exception\ConfigException;
use Lockrot\Exception\InstallBlockedException;
use Lockrot\Lock\LockedPackage;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\InstallSummaryFormatter;

/**
 * The `composer require`/`update`/`install` half of lockrot: on InstallerEvents::PRE_OPERATIONS_EXEC
 * it analyses only the packages the transaction is about to install or update and prints a compact
 * block to Composer's error output.
 *
 * Composer fires the event before it prints its own "Package operations: …" line, so the block
 * appears above that list.
 *
 * Two properties make this affordable. The analysis runs against the project's *own*
 * RepositoryManager repositories: during `composer update`/`require` those ComposerRepository
 * instances have just fetched metadata for exactly these packages, and each one remembers the files
 * it fetched in this process, so a re-read is served without a request. And a `composer install`
 * from an existing lock, which starts cold, is bounded by
 * {@see LockrotConfig::installTimeBudgetSeconds()} ({@see LockrotConfig::DEFAULT_INSTALL_TIME_BUDGET}
 * seconds unless the project sets `extra.lockrot.install-time-budget`).
 */
final class InstallTimeSummary
{
    /** @var callable(IOInterface, Config, list<RepositoryInterface>, LockrotConfig, Tokens, Clock, Deadline, ?string): Analyzer */
    private $analyzerFactory;

    /** @param null|callable(IOInterface, Config, list<RepositoryInterface>, LockrotConfig, Tokens, Clock, Deadline, ?string): Analyzer $analyzerFactory */
    public function __construct(?callable $analyzerFactory = null)
    {
        $this->analyzerFactory = $analyzerFactory ?? [ServiceFactory::class, 'createAnalyzer'];
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
            // Never break an install by default: anything unexpected — a malformed extra.lockrot,
            // an unreachable repository, a bug in lockrot itself — becomes one stderr line and the
            // install continues. Collapsed to one line: some exception messages (a wrapped
            // exception's chain, a multi-line library error) embed newlines of their own, which
            // would otherwise split this into more than the one line promised.
            $io->writeError('<warning>lockrot: install-time check skipped: '.$this->oneLine($e->getMessage()).'</warning>');
        }
    }

    private function oneLine(string $message): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $message));
    }

    private function run(InstallerEvent $event): void
    {
        $env = getenv();
        if (LockrotConfig::isDisabledByEnvironment($env)) {
            // Before reading extra.lockrot: LOCKROT_DISABLE must silence even the "check skipped"
            // line a malformed config would otherwise produce on every install.
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

        // By the time this event fires, `composer require`/`update` has already written the new lock
        // (Installer::doUpdate() writes it before calling doInstall(), which dispatches this event),
        // so the file on disk is normally the post-transaction state already. `--dry-run` writes no
        // lock at all, so there — and for a project with no lock yet — the file on disk (or an empty
        // lock) is the pre-transaction state. Either way, LockFile::withPackages() overlays the
        // transaction's own packages onto whatever was read, so a package new to the graph always
        // gets a node to chain through, dry-run included.
        $lockPath = Factory::getLockFile($composerFile);
        $lock = (is_file($lockPath) ? LockFile::fromFile($lockPath) : LockFile::empty())->withPackages($packages);
        $packages = self::withDevFlagsFrom($lock, $packages);

        $deadline = Deadline::inSeconds((float) $lockrot->installTimeBudgetSeconds());
        $composer = $event->getComposer();
        $config = $composer->getConfig();
        $repositories = array_values($composer->getRepositoryManager()->getRepositories());

        $analyzer = AnalyzerBootstrap::create($this->analyzerFactory, $event->getIO(), $config, $repositories, $project, $lockrot, $env, $deadline);

        $report = $analyzer->analyzePackages($packages, $lock, $project, $event->isDevMode());
        // The block itself does not change — it reports what this transaction brings in either way.
        // The comparison only reaches Policy::exitCode() below, so install-time-strict gates on what
        // the project has not already accepted.
        $report = $this->withBaseline($report, \dirname($composerFile), $lockrot, $lock);
        $lines = (new InstallSummaryFormatter())->format($report);
        if ($lines !== []) {
            $event->getIO()->writeError($lines);
        }

        // isExecutingOperations() is false for a dry run, where nothing is about to land on disk and
        // there is therefore nothing to block.
        if ($lockrot->installTimeStrict() && $event->isExecutingOperations() && Policy::exitCode($report, $lockrot) === Policy::EXIT_FINDINGS) {
            throw new InstallBlockedException(\sprintf(
                'lockrot: findings at or above fail-on=%s and install-time-strict is on; run composer lockrot for details',
                $lockrot->failOn()
            ));
        }
    }

    /**
     * The same packages, each carrying the `packages`/`packages-dev` membership the merged lock
     * records for it. A Composer transaction cannot say which section a package belongs to — see
     * {@see TransactionPackages::fromTransaction()} — so every entry arrives as prod, and a finding
     * on a `composer require --dev` package would otherwise be ranked one priority step too high.
     *
     * The merged lock is the only source that knows. By this event Composer has normally already
     * written the post-transaction lock, so the flag is the one the install is about to leave behind;
     * under `--dry-run`, or in a project with no lock yet, there is nothing on disk to read it from
     * and the package stays prod — the same limitation {@see LockFile::withPackages()} documents.
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
     * The report seen next to the project's baseline, or the report unchanged when there is no
     * baseline file. A file that exists but cannot be read throws, which onPreOperationsExec()
     * turns into the one "install-time check skipped" line — never a blocked install.
     *
     * Staleness is measured against the lock rather than against this transaction's own packages:
     * the transaction touches a handful of packages, so the rest of the lock is present, not gone.
     */
    private function withBaseline(Report $report, string $projectDir, LockrotConfig $lockrot, LockFile $lock): Report
    {
        $file = BaselineFile::resolve($projectDir, $lockrot->baseline());
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

        return $report->withBaseline(BaselineComparison::compare($file->read(), $report, $file->displayPath(), $names));
    }
}
