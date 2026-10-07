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
        $this->dir = sys_get_temp_dir().'/lockrot-e2e-'.bin2hex(random_bytes(8));
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
        // These assertions depend on the GitHub-derived verdict for phpzip/phpzip, so the test needs a
        // token.
        if (getenv('GITHUB_TOKEN') === false || getenv('GITHUB_TOKEN') === '') {
            self::markTestSkipped('set GITHUB_TOKEN: anonymous GitHub requests hit the 60/h rate limit and change the expected verdicts');
        }
        $this->createProject([], ['somework/lockrot' => '*', 'phpzip/phpzip' => '2.0.8']);
        $this->install();

        $run = $this->composer(['lockrot', '--format=json', '--target-php=8.4', '--fail-on=silent'], [], 120);
        $json = json_decode($run->getOutput(), true);
        self::assertIsArray($json, $run->getErrorOutput().$run->getOutput());
        self::assertIsArray($json['findings']);
        self::assertIsArray($json['notes']);
        $verdicts = array_column($json['findings'], 'verdict', 'package');
        // A shared CI token can be rate-limited by the time this runs, which drops the S4 (repository
        // push) signal. S2 (no stable release) and S5 (old release, open-ended constraint) still fire.
        // They give old-promise, which outranks stale, so phpzip lands on old-promise or silent only.
        $rateLimited = (bool) preg_grep('/rate limit/', $json['notes']);
        if ($rateLimited) {
            self::assertSame('old-promise', $verdicts['phpzip/phpzip'], (string) json_encode($json['notes']));
        } else {
            self::assertSame('silent', $verdicts['phpzip/phpzip']);
        }
        self::assertSame($verdicts['phpzip/phpzip'] === 'silent' ? 1 : 0, $run->getExitCode());
        // The level of any one package depends on the same rate limit as the verdict, so assert that
        // the field is present and valid rather than pin a value.
        self::assertIsArray($json['priorities']);
        self::assertSame(['critical', 'high', 'medium', 'low', 'none'], array_keys($json['priorities']));
        foreach ($json['findings'] as $finding) {
            self::assertIsArray($finding);
            self::assertArrayHasKey('priority', $finding);
            self::assertContains($finding['priority'], ['critical', 'high', 'medium', 'low', 'none']);
            self::assertIsBool($finding['direct']);
            self::assertIsBool($finding['dev']);
        }

        $alias = $this->composer(['rot', '--format=json'], [], 120);
        self::assertSame(0, $alias->getExitCode(), $alias->getErrorOutput());

        // The table path is the only code that touches symfony/console's style tags and its terminal-width
        // API. Composer 2.2 LTS bundles symfony/console 2.8 while require-dev resolves 5.4, so only a run
        // through the real binary covers 2.8. COLUMNS stays unset: with it set, TerminalWidth::detect()
        // answers first and neither console's own width lookup is entered. Without it, each console finds
        // no terminal and the width differs per Composer version, so every assertion is width-agnostic.
        $table = $this->composer(['lockrot', '--target-php=8.4'], [], 120);
        $stdout = $table->getOutput();
        self::assertSame(0, $table->getExitCode(), $table->getErrorOutput().$stdout);
        self::assertMatchesRegularExpression('/^(critical|high|medium|low) \(\d+\)$/m', $stdout, $stdout);
        self::assertMatchesRegularExpression('/^ {2}(abandoned|silent|pinned|old-promise|stale) +\S+ \S/m', $stdout, $stdout);
        self::assertStringContainsString('phpzip/phpzip', $stdout);
        self::assertMatchesRegularExpression('/\d+ packages checked/', $stdout);
        self::assertMatchesRegularExpression('/^priority: critical \d+ · high \d+ · medium \d+ · low \d+$/m', $stdout);
        self::assertStringNotContainsString('<fg=', $stdout);
        self::assertStringNotContainsString('<options=', $stdout);
    }

    /**
     * `composer -d <dir>` changes into the project before any command runs, so relative `--output`
     * paths land there, not in the shell's directory. The plugin's own symfony/console strips the
     * markup from the table file, 2.8 under Composer 2.2 LTS and 5.4 under 2.10, and this test covers
     * both. It runs offline, so no verdict depends on GitHub.
     */
    public function testOutputFilesAreRelativeToTheDirectoryComposerRunsIn(): void
    {
        $this->createProject(['target-php' => '8.4'], ['somework/lockrot' => '*', 'phpzip/phpzip' => '2.0.8']);
        $this->install();
        $elsewhere = sys_get_temp_dir().'/lockrot-e2e-elsewhere-'.bin2hex(random_bytes(8));
        mkdir($elsewhere);
        try {
            $run = new Process(
                ['composer', '--working-dir='.$this->dir, 'lockrot', '--offline', '--output=table:r.txt', '--output=json:r.json'],
                $elsewhere,
                ['COMPOSER_HOME' => $this->dir.'/.composer']
            );
            $run->setTimeout(120)->run();

            self::assertSame(0, $run->getExitCode(), $run->getErrorOutput().$run->getOutput());
            self::assertStringContainsString("lockrot: table report written to r.txt\nlockrot: json report written to r.json\n", $run->getErrorOutput());
            $table = (string) file_get_contents($this->dir.'/r.txt');
            self::assertMatchesRegularExpression('/\d+ packages checked/', $table);
            self::assertStringNotContainsString('<fg=', $table);
            self::assertStringNotContainsString('<options=', $table);
            self::assertStringNotContainsString('\\<', $table);
            self::assertStringNotContainsString("\e[", $table);
            self::assertIsArray(json_decode((string) file_get_contents($this->dir.'/r.json'), true));
            self::assertSame(['.', '..'], scandir($elsewhere), 'nothing lands in the shell\'s directory');
        } finally {
            (new Process(['rm', '-rf', $elsewhere]))->run();
        }
    }

    public function testComposerRequirePrintsTheInstallTimeSummary(): void
    {
        $this->createProject(['target-php' => '8.4']);
        $this->install();

        $require = $this->composer(['require', 'phpzip/phpzip:2.0.8', '--no-interaction', '--no-progress']);

        $stderr = $require->getErrorOutput();
        self::assertSame(0, $require->getExitCode(), $stderr);
        // `composer require phpzip/phpzip` also locks its grandt/* dependencies, and lockrot flags them
        // too, so the counts are not "1 of N".
        self::assertMatchesRegularExpression('/lockrot: dependency rot in \d+ of \d+ changed packages?/', $stderr);
        self::assertStringContainsString('phpzip/phpzip 2.0.8', $stderr);
        self::assertStringContainsString('Run composer lockrot for details.', $stderr);
        // Composer fires PRE_OPERATIONS_EXEC before printing its own operations list, so the block
        // comes first.
        $blockAt = strpos($stderr, 'lockrot: dependency rot in');
        $operationsAt = strpos($stderr, 'Package operations:');
        self::assertIsInt($blockAt, $stderr);
        self::assertIsInt($operationsAt, $stderr);
        self::assertLessThan($operationsAt, $blockAt, $stderr);
        self::assertDirectoryExists($this->dir.'/vendor/phpzip/phpzip');
    }

    /**
     * Strict mode stops the install, so nothing lands in vendor/. Composer does not undo the
     * manifest edit. RequireCommand::doUpdate() registers a PRE_OPERATIONS_EXEC listener at priority
     * 10000 that sets `dependencyResolutionCompleted = true`, and it reverts only while that flag is
     * false. A plugin listener runs after it, so the revert is disarmed when InstallBlockedException
     * is thrown, and Installer::doUpdate() has written the lock by then. The outcome is exit 1 with
     * composer.json and composer.lock updated and vendor/phpzip absent.
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

    /** The warning renders through the real plugin. `x-ci` is reserved and stays quiet. */
    public function testAnUnknownKeyIsWarnedAboutThroughThePlugin(): void
    {
        $this->createProject(['install-tme' => 'off', 'x-ci' => true]);
        $this->install();

        $run = $this->composer(['lockrot', '--format=json', '--offline'], [], 120);

        $stderr = $run->getErrorOutput();
        self::assertSame(0, $run->getExitCode(), $stderr);
        self::assertIsArray(json_decode($run->getOutput(), true), $stderr.$run->getOutput());
        self::assertSame(1, substr_count($stderr, 'lockrot: unknown key extra.lockrot.install-tme ignored (did you mean install-time?)'), $stderr);
        self::assertStringNotContainsString('x-ci', $stderr);
        self::assertStringNotContainsString('<warning>', $stderr);
    }

    /**
     * A command line `composer lockrot` cannot read is lockrot's usage error — exit 2 and one
     * `lockrot:` line — not Composer's error box and exit 1. This runs through the real binary, so on
     * the Composer 2.2 leg of CI it is the check that the binding works on symfony/console 2.8 too.
     * No network is needed: nothing is analysed.
     */
    public function testAnUnreadableCommandLineIsExitTwoWithOneLockrotLine(): void
    {
        $this->createProject(['target-php' => '8.4']);
        $this->install();

        foreach (['--nope' => '"--nope" option does not exist', '--format' => '"--format" option requires a value', '--dev=yes' => '"--dev" option does not accept a value'] as $option => $reason) {
            $run = $this->composer(['lockrot', $option], [], 120);

            self::assertSame(2, $run->getExitCode(), $option."\n".$run->getErrorOutput());
            self::assertSame('', $run->getOutput(), $option);
            self::assertMatchesRegularExpression('/^lockrot: [^\n]*'.preg_quote($reason, '/').'[^\n]*$/m', $run->getErrorOutput(), $option);
        }
    }

    /**
     * `COMPOSER=alt.json composer lockrot` reads alt.json and alt.lock, as every other Composer
     * command does. The copies are byte-identical, so only the lock's name tells them apart.
     */
    public function testComposerTheEnvironmentVariableChoosesTheLockLockrotReads(): void
    {
        $this->createProject(['target-php' => '8.4']);
        $this->install();
        copy($this->dir.'/composer.json', $this->dir.'/alt.json');
        copy($this->dir.'/composer.lock', $this->dir.'/alt.lock');

        $run = $this->composer(['lockrot', '--format=json'], ['COMPOSER' => 'alt.json'], 120);

        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $json = json_decode($run->getOutput(), true);
        self::assertIsArray($json, $run->getErrorOutput());
        self::assertIsArray($json['run']);
        self::assertSame('alt.lock', $json['run']['lock_file']);
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
