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
        file_put_contents($this->dir.'/composer.json', json_encode([
            'name' => 'lockrot/e2e', 'type' => 'project', 'minimum-stability' => 'dev', 'prefer-stable' => true,
            'repositories' => [['type' => 'path', 'url' => \dirname(__DIR__, 2), 'options' => ['symlink' => true]]],
            'require' => ['somework/lockrot' => '*', 'phpzip/phpzip' => '2.0.8'],
            'config' => ['allow-plugins' => ['somework/lockrot' => true]],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir)) {
            (new Process(['rm', '-rf', $this->dir]))->run();
        }
    }

    public function testComposerLockrotRunsThroughThePlugin(): void
    {
        $install = new Process(['composer', 'install', '--no-interaction', '--no-progress'], $this->dir, ['COMPOSER_HOME' => $this->dir.'/.composer']);
        $install->setTimeout(300)->run();
        self::assertSame(0, $install->getExitCode(), $install->getErrorOutput());

        $run = new Process(['composer', 'lockrot', '--format=json', '--target-php=8.4', '--fail-on=silent'], $this->dir, ['COMPOSER_HOME' => $this->dir.'/.composer']);
        $run->setTimeout(120)->run();
        $json = json_decode($run->getOutput(), true);
        self::assertIsArray($json, $run->getErrorOutput().$run->getOutput());
        self::assertIsArray($json['findings']);
        $verdicts = array_column($json['findings'], 'verdict', 'package');
        self::assertContains($verdicts['phpzip/phpzip'], ['silent', 'stale'], 'without a GitHub token phpzip may be stale instead of silent');
        self::assertSame($verdicts['phpzip/phpzip'] === 'silent' ? 1 : 0, $run->getExitCode());

        $alias = new Process(['composer', 'rot', '--format=json'], $this->dir, ['COMPOSER_HOME' => $this->dir.'/.composer']);
        $alias->setTimeout(120)->run();
        self::assertSame(0, $alias->getExitCode(), $alias->getErrorOutput());

        // The table path is the only code that touches symfony/console's Table API. Composer 2.2 LTS bundles
        // symfony/console 2.8.52 while require-dev resolves 5.4, so only a run through the real binary covers it.
        $table = new Process(['composer', 'lockrot', '--target-php=8.4'], $this->dir, ['COMPOSER_HOME' => $this->dir.'/.composer']);
        $table->setTimeout(120)->run();
        $stdout = $table->getOutput();
        self::assertSame(0, $table->getExitCode(), $table->getErrorOutput().$stdout);
        self::assertMatchesRegularExpression('/Package\s+\|\s+Version\s+\|\s+Verdict\s+\|\s+Evidence\s+\|\s+Via/', $stdout);
        self::assertStringContainsString('phpzip/phpzip', $stdout);
        self::assertMatchesRegularExpression('/\d+ packages checked/', $stdout);
    }
}
