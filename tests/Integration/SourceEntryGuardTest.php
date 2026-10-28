<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Composer\LockrotPlugin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** The guards that bin/lockrot runs as a source script, where no requirement checker of Box runs. */
final class SourceEntryGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/lockrot-source-entry-'.bin2hex(random_bytes(8));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();
    }

    /** On a web SAPI with register_argc_argv=On, a query string can set the command line. */
    public function testTheSourceScriptDoesNotRunOnAWebSapiThatReadsArgvFromTheQueryString(): void
    {
        $cgi = \dirname(\PHP_BINARY).'/php-cgi';
        if (!is_executable($cgi)) {
            self::markTestSkipped('no php-cgi beside '.\PHP_BINARY);
        }
        $process = new Process([$cgi, '-q', '-d', 'register_argc_argv=1', \dirname(__DIR__, 2).'/bin/lockrot', '--version']);
        $process->setTimeout(60)->run();

        self::assertSame(1, $process->getExitCode(), $process->getOutput());
        self::assertSame('lockrot cannot run safely on a non-CLI SAPI with register_argc_argv=On. Aborting.'.\PHP_EOL, $process->getOutput());
    }

    /**
     * A host project installs lockrot beside its own composer/composer. The stub autoloader of the
     * host writes a line for each lockrot class that loads, so the exact stderr also proves that none
     * loads.
     */
    public function testTheSourceScriptRefusesAComposerBelowTheFloorBeforeAnyLockrotClassLoads(): void
    {
        mkdir($this->dir.'/vendor/somework/lockrot/bin', 0777, true);
        copy(\dirname(__DIR__, 2).'/bin/lockrot', $this->dir.'/vendor/somework/lockrot/bin/lockrot');
        file_put_contents($this->dir.'/vendor/autoload.php', <<<'PHP'
            <?php
            namespace Composer\Console {
                class Application
                {
                }
            }
            namespace Composer {
                class Composer
                {
                    public static function getVersion(): string
                    {
                        return '2.9.5';
                    }
                }
            }
            namespace {
                spl_autoload_register(static function (string $class): void {
                    if (strpos($class, 'Lockrot\\') === 0) {
                        fwrite(STDERR, 'loaded '.$class."\n");
                    }
                });
            }
            PHP);

        $process = new Process([\PHP_BINARY, $this->dir.'/vendor/somework/lockrot/bin/lockrot', '--version']);
        $process->setTimeout(60)->run();

        self::assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertSame('', $process->getOutput());
        self::assertSame("lockrot requires Composer 2.10 or newer, not 2.9.5\n", $process->getErrorOutput());
    }

    /** bin/lockrot repeats the floor as a literal, because no lockrot class can load before its check. */
    public function testTheSourceScriptChecksTheFloorOfThePlugin(): void
    {
        $floor = (new \ReflectionClassConstant(LockrotPlugin::class, 'COMPOSER_FLOOR'))->getValue();
        self::assertIsString($floor);

        self::assertStringContainsString("'".$floor."', '<')", (string) file_get_contents(\dirname(__DIR__, 2).'/bin/lockrot'));
    }
}
