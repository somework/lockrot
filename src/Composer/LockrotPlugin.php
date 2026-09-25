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
 * The Composer plugin entry point: registers the install-time listener and the `composer lockrot` command.
 *
 * The one class under src/ not marked `@internal`: composer.json names it in `extra.class` and
 * Composer loads it by that name, so the name is public. What it can be asked is Composer's plugin
 * interfaces; the one public method they do not declare is marked on its own.
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
     * Composer's PluginManager registers a plugin implementing EventSubscriberInterface with the
     * event dispatcher right after activate(), so no wiring is needed in activate() itself.
     *
     * The interface declares getSubscribedEvents() without a return type in both supported Composer
     * trees, which allows adding one here.
     *
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [InstallerEvents::PRE_OPERATIONS_EXEC => 'onPreOperationsExec'];
    }

    /**
     * Composer calls this by the name getSubscribedEvents() gives it; no interface declares it.
     *
     * @internal
     */
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
