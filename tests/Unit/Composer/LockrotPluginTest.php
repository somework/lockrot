<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Composer;

use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\InstallerEvents;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Lockrot\Composer\CommandProvider;
use Lockrot\Composer\LockrotPlugin;
use PHPUnit\Framework\TestCase;

final class LockrotPluginTest extends TestCase
{
    public function testThePluginIsAnEventSubscriber(): void
    {
        // Composer\Plugin\PluginManager registers a plugin implementing this interface with the
        // event dispatcher.
        self::assertInstanceOf(EventSubscriberInterface::class, new LockrotPlugin());
    }

    public function testPreOperationsExecMapsToAPublicMethod(): void
    {
        $events = LockrotPlugin::getSubscribedEvents();

        self::assertArrayHasKey(InstallerEvents::PRE_OPERATIONS_EXEC, $events);
        $handler = $events[InstallerEvents::PRE_OPERATIONS_EXEC];
        self::assertIsString($handler);
        self::assertTrue((new \ReflectionMethod(LockrotPlugin::class, $handler))->isPublic());
    }

    public function testCommandProviderCapabilityIsUnchanged(): void
    {
        self::assertSame(
            [CommandProviderCapability::class => CommandProvider::class],
            (new LockrotPlugin())->getCapabilities()
        );
    }
}
