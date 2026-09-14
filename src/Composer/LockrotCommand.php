<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\Config;
use Composer\Factory;
use Composer\IO\IOInterface;
use Lockrot\Allowlist\ProjectIgnoreList;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Config\LockrotConfig;
use Lockrot\Config\Policy;
use Lockrot\Data\GitHub\TokenResolver;
use Lockrot\Exception\ConfigException;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\Formatters;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class LockrotCommand extends BaseCommand
{
    /** @var callable(IOInterface, Config, LockrotConfig, ?string, Clock): Analyzer */
    private $analyzerFactory;

    /** Set by initialize() when the project manifest is unusable; rethrown inside execute(). */
    private ?ConfigException $bootstrapError = null;

    /** @param null|callable(IOInterface, Config, LockrotConfig, ?string, Clock): Analyzer $analyzerFactory */
    public function __construct(?callable $analyzerFactory = null)
    {
        $this->analyzerFactory = $analyzerFactory ?? [ServiceFactory::class, 'createAnalyzer'];
        parent::__construct('lockrot');
    }

    protected function configure(): void
    {
        $this
            ->setAliases(['rot'])
            ->setDescription('Shows dependency rot in composer.lock: abandoned, silent, pinned and old-promise packages')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table or json')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Exit 1 when a finding reaches this verdict: none, abandoned, silent, pinned, old-promise, stale')
            ->addOption('target-php', null, InputOption::VALUE_REQUIRED, 'PHP version the project targets, e.g. 8.4 (default: config.platform.php or the running PHP)')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Include packages-dev')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Show every package, not only flagged ones')
            ->addOption('offline', null, InputOption::VALUE_NONE, 'Use cached data only, never go to the network')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Ignore cached data and fetch again')
            ->addOption('strict-network', null, InputOption::VALUE_NONE, 'Exit 1 when Packagist or GitHub could not be reached');
    }

    /**
     * Composer's BaseCommand::initialize() bootstraps a Composer instance from the project's
     * composer.json (2.10.3 src/Composer/Command/BaseCommand.php:240, 2.2.25 :140) and lets a JSON
     * parse error escape as a Composer crash — exit 1, before execute() is ever reached. lockrot
     * documents a malformed manifest as a configuration error (exit 2, README "Exit codes"), so the
     * file is checked here first and the failure is carried into execute()'s error handling instead.
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->bootstrapError = null;
        try {
            ProjectConfig::fromFile((string) getcwd().'/composer.json');
        } catch (ConfigException $e) {
            $this->bootstrapError = $e;

            return;
        }
        parent::initialize($input, $output);
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
            $config = $this->composerConfig($io, is_file($cwd.'/composer.json'));
            $lockrot = LockrotConfig::fromSources($project->lockrotExtra(), $env, $this->cliOptions($input), \PHP_VERSION, $project->platformPhp());
            if ($lockrot->isDisabled()) {
                $this->writeError($output, 'lockrot disabled via LOCKROT_DISABLE');

                return Policy::EXIT_OK;
            }
            $lock = LockFile::fromFile($cwd.'/composer.lock');
            $clock = $this->clock($env);
            $token = TokenResolver::resolve($env, ServiceFactory::githubTokenFromComposer($config));
            $analyzer = ($this->analyzerFactory)($io, $config, $lockrot, $token, $clock);
            $analyzer = $analyzer->withAllowlist($analyzer->allowlist()->merge(ProjectIgnoreList::fromExtra($project->lockrotExtra())));
            $report = $analyzer->analyze($lock, $project, $lockrot->includeDev());
            $output->write(Formatters::for($lockrot->format())->format($report, $input->getOption('all') === true));

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
        }
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

        return [
            'format' => \is_string($format) ? $format : null,
            'fail-on' => \is_string($failOn) ? $failOn : null,
            'target-php' => \is_string($targetPhp) ? $targetPhp : null,
            'dev' => $input->getOption('dev') === true,
            'offline' => $input->getOption('offline') === true,
            'refresh' => $input->getOption('refresh') === true,
            'strict-network' => $input->getOption('strict-network') === true,
        ];
    }

    private function composerConfig(IOInterface $io, bool $hasComposerJson): Config
    {
        if ($hasComposerJson) {
            // Composer >= 2.3 has tryComposer(); 2.2 LTS only has getComposer(bool $required) (BaseCommand.php:124 vs 2.2 :59)
            // @phpstan-ignore function.alreadyNarrowedType (tryComposer() does not exist in Composer 2.2 LTS; guard is load-bearing there)
            $composer = method_exists($this, 'tryComposer') ? $this->tryComposer() : $this->getComposer(false);
            if ($composer instanceof Composer) {
                return $composer->getConfig();
            }
        }

        return Factory::createConfig($io);
    }

    /** @param array<string, mixed> $env */
    private function clock(array $env): Clock
    {
        $today = $env['LOCKROT_TODAY'] ?? null;

        return \is_string($today) && $today !== '' ? Clock::fixed($today) : new Clock();
    }
}
