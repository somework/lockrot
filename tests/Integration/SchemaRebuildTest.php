<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The CI step that rebuilds the generated schemas and negatives fails on a hand edit of a generated
 * file. The test runs the step, as `.github/workflows/ci.yml` writes it, in a git repository that
 * holds a copy of `tools/schema` and the files that the builders read.
 */
final class SchemaRebuildTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';
    private const STEP = 'The generated schemas and negatives match tools/schema';
    private const REPORT2 = 'resources/lockrot-report-2.schema.json';
    /** What the builders read besides `tools/schema`: the documents the negatives derive from. */
    private const INPUTS = ['tools/schema', 'tests/fixtures/schema/documents'];

    private string $dir = '';

    protected function setUp(): void
    {
        if ((new ExecutableFinder())->find('python3') === null) {
            self::markTestSkipped('python3 runs the builders, and the CI quality job has it.');
        }
        $this->dir = sys_get_temp_dir().'/lockrot-rebuild-'.bin2hex(random_bytes(8));
        mkdir($this->dir.'/resources', 0777, true);
        foreach (self::INPUTS as $path) {
            self::copyTree(self::ROOT.'/'.$path, $this->dir.'/'.$path);
        }
        $this->git(['init', '--quiet']);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            (new Process(['rm', '-rf', $this->dir]))->run();
        }
    }

    public function testTheStepPassesOnWhatTheBuildersWrite(): void
    {
        $this->build();
        $this->commit('generated');

        $step = $this->step();

        self::assertSame(0, $step->getExitCode(), $step->getOutput().$step->getErrorOutput());
    }

    public function testTheStepFailsOnAOneByteEditOfAGeneratedSchema(): void
    {
        $this->build();
        $path = $this->dir.'/'.self::REPORT2;
        $schema = (string) file_get_contents($path);
        $at = strpos($schema, '"description": "') + \strlen('"description": "');
        self::assertGreaterThan(\strlen('"description": "'), $at, 'the schema has a description');
        $schema[$at] = $schema[$at] === 'X' ? 'Y' : 'X';
        file_put_contents($path, $schema);
        $this->commit('a hand edit');

        $step = $this->step();

        self::assertNotSame(0, $step->getExitCode(), 'the step catches the edit');
        self::assertStringContainsString(self::REPORT2, $step->getOutput());
    }

    /** The `run:` block of the CI step, read from the workflow file. */
    private static function stepScript(): string
    {
        $lines = file(self::ROOT.'/.github/workflows/ci.yml', \FILE_IGNORE_NEW_LINES) ?: [];
        $name = null;
        foreach ($lines as $i => $line) {
            if (trim($line) === '- name: '.self::STEP) {
                $name = $i;
                break;
            }
        }
        self::assertIsInt($name, 'ci.yml has the step "'.self::STEP.'"');
        self::assertSame('run: |', trim($lines[$name + 1] ?? ''), 'the step runs a block');
        $indent = \strlen($lines[$name + 1]) - \strlen(ltrim($lines[$name + 1]));
        $script = [];
        for ($i = $name + 2; isset($lines[$i]) && (trim($lines[$i]) === '' || \strlen($lines[$i]) - \strlen(ltrim($lines[$i])) > $indent); ++$i) {
            $script[] = trim($lines[$i]);
        }
        self::assertNotSame([], $script);

        return "set -e\n".implode("\n", $script);
    }

    private function build(): void
    {
        $build = Process::fromShellCommandline(str_replace("\ngit ", "\n: git ", self::stepScript()), $this->dir, null, null, 120);
        $build->run();
        self::assertSame(0, $build->getExitCode(), $build->getErrorOutput());
    }

    private function step(): Process
    {
        $step = Process::fromShellCommandline(self::stepScript(), $this->dir, null, null, 120);
        $step->run();

        return $step;
    }

    private function commit(string $message): void
    {
        $this->git(['add', '--all']);
        $this->git(['-c', 'user.name=lockrot', '-c', 'user.email=lockrot@example.test', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', $message]);
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): void
    {
        $git = new Process(array_merge(['git'], $arguments), $this->dir);
        $git->run();
        self::assertSame(0, $git->getExitCode(), $git->getErrorOutput());
    }

    private static function copyTree(string $from, string $to): void
    {
        if (!is_dir($to)) {
            mkdir($to, 0777, true);
        }
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '__pycache__') {
                continue;
            }
            if (is_dir($from.'/'.$entry)) {
                self::copyTree($from.'/'.$entry, $to.'/'.$entry);
            } else {
                copy($from.'/'.$entry, $to.'/'.$entry);
            }
        }
    }
}
