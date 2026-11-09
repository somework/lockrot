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

    /**
     * PHP parses the whole script before the PHP 7.4 guard runs, so a PHP 7.4 construct in it turns
     * the guard's line into a parse error on an older PHP: an arrow function, `??=`, a typed
     * property, a numeric separator or array unpacking.
     */
    public function testTheSourceScriptHasNoSyntaxThatAPhpBelowItsGuardCannotParse(): void
    {
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents(\dirname(__DIR__, 2).'/bin/lockrot')),
            static fn ($token): bool => !\is_array($token) || !\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true),
        ));
        // PHP 8 reads `\Foo\Bar` as one name token, PHP 7.4 as a separator and strings.
        $typeStarts = array_merge([\T_STRING, \T_ARRAY, \T_NS_SEPARATOR], \defined('T_NAME_FULLY_QUALIFIED') ? [\constant('T_NAME_QUALIFIED'), \constant('T_NAME_FULLY_QUALIFIED')] : []);
        $found = [];
        $open = [];
        foreach ($tokens as $i => $token) {
            if (\is_array($token) && \in_array($token[0], [\T_FN, \T_COALESCE_EQUAL], true)) {
                $found[] = $token[1].' on line '.$token[2];
            }
            if (\is_array($token) && \in_array($token[0], [\T_LNUMBER, \T_DNUMBER], true) && strpos($token[1], '_') !== false) {
                $found[] = 'a numeric separator on line '.$token[2];
            }
            // `...` unpacks an array only inside an array literal: `[...$a]` or `array(...$a)`.
            if ($token === '[' || $token === '(') {
                $open[] = $token === '[' || (\is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === \T_ARRAY) ? 'array' : 'call';
            } elseif ($token === ']' || $token === ')') {
                array_pop($open);
            } elseif (\is_array($token) && $token[0] === \T_ELLIPSIS && end($open) === 'array') {
                $found[] = 'array unpacking on line '.$token[2];
            }
            if (!\is_array($token) || !\in_array($token[0], [\T_PRIVATE, \T_PROTECTED, \T_PUBLIC, \T_VAR], true)) {
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            if (\is_array($next) && $next[0] === \T_STATIC) {
                $next = $tokens[$i + 2] ?? null;
            }
            if ($next === '?' || (\is_array($next) && \in_array($next[0], $typeStarts, true))) {
                $found[] = 'a typed property on line '.$token[2];
            }
        }

        self::assertSame([], $found);
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

        self::assertSame(2, $process->getExitCode(), $process->getOutput());
        self::assertSame('lockrot cannot run safely on a non-CLI SAPI with register_argc_argv=On. Aborting.'.\PHP_EOL, $process->getOutput());
    }

    /** Most projects that install lockrot as a plugin do not require composer/composer. */
    public function testTheSourceScriptInAProjectWithoutComposerNamesTheWaysToRunLockrot(): void
    {
        mkdir($this->dir.'/vendor/somework/lockrot/bin', 0777, true);
        copy(\dirname(__DIR__, 2).'/bin/lockrot', $this->dir.'/vendor/somework/lockrot/bin/lockrot');
        file_put_contents($this->dir.'/vendor/autoload.php', "<?php\n");

        $process = new Process([\PHP_BINARY, $this->dir.'/vendor/somework/lockrot/bin/lockrot', '--version']);
        $process->setTimeout(60)->run();

        self::assertSame(2, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('', $process->getOutput());
        self::assertSame("lockrot needs the composer/composer package 2.10 or newer, and the vendor directory of this project does not hold it. Run the PHAR (https://lockrot.dev/phar/). Or upgrade Composer to 2.10 or newer and run composer lockrot. Or require composer/composer 2.10 or newer in this project.\n", $process->getErrorOutput());
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

        self::assertSame(2, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertSame('', $process->getOutput());
        self::assertSame("lockrot requires the composer/composer package 2.10 or newer, not the 2.9.5 that it loads. Run the PHAR (https://lockrot.dev/phar/). Or upgrade Composer to 2.10 or newer and run composer lockrot. Or require composer/composer 2.10 or newer in this project.\n", $process->getErrorOutput());
    }

    /** bin/lockrot repeats the floor as a literal, because no lockrot class can load before its check. */
    public function testTheSourceScriptChecksTheFloorOfThePlugin(): void
    {
        $floor = (new \ReflectionClassConstant(LockrotPlugin::class, 'COMPOSER_FLOOR'))->getValue();
        self::assertIsString($floor);

        self::assertStringContainsString("'".$floor."', '<')", (string) file_get_contents(\dirname(__DIR__, 2).'/bin/lockrot'));
    }
}
