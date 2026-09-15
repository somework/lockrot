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
        // The only e2e test whose assertions depend on the GitHub-derived verdict for phpzip/phpzip
        // (see the rate-limit fallback below); the other three assert on generic shapes that hold
        // regardless of GitHub activity data, so they run locally without a token.
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
        // Even with GITHUB_TOKEN set, a shared CI token can be rate-limited by the time this runs,
        // which drops the S4 (repository push) signal. S2 (no stable release) and S5 (old release,
        // open-ended constraint) still fire on their own, giving old-promise rather than silent — and
        // old-promise outranks stale in verdict precedence, so those are the only two verdicts phpzip
        // can land on here.
        $rateLimited = (bool) preg_grep('/rate limit/', $json['notes']);
        if ($rateLimited) {
            self::assertSame('old-promise', $verdicts['phpzip/phpzip'], (string) json_encode($json['notes']));
        } else {
            self::assertSame('silent', $verdicts['phpzip/phpzip']);
        }
        self::assertSame($verdicts['phpzip/phpzip'] === 'silent' ? 1 : 0, $run->getExitCode());
        // Every finding carries a priority, and the report carries the five totals. The exact level of
        // any one package depends on the same rate-limit dice as the verdict above, so this asserts the
        // field is present and valid rather than pinning a value.
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
        // API. Composer 2.2 LTS bundles symfony/console 2.8.52 while require-dev resolves 5.4, so only a run
        // through the real binary covers 2.8. COLUMNS is deliberately NOT set here: with it set,
        // TerminalWidth::detect() answers at step 1 and neither console's own width lookup is ever entered.
        // Without it, and with this test driving composer through Symfony Process rather than a terminal,
        // the run exercises step 2 on Composer 2.10.3 — symfony/console 5.4's Terminal::getWidth() finds
        // no COLUMNS and no stty and returns its own 80 fallback — and step 3 on Composer 2.2.25, where
        // there is no Terminal class at all: 2.8.52's Application::getTerminalDimensions() shells out to
        // `stty -a | grep columns` with the inherited non-TTY stdin, gets an empty string, and returns
        // [null, null] — so lockrot falls through to FormatContext::DEFAULT_WIDTH, 120. Every assertion
        // below is width-agnostic for exactly that reason.
        $table = $this->composer(['lockrot', '--target-php=8.4'], [], 120);
        $stdout = $table->getOutput();
        self::assertSame(0, $table->getExitCode(), $table->getErrorOutput().$stdout);
        self::assertMatchesRegularExpression('/^(critical|high|medium|low) \(\d+\)$/m', $stdout, $stdout);
        self::assertMatchesRegularExpression('/^ {2}(abandoned|silent|pinned|old-promise|stale) +\S+ \S/m', $stdout, $stdout);
        self::assertStringContainsString('phpzip/phpzip', $stdout);
        self::assertMatchesRegularExpression('/\d+ packages checked/', $stdout);
        self::assertMatchesRegularExpression('/^priority: critical \d+ · high \d+ · medium \d+ · low \d+$/m', $stdout);
        // no style tag reaches a redirected (non-TTY) stdout, and nothing is left half-rendered
        self::assertStringNotContainsString('<fg=', $stdout);
        self::assertStringNotContainsString('<options=', $stdout);
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
        // appears above it.
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
     * `dependencyResolutionCompleted = true`, and its catch block only calls revertComposerFile()
     * while that flag is false. A plugin listener on the same event always runs after Composer's own,
     * so by the time InstallBlockedException is thrown the revert path is already disarmed. Composer
     * has also written the new lock by then, in Installer::doUpdate(), before doInstall() dispatches
     * the event. The outcome is exit 1 with composer.json and composer.lock updated and
     * vendor/phpzip absent.
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
