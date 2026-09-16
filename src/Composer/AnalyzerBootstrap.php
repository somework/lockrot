<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Repository\RepositoryInterface;
use Lockrot\Allowlist\ProjectIgnoreList;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Config\LockrotConfig;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Deadline;
use Lockrot\Lock\ProjectConfig;

/**
 * The bootstrap steps `LockrotCommand::execute()` and `InstallTimeSummary::run()` both perform
 * before they can call {@see Analyzer::analyze()}/{@see Analyzer::analyzePackages()}: resolve
 * lockrot's own tokens, build a {@see Clock} from the environment, hand both (plus the deadline) to the
 * analyzer factory, and merge the project's own `extra.lockrot.ignore` allowlist onto whatever
 * allowlist the factory built the analyzer with.
 */
final class AnalyzerBootstrap
{
    /**
     * @param callable(IOInterface, Config, list<RepositoryInterface>, LockrotConfig, Tokens, Clock, Deadline): Analyzer $analyzerFactory
     * @param list<RepositoryInterface>                                                                                 $repositories
     * @param array<string, mixed>                                                                                      $env
     */
    public static function create(
        callable $analyzerFactory,
        IOInterface $io,
        Config $config,
        array $repositories,
        ProjectConfig $project,
        LockrotConfig $lockrot,
        array $env,
        Deadline $deadline
    ): Analyzer {
        $clock = Clock::fromEnvironment($env);
        $tokens = Tokens::fromEnvironment($env, ServiceFactory::githubTokenFromComposer($config));
        $analyzer = $analyzerFactory($io, $config, $repositories, $lockrot, $tokens, $clock, $deadline);

        return $analyzer->withAllowlist($analyzer->allowlist()->merge(ProjectIgnoreList::fromExtra($project->lockrotExtra())));
    }
}
