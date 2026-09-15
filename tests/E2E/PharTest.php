<?php

declare(strict_types=1);

namespace Lockrot\Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** @group e2e */
#[Group('e2e')]
final class PharTest extends TestCase
{
    private function phar(): string
    {
        $phar = \dirname(__DIR__, 2).'/build/lockrot.phar';
        if (!is_file($phar)) {
            self::markTestSkipped('build/lockrot.phar not built; run build/build-phar.sh');
        }

        return $phar;
    }

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempDirs) as $dir) {
            self::removeTree($dir);
        }
        $this->tempDirs = [];
    }

    private static function removeTree(string $dir): void
    {
        $entries = is_dir($dir) ? scandir($dir) : false;
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) && !is_link($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * @param list<string> $arguments
     * @param ?string      $cwd       run the PHAR from here instead of the repository root
     */
    private function runPhar(array $arguments, ?string $cwd = null): Process
    {
        $process = new Process(array_merge(['php', $this->phar()], $arguments), $cwd);
        $process->setTimeout(300)->run();

        return $process;
    }

    public function testPharRunsOnFixtureDirectory(): void
    {
        $phar = $this->phar();
        $process = new Process(['php', $phar, '-d', \dirname(__DIR__).'/fixtures/skeletons/laravel', '--format=json', '--target-php=8.4']);
        $process->setTimeout(300)->run();
        $json = json_decode($process->getOutput(), true);
        self::assertIsArray($json, $process->getErrorOutput());
        $counts = $json['counts'];
        self::assertIsArray($counts);
        self::assertSame(0, $counts['abandoned']);
    }

    public function testListShowsOnlyTheTwoLockrotCommands(): void
    {
        $process = $this->runPhar(['list']);
        $output = $process->getOutput();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('self-update', $output);
        self::assertStringContainsString('lockrot', $output);
        // The PHAR is a Composer application; without an explicit command list it would also offer
        // to install, update and require packages in whatever project it is pointed at.
        foreach (['install', 'update', 'require', 'remove', 'dump-autoload'] as $composerCommand) {
            self::assertStringNotContainsString("\n  ".$composerCommand.' ', $output, $composerCommand.' must not be reachable from lockrot.phar');
        }
    }

    /**
     * Composer's application turns the scripts of whatever project it is run in into commands of
     * its own. This runs from lockrot's own checkout, whose composer.json defines `cs`, so a build
     * that stopped filtering registrations would list and run it.
     */
    public function testAProjectScriptIsNotAReachableCommand(): void
    {
        $listed = $this->runPhar(['list']);
        $invoked = $this->runPhar(['cs']);

        self::assertStringNotContainsString("\n  cs ", $listed->getOutput());
        self::assertNotSame(0, $invoked->getExitCode());
        self::assertStringContainsString('Command "cs" is not defined', $invoked->getOutput().$invoked->getErrorOutput());
    }

    /**
     * A project whose `scripts` map a name to a class name. Composer >= 2.9 registers the project's
     * own autoloader and calls `class_exists()` on that value, which loads the project's file and
     * runs whatever sits at its top level; here that writes `marker.txt`. lockrot reads a project,
     * it never executes one, so running the PHAR in this directory must leave no marker behind.
     *
     * @return array{0: string, 1: string} project directory, marker path
     */
    private function projectWithAClassScript(): array
    {
        $dir = sys_get_temp_dir().'/lockrot-script-class-'.uniqid('', true);
        if (!mkdir($dir.'/src', 0755, true) && !is_dir($dir.'/src')) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;
        $marker = $dir.'/marker.txt';
        file_put_contents($dir.'/composer.json', (string) json_encode([
            'name' => 'acme/script-class',
            'autoload' => ['psr-4' => ['Acme\\' => 'src/']],
            'scripts' => ['marker' => 'Acme\\Marker'],
        ]));
        file_put_contents($dir.'/composer.lock', (string) json_encode(['packages' => [], 'packages-dev' => []]));
        file_put_contents(
            $dir.'/src/Marker.php',
            "<?php\n\nnamespace Acme;\n\nfile_put_contents(__DIR__.'/../marker.txt', 'the project was loaded');\n\nclass Marker extends \\Symfony\\Component\\Console\\Command\\Command\n{\n}\n"
        );

        return [$dir, $marker];
    }

    public function testRunningInAProjectNeverLoadsItsScriptClasses(): void
    {
        [$dir, $marker] = $this->projectWithAClassScript();

        $process = $this->runPhar(['--format=json', '--offline'], $dir);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertIsArray(json_decode($process->getOutput(), true), $process->getErrorOutput());
        self::assertFileDoesNotExist($marker, 'lockrot must not load the inspected project\'s classes');
    }

    public function testTheAliasAlsoNeverLoadsAProjectsScriptClasses(): void
    {
        [$dir, $marker] = $this->projectWithAClassScript();

        $process = $this->runPhar(['rot', '--format=json', '--offline'], $dir);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertFileDoesNotExist($marker, 'lockrot must not load the inspected project\'s classes');
    }

    public function testListingCommandsNeverLoadsAProjectsScriptClasses(): void
    {
        [$dir, $marker] = $this->projectWithAClassScript();

        $process = $this->runPhar(['list'], $dir);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertFileDoesNotExist($marker, 'lockrot must not load the inspected project\'s classes');
    }

    public function testSelfUpdateHelpIsAvailable(): void
    {
        $process = $this->runPhar(['self-update', '--help']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('--check', $process->getOutput());
        self::assertStringContainsString('--force', $process->getOutput());
    }

    public function testTheDefaultCommandStillRunsWithoutBeingNamed(): void
    {
        $process = $this->runPhar(['-d', \dirname(__DIR__).'/fixtures/skeletons/laravel', '--format=json', '--target-php=8.4', '--offline']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertIsArray(json_decode($process->getOutput(), true), $process->getErrorOutput());
    }
}
