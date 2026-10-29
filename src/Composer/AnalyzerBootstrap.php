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
use Lockrot\Output\TerminalText;
use Lockrot\Verdict\MoveChecks;

/**
 * @internal
 */
final class AnalyzerBootstrap
{
    /** An ambient token => the public host that it was meant for, and lockrot's own name for that host's token. */
    private const PUBLIC_HOSTS = [
        Tokens::ENV_GITHUB => ['github.com', Tokens::ENV_LOCKROT_GITHUB],
        Tokens::ENV_GITLAB => ['gitlab.com', Tokens::ENV_LOCKROT_GITLAB],
    ];

    /**
     * @param callable(IOInterface, Config, list<RepositoryInterface>, LockrotConfig, Tokens, Clock, Deadline, ?string, (\Closure(): MoveChecks)|null, ?string): Analyzer $analyzerFactory
     * @param list<RepositoryInterface>                                                                                              $repositories
     * @param array<string, mixed>                                                                                                   $env
     * @param (\Closure(): MoveChecks)|null $checks the move check that the factory hands the analyzer, built only when the factory asks; null for none
     * @param ?string $platformPhp the lock's `platform.php`
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
        ?\Closure $checks = null,
        ?string $platformPhp = null
    ): Analyzer {
        $clock = Clock::fromEnvironment($env);
        $tokens = Tokens::fromEnvironment($env, ServiceFactory::githubTokenFromComposer($config));
        foreach ($tokens->withheld() as $name => $issuer) {
            [$host, $lockrotName] = self::PUBLIC_HOSTS[$name];
            // Raw, past Composer's formatter: the issuer comes from the environment (see TerminalText).
            $line = \sprintf('lockrot: %s is for %s, so lockrot does not send it to %s. Set %s to a %s token.', $name, TerminalText::neutralise($issuer), $host, $lockrotName, $host);
            $io->writeErrorRaw(TerminalText::warning($line, $io->isDecorated()), true, IOInterface::VERBOSE);
        }
        $analyzer = $analyzerFactory($io, $config, $repositories, $lockrot, $tokens, $clock, $deadline, $project->requirePhp(), $checks, $platformPhp);

        return $analyzer
            ->withAllowlist($analyzer->allowlist()->merge(ProjectIgnoreList::fromExtra($project->lockrotExtra())))
            ->withUncheckedAcceptance(UncheckedAcceptance::fromConfig($project->lockrotExtra()['accept-unchecked'] ?? null));
    }
}
