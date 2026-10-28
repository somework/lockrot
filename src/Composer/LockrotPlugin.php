<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

/**
 * @internal
 */
final class LockrotPlugin implements PluginInterface, Capable, EventSubscriberInterface
{
    private const COMPOSER_FLOOR = '2.10';

    private string $composerVersion;

    /**
     * composer-plugin-api `^2.9` also admits Composer 2.9, which reports plugin API 2.9.0 like 2.10,
     * so the running Composer's own version decides.
     *
     * @param ?string $composerVersion the running Composer's version by default
     */
    public function __construct(?string $composerVersion = null)
    {
        $this->composerVersion = $composerVersion ?? Composer::getVersion();
    }

    public function activate(Composer $composer, IOInterface $io): void
    {
        if (!$this->supported()) {
            $io->writeError('lockrot needs Composer '.self::COMPOSER_FLOOR.' or later and does nothing on Composer '.$this->composerVersion.'.');
        }
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * Composer registers a plugin that implements EventSubscriberInterface right after activate(),
     * so activate() needs no wiring.
     *
     * The interface declares getSubscribedEvents() without a return type, so adding one is safe.
     *
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [InstallerEvents::PRE_OPERATIONS_EXEC => 'onPreOperationsExec'];
    }

    public function onPreOperationsExec(InstallerEvent $event): void
    {
        if ($this->supported()) {
            (new InstallTimeSummary())->onPreOperationsExec($event);
        }
    }

    /** @return array<class-string, class-string> */
    public function getCapabilities(): array
    {
        return $this->supported() ? [CommandProviderCapability::class => CommandProvider::class] : [];
    }

    /** A snapshot or a source checkout reports its branch, such as `2.10-dev+source`, so only the minor counts. */
    private function supported(): bool
    {
        preg_match('/^\d+\.\d+/', $this->composerVersion, $minor);

        return version_compare($minor[0] ?? '0', self::COMPOSER_FLOOR, '>=');
    }
}
