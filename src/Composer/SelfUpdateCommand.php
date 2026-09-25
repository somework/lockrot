<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Command\BaseCommand;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Util\Platform;
use Lockrot\Clock;
use Lockrot\Config\Policy;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Deadline;
use Lockrot\Exception\ConfigException;
use Lockrot\SelfUpdate\PharUpdater;
use Lockrot\SelfUpdate\PharValidator;
use Lockrot\SelfUpdate\PharValidatorInterface;
use Lockrot\SelfUpdate\ReleaseKey;
use Lockrot\SelfUpdate\ReleaseLocator;
use Lockrot\SelfUpdate\ReleaseSignatureVerifier;
use Lockrot\SelfUpdate\SignatureVerifierInterface;
use Lockrot\Version;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `lockrot.phar self-update` — replaces the running PHAR with the newest GitHub release of its
 * major version that this PHP can run and this archive can verify ({@see ReleaseLocator}).
 *
 * Registered in bin/lockrot only. The Composer plugin must never expose it: there the code lives in
 * the project's vendor directory, where the way to update lockrot is `composer update`.
 *
 * Exit codes follow the rest of lockrot: 0 for a successful update or an already-current build, 2
 * for every error. `--check` adds one case of its own — exit 1 when an update is available — so a
 * scheduled CI job can notice a new release without this command ever writing to disk. A newer
 * major version alone is not an update without `--allow-major`: `--check` names it on a line of
 * its own and still exits 0.
 *
 * Every message goes to stderr; stdout stays empty, as it does for every lockrot run that produces
 * no report.
 *
 * @internal
 */
final class SelfUpdateCommand extends BaseCommand
{
    /**
     * How long the whole self-update round may take. {@see ComposerHttpClient} turns this into the
     * per-request timeout, which Composer maps to curl's CURLOPT_TIMEOUT — the *total* transfer
     * time, not just the connect time. A never-expiring deadline would leave
     * {@see ComposerHttpClient::DEFAULT_TIMEOUT} at 10 seconds, which is ample for the release JSON
     * but not for a megabyte of PHAR on a slow link.
     */
    private const BUDGET_SECONDS = 120.0;

    /** @var null|callable(IOInterface): array{0: HttpClientInterface, 1: ?string} client and GitHub token */
    private $httpFactory;

    private ?PharValidatorInterface $validator;
    private ?string $runningPhar;
    private string $releaseUrl;
    private ?SignatureVerifierInterface $signatures;

    /**
     * @param null|callable(IOInterface): array{0: HttpClientInterface, 1: ?string} $httpFactory HTTP client plus the
     *                                                                                          resolved GitHub token;
     *                                                                                          null builds both from
     *                                                                                          Composer's own config
     * @param ?string $runningPhar the archive to replace; null asks the runtime (`\Phar::running(false)`)
     * @param ?SignatureVerifierInterface $signatures null checks against the release key built into this archive
     */
    public function __construct(
        ?callable $httpFactory = null,
        ?PharValidatorInterface $validator = null,
        ?string $runningPhar = null,
        ?string $releaseUrl = null,
        ?SignatureVerifierInterface $signatures = null
    ) {
        $this->httpFactory = $httpFactory;
        $this->validator = $validator;
        $this->runningPhar = $runningPhar;
        $this->releaseUrl = $releaseUrl ?? ReleaseLocator::DEFAULT_URL;
        $this->signatures = $signatures;
        parent::__construct('self-update');
    }

    protected function configure(): void
    {
        $this
            // Composer's own application registers a `self-update` with a `selfupdate` alias; the
            // PHAR replaces both entries so neither spelling can reach Composer's updater, which
            // would go looking for a composer.phar that is not there.
            ->setAliases(['selfupdate'])
            ->setDescription('Replaces this lockrot.phar with the newest release of its major version from GitHub')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Only report whether an update exists; exits 1 when one does')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Reinstall even when the newest release is the version already running')
            ->addOption('allow-major', null, InputOption::VALUE_NONE, 'Also move to the next major version (for example 0.x to 1.0, or 1.x to 2.x)')
            ->addOption('offline', null, InputOption::VALUE_NONE, 'Refuse to use the network (self-update cannot run without it)')
            ->setHelp(
                "Downloads the newest release of lockrot.phar from GitHub in the same major version as\n"
                ."this one (all of 0.x counts as one), checks it against the sha256 published beside it\n"
                ."and against its signature (verified with the release key built into this archive),\n"
                ."and replaces the running archive in place. A release that needs a newer PHP than this\n"
                ."one is passed over with a line saying so; --allow-major moves on to the next major\n"
                ."version, one at a time.\n\n"
                ."The PHAR's own directory has to be writable. Nothing else on disk is touched, and\n"
                ."a failure at any step leaves the running archive exactly as it was.\n\n"
                ."  php lockrot.phar self-update\n"
                ."  php lockrot.phar self-update --check\n"
                ."  php lockrot.phar self-update --allow-major\n"
            );
    }

    /**
     * Composer's BaseCommand::initialize() builds a Composer instance from the current directory,
     * falling back to the global one. self-update needs none of it — it reads no project — and
     * going through it would make updating the PHAR depend on whatever composer.json happens to sit
     * in the working directory. Overridden empty so `lockrot.phar self-update` works from anywhere.
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Read before anything can replace the archive this process is running from. After the
        // swap the running process can no longer load a class it has not already used, and
        // `Policy::EXIT_OK` in a return statement below would be exactly that — the first use of
        // Policy in a self-update run, resolved too late to succeed. Everything else on the way out
        // is already in memory: writeError() only calls `instanceof`, which never triggers the
        // autoloader, and `writeln()` on an output object built before the command ran.
        $exitOk = Policy::EXIT_OK;
        $exitError = Policy::EXIT_ERROR;
        $exitFindings = Policy::EXIT_FINDINGS;

        try {
            $phar = $this->runningPhar ?? \Phar::running(false);
            if ($phar === '') {
                throw new ConfigException('self-update is only available in the lockrot.phar build; update a plugin install with composer update somework/lockrot');
            }
            if ($input->getOption('offline') === true || self::networkDisabled()) {
                throw new ConfigException('self-update needs network access');
            }

            [$http, $token] = $this->httpAndToken();
            $force = $input->getOption('force') === true;
            $signatures = $this->signatures ?? new ReleaseSignatureVerifier(ReleaseKey::PEM);
            $choice = (new ReleaseLocator($http, $signatures->keyFingerprint(), $token, $this->releaseUrl))
                ->locate($input->getOption('allow-major') === true, $force);
            // Every line is written before anything is installed; see PharUpdater on why nothing
            // new may be loaded once the archive has been swapped.
            foreach ($choice->notes() as $note) {
                $this->writeError($output, $note);
            }
            $upToDate = 'lockrot '.Version::STRING.' is up to date';
            $release = $choice->release();
            if ($release === null) {
                if ($force) {
                    throw new ConfigException(\sprintf(
                        'no release in the %d.x line can be installed by this lockrot.phar',
                        ReleaseLocator::majorOf(Version::STRING)
                    ));
                }
                $this->writeError($output, $upToDate);

                return $exitOk;
            }
            $updater = new PharUpdater(
                $http,
                $this->validator ?? new PharValidator(),
                $signatures,
                $phar,
                Version::STRING
            );

            if ($input->getOption('check') === true) {
                if (!$updater->isUpdateAvailable($release)) {
                    $this->writeError($output, $upToDate);

                    return $exitOk;
                }
                $this->writeError($output, \sprintf(
                    'lockrot %s is available (installed: %s); run lockrot.phar self-update',
                    $release->version(),
                    Version::STRING
                ));

                return $exitFindings;
            }

            $message = $updater->update($release, $force);
            $this->writeError($output, $message ?? $upToDate);

            return $exitOk;
        } catch (ConfigException $e) {
            $this->writeError($output, '<error>lockrot: '.$e->getMessage().'</error>');

            return $exitError;
        } catch (\Throwable $e) {
            $this->writeError($output, '<error>lockrot self-update failed: '.$e->getMessage().'</error>');

            return $exitError;
        }
    }

    /**
     * `composer --no-network` and anything else that sets COMPOSER_DISABLE_NETWORK mean the caller
     * has asked for no requests at all; self-update has nothing it could do offline, so it says so
     * instead of failing later with a transport error.
     */
    private static function networkDisabled(): bool
    {
        $disabled = Platform::getEnv('COMPOSER_DISABLE_NETWORK');

        return $disabled !== false && $disabled !== '' && $disabled !== '0';
    }

    /**
     * The HTTP client and the GitHub token, built the way the analyzer builds them: Composer's own
     * Config and HttpDownloader, with the token resolved from LOCKROT_GITHUB_TOKEN, GITHUB_TOKEN or
     * Composer's `github-oauth`, so a self-update is not the one lockrot call that hits the
     * anonymous rate limit.
     *
     * @return array{0: HttpClientInterface, 1: ?string}
     */
    private function httpAndToken(): array
    {
        $io = $this->getIO();
        if ($this->httpFactory !== null) {
            return ($this->httpFactory)($io);
        }
        $config = Factory::createConfig($io);
        $env = getenv();
        $client = new ComposerHttpClient(
            Factory::createHttpDownloader($io, $config),
            $io,
            $config,
            Clock::fromEnvironment($env),
            Deadline::inSeconds(self::BUDGET_SECONDS)
        );

        return [$client, Tokens::fromEnvironment($env, ServiceFactory::githubTokenFromComposer($config))->github()];
    }

    private function writeError(OutputInterface $output, string $message): void
    {
        $target = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $target->writeln($message);
    }
}
