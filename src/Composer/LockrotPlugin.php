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
    public function activate(Composer $composer, IOInterface $io): void
    {
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
     * The interface declares getSubscribedEvents() without a return type in both supported Composer
     * trees, so adding one is safe.
     *
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [InstallerEvents::PRE_OPERATIONS_EXEC => 'onPreOperationsExec'];
    }

    public function onPreOperationsExec(InstallerEvent $event): void
    {
        (new InstallTimeSummary())->onPreOperationsExec($event);
    }

    /** @return array<class-string, class-string> */
    public function getCapabilities(): array
    {
        return [CommandProviderCapability::class => CommandProvider::class];
    }
}
