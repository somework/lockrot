<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Composer;

use Composer\Composer;
use Composer\DependencyResolver\Transaction;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\IO\BufferIO;
use Composer\Package\CompletePackage;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Lockrot\Composer\CommandProvider;
use Lockrot\Composer\LockrotPlugin;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testOnTheRunningComposerThePluginIsActive(): void
    {
        $io = new BufferIO();

        (new LockrotPlugin())->activate(new Composer(), $io);

        self::assertSame('', $io->getOutput());
    }

    /** @dataProvider composerBelow210 */
    #[DataProvider('composerBelow210')]
    public function testBelowComposer210ThePluginDoesNothingAndSaysSoOnce(string $version): void
    {
        $plugin = new LockrotPlugin($version);
        $io = new BufferIO();

        $plugin->activate(new Composer(), $io);

        self::assertSame('lockrot needs Composer 2.10 or later and does nothing on Composer '.$version.'.'.\PHP_EOL, $io->getOutput());
        self::assertSame([], $plugin->getCapabilities());
    }

    public function testOnlyFromComposer210TheInstallTimeSummaryRuns(): void
    {
        $output = [];
        foreach (['2.9.5', '2.10.0'] as $version) {
            $io = new BufferIO();
            // A Composer with no configuration: the summary cannot analyse with it and writes its skip line.
            $event = new InstallerEvent(InstallerEvents::PRE_OPERATIONS_EXEC, new Composer(), $io, true, true, new Transaction([], [new CompletePackage('vendor/a', '1.0.0.0', '1.0.0')]));

            (new LockrotPlugin($version))->onPreOperationsExec($event);

            $output[$version] = $io->getOutput();
        }

        self::assertSame('', $output['2.9.5']);
        self::assertStringContainsString('lockrot: install-time check skipped', $output['2.10.0']);
    }

    /** @return iterable<string, array{string}> */
    public static function composerBelow210(): iterable
    {
        yield 'a 2.9 release' => ['2.9.5'];
        yield 'a 2.2 LTS release' => ['2.2.25'];
        yield 'a 2.1 snapshot' => ['2.1-dev+0123456789abcdef0123456789abcdef01234567'];
        yield 'a 1.x release' => ['1.10.27'];
    }

    /** @dataProvider composer210AndLater */
    #[DataProvider('composer210AndLater')]
    public function testFromComposer210ThePluginIsActive(string $version): void
    {
        $plugin = new LockrotPlugin($version);
        $io = new BufferIO();

        $plugin->activate(new Composer(), $io);

        self::assertSame('', $io->getOutput());
        self::assertSame([CommandProviderCapability::class => CommandProvider::class], $plugin->getCapabilities());
    }

    /** @return iterable<string, array{string}> */
    public static function composer210AndLater(): iterable
    {
        yield 'the first 2.10 release' => ['2.10.0'];
        yield 'a 2.11 snapshot' => ['2.11-dev+0123456789abcdef0123456789abcdef01234567'];
        yield 'a source checkout' => ['2.10-dev+source'];
        yield 'a 3.x release' => ['3.0.0'];
    }
}
