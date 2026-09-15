<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Config;
use Composer\Factory;
use Composer\Installer\InstallerEvent;
use Composer\IO\IOInterface;
use Composer\Repository\RepositoryInterface;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Config\LockrotConfig;
use Lockrot\Config\Policy;
use Lockrot\Deadline;
use Lockrot\Exception\InstallBlockedException;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\InstallSummaryFormatter;

/**
 * The `composer require`/`update`/`install` half of lockrot: on InstallerEvents::PRE_OPERATIONS_EXEC
 * it analyses only the packages the transaction is about to install or update and prints a compact
 * block to Composer's error output.
 *
 * Composer fires the event before it prints its own "Package operations: …" line (2.10.3
 * Installer.php:838 vs :851-862, 2.2.25 :723 vs :741-749), so the block appears above that list.
 *
 * Two properties make this affordable. The analysis runs against the project's *own*
 * RepositoryManager repositories: during `composer update`/`require` those ComposerRepository
 * instances have just fetched metadata for exactly these packages, and each one remembers the files
 * it fetched in this process (`freshMetadataUrls`, 2.10.3 Repository/ComposerRepository.php:133,
 * short-circuit at :1916-1922; 2.2.25 :115, :1488-1491) — so a re-read is served without a request. And
 * a `composer install` from an existing lock, which starts cold, is bounded by
 * {@see LockrotConfig::installTimeBudgetSeconds()} ({@see LockrotConfig::DEFAULT_INSTALL_TIME_BUDGET}
 * seconds unless the project sets `extra.lockrot.install-time-budget`; SPEC F2.6).
 */
final class InstallTimeSummary
{
    /** @var callable(IOInterface, Config, list<RepositoryInterface>, LockrotConfig, ?string, Clock, Deadline): Analyzer */
    private $analyzerFactory;

    /** @param null|callable(IOInterface, Config, list<RepositoryInterface>, LockrotConfig, ?string, Clock, Deadline): Analyzer $analyzerFactory */
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
            // Never break an install by default (SPEC F6): anything unexpected — a malformed
            // extra.lockrot, an unreachable repository, a bug in lockrot itself — becomes one
            // stderr line and the install continues. Collapsed to one line: some exception
            // messages (a wrapped exception's chain, a multi-line library error) embed newlines of
            // their own, which would otherwise split this into more than the one line promised.
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
        // (Installer::doUpdate() writes it at 2.10.3 :681 / 2.2.25 :575 and only then calls
        // doInstall(), which dispatches this event at 2.10.3 :838 / 2.2.25 :723), so the file on
        // disk is normally the post-transaction state already. `--dry-run` writes no lock at all
        // (Installer::doUpdate() guards the write with writeLock && executeOperations, 2.10.3 :679),
        // so there — and for a project with no lock yet — the file on disk (or an empty lock) is the
        // pre-transaction state. Either way, LockFile::withPackages() overlays the transaction's own
        // packages onto whatever was read, so a package new to the graph always gets a node to chain
        // through, dry-run included.
        $lockPath = Factory::getLockFile($composerFile);
        $lock = (is_file($lockPath) ? LockFile::fromFile($lockPath) : LockFile::empty())->withPackages($packages);

        $deadline = Deadline::inSeconds((float) $lockrot->installTimeBudgetSeconds());
        $composer = $event->getComposer();
        $config = $composer->getConfig();
        $repositories = array_values($composer->getRepositoryManager()->getRepositories());

        $analyzer = AnalyzerBootstrap::create($this->analyzerFactory, $event->getIO(), $config, $repositories, $project, $lockrot, $env, $deadline);

        $report = $analyzer->analyzePackages($packages, $lock, $project, $event->isDevMode());
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
}
