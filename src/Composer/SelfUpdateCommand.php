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
use Lockrot\Output\TerminalText;
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
 * Registered in bin/lockrot only. The Composer plugin must not expose it: there the code lives in
 * the project's vendor directory, and `composer update` updates lockrot.
 *
 * Release rules and exit codes: docs/phar.md#keeping-it-updated and
 * docs/phar.md#self-update-exit-codes. Every message goes to stderr, so stdout stays empty.
 *
 * @internal
 */
final class SelfUpdateCommand extends BaseCommand
{
    use RejectsUnreadableInput;

    /**
     * {@see ComposerHttpClient} turns the budget into the per-request timeout, which Composer maps
     * to curl's CURLOPT_TIMEOUT, the total transfer time. A never-expiring deadline leaves
     * {@see ComposerHttpClient::DEFAULT_TIMEOUT} in force, and it is too short for the PHAR on a
     * slow link.
     */
    private const BUDGET_SECONDS = 120.0;

    /** @var null|callable(IOInterface): array{0: HttpClientInterface, 1: ?string} client and GitHub token */
    private $httpFactory;

    private ?PharValidatorInterface $validator;
    private ?string $runningPhar;
    private string $releaseUrl;
    private ?SignatureVerifierInterface $signatures;

    /**
     * @param null|callable(IOInterface): array{0: HttpClientInterface, 1: ?string} $httpFactory client and GitHub token;
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
            // The PHAR replaces both of Composer's `self-update` and `selfupdate` entries: its
            // updater looks for a composer.phar that is not there.
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

    public function run(InputInterface $input, OutputInterface $output): int
    {
        return $this->unreadableInput($input, $output) ?? parent::run($input, $output);
    }

    /**
     * Empty, so that `lockrot.phar self-update` works from anywhere: Composer's initialize() builds
     * a Composer instance from the working directory, and self-update reads no project.
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Read before anything can replace the archive: after the swap this process cannot load a
        // class it has not used. Policy is the first use in a self-update run, and TerminalText is
        // loaded for the same reason: an error after the swap can be the first line that
        // writeError() colours. Every other class on the way out is already in memory.
        $exitOk = Policy::EXIT_OK;
        $exitError = Policy::EXIT_ERROR;
        $exitFindings = Policy::EXIT_FINDINGS;
        class_exists(TerminalText::class);

        try {
            $phar = $this->runningPhar ?? \Phar::running(false);
            if ($phar === '') {
                throw new ConfigException('self-update is only available in the lockrot.phar build; update a plugin install with composer update somework/lockrot');
            }
            if ($input->getOption('offline') === true || self::networkDisabled()) {
                throw new ConfigException('self-update needs network access');
            }

            [$http, $token] = $this->httpAndToken();
            $check = $input->getOption('check') === true;
            // --check only ever reports: with --force as well it says what a plain run does.
            $force = !$check && $input->getOption('force') === true;
            $signatures = $this->signatures ?? new ReleaseSignatureVerifier(ReleaseKey::PEM);
            $locator = new ReleaseLocator($http, $signatures->keyFingerprint(), $token, $this->releaseUrl);
            try {
                $release = $locator->locate($input->getOption('allow-major') === true, $force);
            } finally {
                // Write every note before anything is installed (see PharUpdater), and also when
                // locate() fails, so the error follows the reason that a newer release was passed
                // over.
                foreach ($locator->notes() as $note) {
                    $this->writeError($output, self::plain($note));
                }
            }
            $upToDate = 'lockrot '.Version::STRING.' is up to date';
            if ($release === null) {
                $this->writeError($output, $upToDate);

                return $exitOk;
            }
            if ($check) {
                // A release of the next major is one a plain self-update holds back, so the advice
                // names the flag that reaches it.
                $this->writeError($output, \sprintf(
                    'lockrot %s is available (installed: %s); run lockrot.phar self-update%s',
                    $release->version(),
                    Version::STRING,
                    ReleaseLocator::majorOf($release->version()) === ReleaseLocator::majorOf(Version::STRING) ? '' : ' --allow-major'
                ));

                return $exitFindings;
            }
            $updater = new PharUpdater(
                $http,
                $this->validator ?? new PharValidator(),
                $signatures,
                $phar,
                Version::STRING
            );

            $message = $updater->update($release, $force);
            $this->writeError($output, $message ?? $upToDate);

            return $exitOk;
        } catch (ConfigException $e) {
            $this->writeError($output, 'lockrot: '.self::plain($e->getMessage()), true);

            return $exitError;
        } catch (\Throwable $e) {
            $this->writeError($output, 'lockrot self-update failed: '.self::plain($e->getMessage()), true);

            return $exitError;
        }
    }

    /**
     * COMPOSER_DISABLE_NETWORK, as `composer --no-network` sets it, forbids every request. Refuse
     * early instead of failing with a transport error.
     */
    private static function networkDisabled(): bool
    {
        $disabled = Platform::getEnv('COMPOSER_DISABLE_NETWORK');

        return $disabled !== false && $disabled !== '' && $disabled !== '0';
    }

    /**
     * Built as the analyzer builds them, so that the token lifts the anonymous rate limit for a
     * self-update too (docs/configuration.md#environment-overrides).
     *
     * @return array{0: HttpClientInterface, 1: ?string} client, GitHub token
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

    /**
     * Replaces C0 controls, DEL and the C1 controls (in UTF-8) with `?`: a tag or URL from the
     * release list must not reach the terminal as a control sequence. writeError() writes the rest
     * past the tag formatter.
     */
    private static function plain(string $text): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', '?', $text);
    }

    /**
     * Raw, so that no tag or URL from the release document is read as a console tag
     * ({@see TerminalText} on why OutputFormatter::escape() cannot do it). TerminalText is loaded
     * before the archive is replaced ({@see self::execute()}).
     */
    private function writeError(OutputInterface $output, string $message, bool $error = false): void
    {
        $target = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $target->writeln($error ? TerminalText::error($message, $target->isDecorated()) : $message, OutputInterface::OUTPUT_RAW);
    }
}
