<?php

declare(strict_types=1);

namespace Lockrot\Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** @group e2e */
#[Group('e2e')]
final class PluginTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (getenv('LOCKROT_E2E') !== '1') {
            self::markTestSkipped('set LOCKROT_E2E=1 to run the e2e plugin test (needs network and the composer binary)');
        }
        $this->dir = sys_get_temp_dir().'/lockrot-e2e-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir)) {
            (new Process(['rm', '-rf', $this->dir]))->run();
        }
    }

    /**
     * @param array<string, string> $require      root requirements; lockrot itself comes from a path repository
     * @param array<string, mixed>  $extraLockrot written to extra.lockrot when non-empty
     */
    private function createProject(array $extraLockrot = [], array $require = ['somework/lockrot' => '*']): void
    {
        $json = [
            'name' => 'lockrot/e2e', 'type' => 'project', 'minimum-stability' => 'dev', 'prefer-stable' => true,
            'repositories' => [['type' => 'path', 'url' => \dirname(__DIR__, 2), 'options' => ['symlink' => true]]],
            'require' => $require,
            'config' => ['allow-plugins' => ['somework/lockrot' => true]],
        ];
        if ($extraLockrot !== []) {
            $json['extra'] = ['lockrot' => $extraLockrot];
        }
        file_put_contents($this->dir.'/composer.json', json_encode($json, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $env       merged on top of COMPOSER_HOME
     */
    private function composer(array $arguments, array $env = [], int $timeout = 300): Process
    {
        $process = new Process(
            array_merge(['composer'], $arguments),
            $this->dir,
            array_merge(['COMPOSER_HOME' => $this->dir.'/.composer'], $env)
        );
        $process->setTimeout($timeout)->run();

        return $process;
    }

    private function install(): void
    {
        $install = $this->composer(['install', '--no-interaction', '--no-progress']);
        self::assertSame(0, $install->getExitCode(), $install->getErrorOutput());
    }

    public function testComposerLockrotRunsThroughThePlugin(): void
    {
        $this->createProject([], ['somework/lockrot' => '*', 'phpzip/phpzip' => '2.0.8']);
        $this->install();

        $run = $this->composer(['lockrot', '--format=json', '--target-php=8.4', '--fail-on=silent'], [], 120);
        $json = json_decode($run->getOutput(), true);
        self::assertIsArray($json, $run->getErrorOutput().$run->getOutput());
        self::assertIsArray($json['findings']);
        $verdicts = array_column($json['findings'], 'verdict', 'package');
        self::assertContains($verdicts['phpzip/phpzip'], ['silent', 'stale'], 'without a GitHub token phpzip may be stale instead of silent');
        self::assertSame($verdicts['phpzip/phpzip'] === 'silent' ? 1 : 0, $run->getExitCode());

        $alias = $this->composer(['rot', '--format=json'], [], 120);
        self::assertSame(0, $alias->getExitCode(), $alias->getErrorOutput());

        // The table path is the only code that touches symfony/console's Table API. Composer 2.2 LTS bundles
        // symfony/console 2.8.52 while require-dev resolves 5.4, so only a run through the real binary covers it.
        $table = $this->composer(['lockrot', '--target-php=8.4'], [], 120);
        $stdout = $table->getOutput();
        self::assertSame(0, $table->getExitCode(), $table->getErrorOutput().$stdout);
        self::assertMatchesRegularExpression('/Package\s+\|\s+Version\s+\|\s+Verdict\s+\|\s+Evidence\s+\|\s+Via/', $stdout);
        self::assertStringContainsString('phpzip/phpzip', $stdout);
        self::assertMatchesRegularExpression('/\d+ packages checked/', $stdout);
    }

    public function testComposerRequirePrintsTheInstallTimeSummary(): void
    {
        $this->createProject(['target-php' => '8.4']);
        $this->install();

        $require = $this->composer(['require', 'phpzip/phpzip:2.0.8', '--no-interaction', '--no-progress']);

        $stderr = $require->getErrorOutput();
        self::assertSame(0, $require->getExitCode(), $stderr);
        // `composer require phpzip/phpzip` also locks its three grandt/* dependencies, and all four
        // are flagged, so the counts are "4 of 4" rather than "1 of N".
        self::assertMatchesRegularExpression('/lockrot: dependency rot in \d+ of \d+ changed packages?/', $stderr);
        self::assertStringContainsString('phpzip/phpzip 2.0.8', $stderr);
        self::assertStringContainsString('Run composer lockrot for details.', $stderr);
        // Composer fires PRE_OPERATIONS_EXEC before printing its own operations list, so the block
        // appears above it (2.10.3 Installer.php:838 vs :851-862, 2.2.25 :723 vs :741-749).
        $blockAt = strpos($stderr, 'lockrot: dependency rot in');
        $operationsAt = strpos($stderr, 'Package operations:');
        self::assertIsInt($blockAt, $stderr);
        self::assertIsInt($operationsAt, $stderr);
        self::assertLessThan($operationsAt, $blockAt, $stderr);
        self::assertDirectoryExists($this->dir.'/vendor/phpzip/phpzip');
    }

    /**
     * Strict mode stops the install: the operations never execute, so nothing lands in vendor/.
     *
     * What it does NOT do is make Composer undo the manifest edit. RequireCommand::doUpdate()
     * registers its own PRE_OPERATIONS_EXEC listener at priority 10000 which sets
     * `dependencyResolutionCompleted = true` (2.10.3 Command/RequireCommand.php:412-415, 2.2.25
     * :363-364), and its catch block only calls revertComposerFile() while that flag is false
     * (2.10.3 :343-346, 2.2.25 :289-292). A plugin listener on the same event always runs after
     * Composer's own, so by the time InstallBlockedException is thrown the revert path is already
     * disarmed. Composer has also written the new lock by then (Installer::doUpdate() at 2.10.3
     * :681 / 2.2.25 :575, before doInstall() dispatches the event). Verified against Composer
     * 2.10.3: exit 1, composer.json and composer.lock updated, vendor/phpzip absent.
     */
    public function testInstallTimeStrictStopsTheRequire(): void
    {
        $this->createProject(['install-time-strict' => true, 'fail-on' => 'old-promise', 'target-php' => '8.4']);
        $this->install();

        $require = $this->composer(['require', 'phpzip/phpzip:2.0.8', '--no-interaction', '--no-progress']);

        $stderr = $require->getErrorOutput();
        self::assertNotSame(0, $require->getExitCode(), $stderr);
        self::assertStringContainsString('install-time-strict', $stderr);
        self::assertStringContainsString('fail-on=old-promise', $stderr);
        self::assertDirectoryDoesNotExist($this->dir.'/vendor/phpzip');
    }

    public function testLockrotDisableSkipsTheInstallTimeSummary(): void
    {
        $this->createProject(['target-php' => '8.4']);
        $this->install();

        $require = $this->composer(['require', 'phpzip/phpzip:2.0.8', '--no-interaction', '--no-progress'], ['LOCKROT_DISABLE' => '1']);

        $stderr = $require->getErrorOutput();
        self::assertSame(0, $require->getExitCode(), $stderr);
        self::assertStringNotContainsString('lockrot:', $stderr);
    }
}
