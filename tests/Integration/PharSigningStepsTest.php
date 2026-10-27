<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The phar.yml steps that hold a GNUPGHOME of their own. Later steps of the job run code from dev
 * dependencies, so each step removes its GNUPGHOME and stops its gpg daemons, whose agent can cache
 * the passphrase, on every exit. Each step's `run:` block runs under `bash -e`, the shell of a step
 * that names none. A stand-in `gpg` writes into GNUPGHOME, answers `--status-file` with a good
 * signature by LOCKROT_SIGNING_KEY and exits with FAKE_GPG_EXIT. A stand-in `gpgconf` exits with
 * FAKE_GPGCONF_EXIT. Both log their call with the GNUPGHOME that they see.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class PharSigningStepsTest extends TestCase
{
    private const WORKFLOW = __DIR__.'/../../.github/workflows/phar.yml';
    private const FAKE_GPG = <<<'BASH'
        #!/usr/bin/env bash
        cat > /dev/null
        echo "gpg $GNUPGHOME" >> "$FAKE_LOG"
        mkdir -p "$GNUPGHOME/private-keys-v1.d"
        touch "$GNUPGHOME/private-keys-v1.d/key"
        while [ $# -gt 0 ]; do
          if [ "$1" = --status-file ]; then
            printf '[GNUPG:] GOODSIG X lockrot\n[GNUPG:] VALIDSIG %s 0 0 0 0 0 0 0 0 %s\n' "$LOCKROT_SIGNING_KEY" "$LOCKROT_SIGNING_KEY" > "$2"
          fi
          shift
        done
        exit "${FAKE_GPG_EXIT:-0}"
        BASH;
    private const FAKE_GPGCONF = <<<'BASH'
        #!/usr/bin/env bash
        echo "gpgconf $* $GNUPGHOME" >> "$FAKE_LOG"
        exit "${FAKE_GPGCONF_EXIT:-0}"
        BASH;

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/lockrot-phar-signing-'.bin2hex(random_bytes(6));
        foreach (['bin', 'tmp', 'work/build'] as $dir) {
            mkdir($this->root.'/'.$dir, 0777, true);
        }
        file_put_contents($this->root.'/bin/gpg', self::FAKE_GPG);
        file_put_contents($this->root.'/bin/gpgconf', self::FAKE_GPGCONF);
        chmod($this->root.'/bin/gpg', 0755);
        chmod($this->root.'/bin/gpgconf', 0755);
        touch($this->root.'/gpg.log');
    }

    protected function tearDown(): void
    {
        // A step that fails this test leaves its GNUPGHOME, which mktemp put outside the root.
        preg_match_all('{^gpg (\S+)$}m', (string) file_get_contents($this->root.'/gpg.log'), $homes);
        foreach (array_merge($homes[1], [$this->root]) as $dir) {
            (new Process(['rm', '-rf', $dir]))->run();
        }
    }

    /** @return iterable<string, array{string, string, string}> the step, the exit of gpg and the exit of gpgconf */
    public static function exits(): iterable
    {
        foreach (['Sign the PHAR with the release key', 'Verify the signature with the committed public key'] as $step) {
            yield $step.', gpg succeeds' => [$step, '0', '0'];
            yield $step.', gpg fails' => [$step, '2', '0'];
            yield $step.', no agent to stop' => [$step, '0', '1'];
        }
    }

    /**
     * @dataProvider exits
     */
    #[DataProvider('exits')]
    public function testTheStepRemovesItsGnupgHomeAndStopsItsAgentOnEveryExit(string $step, string $gpgExit, string $gpgconfExit): void
    {
        $process = new Process(['bash', '--noprofile', '--norc', '-e', '-c', self::stepScript($step)], $this->root.'/work', [
            'TMPDIR' => $this->root.'/tmp',
            'PATH' => $this->root.'/bin:'.(string) getenv('PATH'),
            'FAKE_LOG' => $this->root.'/gpg.log',
            'FAKE_GPG_EXIT' => $gpgExit,
            'FAKE_GPGCONF_EXIT' => $gpgconfExit,
            'GPG_PRIVATE_KEY' => 'key',
            'GPG_PASSPHRASE' => 'passphrase',
            'LOCKROT_SIGNING_KEY' => '39ECC3F64AE8D06A9A63FD99AB6F7F52AE513141',
        ]);
        $process->setTimeout(60)->run();

        self::assertSame($gpgExit === '0', $process->isSuccessful(), $process->getErrorOutput());
        $log = (string) file_get_contents($this->root.'/gpg.log');
        preg_match('{^gpg (\S+)$}m', $log, $match);
        $home = $match[1] ?? '';
        self::assertNotSame('', $home, $log);
        self::assertDirectoryDoesNotExist($home, 'the GNUPGHOME is gone');
        self::assertStringContainsString("\ngpgconf --kill all ".$home."\n", "\n".$log, 'the daemons of that GNUPGHOME are stopped');
    }

    /** The `run:` block of a phar.yml step, read from the workflow file. */
    private static function stepScript(string $step): string
    {
        $lines = file(self::WORKFLOW, \FILE_IGNORE_NEW_LINES) ?: [];
        $name = null;
        foreach ($lines as $i => $line) {
            if (trim($line) === '- name: '.$step) {
                $name = $i;
                break;
            }
        }
        self::assertIsInt($name, 'phar.yml has the step "'.$step.'"');
        $run = null;
        for ($i = $name + 1; isset($lines[$i]) && strpos(ltrim($lines[$i]), '- ') !== 0; ++$i) {
            if (trim($lines[$i]) === 'run: |') {
                $run = $i;
                break;
            }
        }
        self::assertIsInt($run, $step.' runs a block');
        $indent = \strlen($lines[$run]) - \strlen(ltrim($lines[$run]));
        $script = [];
        for ($i = $run + 1; isset($lines[$i]) && (trim($lines[$i]) === '' || \strlen($lines[$i]) - \strlen(ltrim($lines[$i])) > $indent); ++$i) {
            $script[] = trim($lines[$i]);
        }
        self::assertNotSame([], $script);

        return implode("\n", $script);
    }
}
