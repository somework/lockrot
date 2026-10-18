<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Repository\RepositoryInterface;
use Lockrot\Allowlist\ProjectIgnoreList;
use Lockrot\Allowlist\UncheckedAcceptance;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Config\LockrotConfig;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Deadline;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Verdict\MoveChecks;

/**
 * @internal
 */
final class AnalyzerBootstrap
{
    /**
     * @param callable(IOInterface, Config, list<RepositoryInterface>, LockrotConfig, Tokens, Clock, Deadline, ?string, (\Closure(): MoveChecks)|null): Analyzer $analyzerFactory
     * @param list<RepositoryInterface>                                                                                              $repositories
     * @param array<string, mixed>                                                                                                   $env
     * @param (\Closure(): MoveChecks)|null $checks the move check that the factory hands the analyzer, built only when the factory asks; null for none
     */
    public static function create(
        callable $analyzerFactory,
        IOInterface $io,
        Config $config,
        array $repositories,
        ProjectConfig $project,
        LockrotConfig $lockrot,
        array $env,
        Deadline $deadline,
        ?\Closure $checks = null
    ): Analyzer {
        $clock = Clock::fromEnvironment($env);
        $tokens = Tokens::fromEnvironment($env, ServiceFactory::githubTokenFromComposer($config));
        $analyzer = $analyzerFactory($io, $config, $repositories, $lockrot, $tokens, $clock, $deadline, $project->requirePhp(), $checks);

        return $analyzer
            ->withAllowlist($analyzer->allowlist()->merge(ProjectIgnoreList::fromExtra($project->lockrotExtra())))
            ->withUncheckedAcceptance(UncheckedAcceptance::fromConfig($project->lockrotExtra()['accept-unchecked'] ?? null));
    }
}
