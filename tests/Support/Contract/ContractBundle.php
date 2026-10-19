<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support\Contract;

use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineFile;
use Lockrot\Json\JsonWriter;
use Lockrot\Output\AgeText;
use Lockrot\Output\CauseWords;
use Lockrot\Output\RunGateText;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ScoreSweep;
use Lockrot\Tests\Support\VersionMask;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\ScoreModel;
use Lockrot\Verdict\ShellCommand;
use Lockrot\Version;

/**
 * The rendering contract bundle of SPEC-0.14.md §7.11, as lockrot writes it. It holds every case of
 * tests/fixtures/flags/cases.json, the composite, the words tables and the tables of the PHP grammars.
 * tools/report/record writes it to tests/fixtures/contract/. Every file carries the tool version
 * through {@see VersionMask}.
 */
final class ContractBundle
{
    public const DIRECTORY = __DIR__.'/../../fixtures/contract';
    /** The files of DIRECTORY that record() does not write: the recorder's own, a table of PhpFloorTest, and the model's page goldens (PageGoldensTest). */
    public const NOT_RECORDED = ['bundle_sha256', 'provenance.json', 'en/php-versions.json', 'page-goldens.json'];
    /** The directory of DIRECTORY that {@see EnginePage} writes. */
    public const ENGINE_PAGES = 'page/';
    private const FIXTURES = __DIR__.'/../../fixtures/';

    /** The text formats, by the suffix of the file each one is written to below `text/`. */
    private const TEXT = ['table' => '.table.txt', 'markdown' => '.md', 'github' => '.github.txt'];
    private const DOCUMENTS = __DIR__.'/../../fixtures/schema/documents/';
    /**
     * The golden keys that the PHP grammars own (§7.11 item 4), each with the key of its inputs in
     * ReferenceText::INPUTS, the x-rendered-from list of its string.
     */
    private const RENDERED_FROM = [
        'score_text' => 'score.text',
        'next_step_text' => 'next_step.text',
        'gate_line' => 'gate_line',
        'run_gate_line' => 'gate.text',
        'age_line' => 'age_line',
        'baseline_suffix' => 'baseline_suffix',
        'commands_posix' => 'commands',
        'commands_cmd' => 'commands',
    ];
    /**
     * The fewest goldens per key that the recording compares with the model's. A hydrated input that
     * differs from the model's leaves its golden uncompared, so a lost input fails here.
     */
    public const COMPARED_FLOOR = ['age_line' => 65, 'baseline_suffix' => 70, 'commands_cmd' => 91, 'commands_posix' => 91, 'gate_line' => 88, 'next_step_text' => 55, 'run_gate_line' => 90, 'score_text' => 78];
    /** The inputs of a command list: the move's argv. */
    private const COMMANDS = ['next_step.commands'];
    /** Up to this many score lines per grammar path of the sweep, as the model's score-lines.json keeps. */
    private const LINES_PER_PATH = 4;
    /**
     * Commands whose quoting differs between the shells, beside the commands that the cases write, and
     * the hostile rows of SPEC-0.14.md §5.6 that every shell refuses (`null`).
     */
    private const QUOTING_ROWS = [
        ['composer', 'require', 'vendor/package:^2.0 || ^3.0'],
        ['composer', 'require', 'vendor/package:>=1.2 <2.0'],
        ['composer', 'config', 'extra.lockrot.reason', "the team's fork"],
        ['composer', 'require', 'vendor/package:~1.4,!=1.4.2'],
        ['composer', 'require', 'vendor/package:dev-x%CMDCMDLINE:~0,1%&calc&rem '],
        ['composer', 'require', 'vendor/package:dev-x!PATH!'],
        ['composer', 'update', "vendor/package\r"],
        ['composer', 'update', "vendor/package\ncalc"],
        ['composer', 'update', "vendor/package\0"],
        ['composer', 'require', "vendor/package:x\u{2019};calc;\u{2019}"],
        ['composer', 'update', '--%'],
        ['composer', 'why-not', 'vendor/package', '--working-dir=x'],
        ['composer', 'update', '@name'],
        ['composer', 'update', 'a,b'],
        ['composer', 'update', 'a/b|c'],
        ['composer', 'update', '=a'],
        ['a,b', 'update', 'vendor/package'],
        ['-x', 'update', 'vendor/package'],
        ['iex', 'calc'],
        ['composer', 'exec', 'calc'],
        ['composer', 'update', '   '],
    ];

    /** @var array<string, string> each written file's contents, by its path in the bundle */
    private array $files = [];
    /** @var list<string> */
    private array $withoutInputs = [];
    /** @var array<string, array<string, array{expected: mixed, actual: mixed}>> by case id, then golden key */
    private array $mismatches = [];
    /** @var array<string, int> golden comparisons by key */
    private array $compared = [];
    /** @var array<string, int> goldens left uncompared by key: their inputs differ from the model's */
    private array $skipped = [];
    /** @var array<int|string, true> the published years values, as their keys */
    private array $years = [];
    /** @var array<string, list<string>> */
    private array $argvs = [];

    /**
     * Every file of the bundle by its path below tests/fixtures/contract/. The model documents of
     * the cases that no hydration rebuilds go to `model-only/`. tools/report/record writes
     * `provenance.json` and `bundle_sha256`.
     *
     * @return array{files: array<string, string>, without_inputs: list<string>, goldens: array{compared: array<string, int>, inputs_differ: array<string, int>}}
     */
    public static function record(): array
    {
        $bundle = new self();
        $bundle->recordCases();
        $bundle->recordComposite();
        $bundle->recordGateRows();
        $bundle->recordTables();
        if ($bundle->mismatches !== []) {
            throw new GoldenMismatch($bundle->mismatches);
        }
        foreach (self::COMPARED_FLOOR as $key => $floor) {
            if (($bundle->compared[$key] ?? 0) < $floor) {
                throw new \UnexpectedValueException(\sprintf('%d %s goldens compared with the model, the floor is %d', $bundle->compared[$key] ?? 0, $key, $floor));
            }
        }
        ksort($bundle->files);

        ksort($bundle->compared);
        ksort($bundle->skipped);

        return ['files' => $bundle->files, 'without_inputs' => $bundle->withoutInputs, 'goldens' => ['compared' => $bundle->compared, 'inputs_differ' => $bundle->skipped]];
    }

    /**
     * The sha256 that `contract.bundle_sha256` pins: one line `<sha256>  <path>` per file, sorted
     * by path, hashed. The paths are relative to tests/fixtures/.
     *
     * @param array<string, string> $files contents by path below tests/fixtures/
     */
    public static function sha256(array $files): string
    {
        ksort($files);
        $lines = '';
        foreach ($files as $path => $contents) {
            $lines .= hash('sha256', $contents).'  '.$path."\n";
        }

        return hash('sha256', $lines);
    }

    /**
     * The files that `bundle_sha256` covers, by their path below tests/fixtures/: every file of
     * contract/ but cache/, provenance.json and bundle_sha256, and each baselined scenario's
     * check/report.bundle.json.
     *
     * @return array<string, string>
     */
    public static function bundleFiles(): array
    {
        $files = [];
        foreach (self::tree(self::DIRECTORY) as $path => $contents) {
            if (strpos($path, 'cache/') !== 0 && !\in_array($path, ['provenance.json', 'bundle_sha256'], true)) {
                $files['contract/'.$path] = $contents;
            }
        }
        foreach (glob(self::FIXTURES.'baselined/*/check/report.bundle.json') ?: [] as $bundle) {
            $files[substr($bundle, \strlen(self::FIXTURES))] = (string) file_get_contents($bundle);
        }
        ksort($files);

        return $files;
    }

    /** @return array<string, string> every file below $root by its relative path, sorted */
    public static function tree(string $root): array
    {
        $files = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[substr($file->getPathname(), \strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }
        ksort($files);

        return $files;
    }

    /** The bytes that BaselineFile::write() gives $baseline. */
    public static function written(Baseline $baseline): string
    {
        $dir = sys_get_temp_dir().'/lockrot-baseline-'.bin2hex(random_bytes(8));
        mkdir($dir);
        $file = BaselineFile::resolve($dir, 'lockrot-baseline.json', Version::STRING);
        try {
            $file->write($baseline);

            return (string) file_get_contents($file->path());
        } finally {
            @unlink($file->path());
            rmdir($dir);
        }
    }

    /**
     * A decoded JSON object with the string keys that a reader of its document expects.
     *
     * @param array<mixed, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function stringKeys(array $data): array
    {
        $keyed = [];
        foreach ($data as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }

    private function recordCases(): void
    {
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(CaseHydrator::CASES), ['cases']) as $case) {
            if (!\is_array($case)) {
                throw new \UnexpectedValueException('a case is not an object');
            }
            $id = JsonPath::stringAt($case, ['id']);
            $name = CaseRecording::fileName($id);
            if (!\is_array($case['inputs'] ?? null)) {
                $this->withoutInputs[] = $id;
                $this->putRaw('model-only/'.$name.'.json', (string) file_get_contents(self::DOCUMENTS.'cases/'.$name.'.json'));
                continue;
            }
            $recording = CaseRecording::of($case);
            $formats = $recording->formats();
            $this->putFormats($name, $formats);
            $this->putRaw('explain-2/'.$name.'.json', $recording->explain());
            $goldens = $recording->goldens($formats['json']);
            $this->put('goldens/'.$name.'.json', $goldens);
            $this->compare($id, $case, $goldens, $formats['json']);
            $this->collect($formats['json']);
        }
    }

    private function recordComposite(): void
    {
        $report = CaseHydrator::composite();
        $run = $report->run();
        $failOn = $run === null || $run->failOn() === null ? FailOn::none() : $run->failOn();
        $formats = CaseRecording::formatted($report, $failOn, CaseHydrator::lockJson());
        $this->putFormats('composite', $formats);
        $this->collect($formats['json']);
    }

    /** The baseline-2 files of the model's gate rows, read by Baseline::fromArray() and written by BaselineFile::write(). */
    private function recordGateRows(): void
    {
        $dir = self::DOCUMENTS.'baseline/gate-rows/';
        $files = glob($dir.'*.json') ?: [];
        sort($files);
        if ($files === []) {
            throw new \UnexpectedValueException('no gate-row baseline in '.$dir);
        }
        foreach ($files as $file) {
            $this->putRaw('baseline-2/'.basename($file), self::written(Baseline::fromArray(self::stringKeys(JsonPath::decodeFile($file)))));
        }
    }

    private function recordTables(): void
    {
        $this->put('score-lines.json', ['score_text_grammar' => ScoreModel::SCORE_TEXT_GRAMMAR, 'pairs' => self::scoreLines()]);
        $years = array_map('floatval', array_keys($this->years + ['0' => true, '0.1' => true, '1' => true, '10' => true]));
        sort($years);
        $this->put('en/years-phrases.json', ['pairs' => array_map(static fn (float $value): array => ['value' => $value, 'phrase' => AgeText::ago($value)], array_values(array_unique($years, \SORT_REGULAR)))]);
        $rows = [];
        foreach (array_merge(array_values($this->argvs), self::QUOTING_ROWS) as $argv) {
            $rows[implode("\0", $argv)] = ['argv' => $argv, 'posix' => ShellCommand::posix($argv), 'cmd' => ShellCommand::cmd($argv), 'powershell' => ShellCommand::powershell($argv)];
        }
        ksort($rows);
        $this->put('en/commands.json', ['rows' => array_values($rows)]);
        $words = CauseWords::WORDS;
        ksort($words);
        $this->put('en/reason-words.json', ['words' => $words]);
        $this->put('en/gate-words.json', ['fail_on_source' => RunGateText::FAIL_ON_SOURCE, 'target_php_source' => RunGateText::TARGET_SOURCE, 'not_applied' => RunGateText::NOT_APPLIED]);
        foreach (PageWords::TABLES as $file => $table) {
            $this->put('en/'.$file, $table);
        }
    }

    /**
     * Up to four score lines per grammar path of the score sweep: the number of maintenance terms,
     * the halvings and whether each changed a number, the security term and its multiplier,
     * rounding, and the accepted flags.
     *
     * @return list<array{score: array<mixed, mixed>, text: string}>
     */
    private static function scoreLines(): array
    {
        $kept = [];
        $pairs = [];
        foreach (ScoreSweep::inputs() as $inputs) {
            $score = ScoreSweep::decoded(ScoreSweep::basis($inputs));
            $path = self::grammarPath($score);
            if (($kept[$path] ?? 0) >= self::LINES_PER_PATH) {
                continue;
            }
            $kept[$path] = ($kept[$path] ?? 0) + 1;
            $pairs[] = ['score' => $score, 'text' => (string) $score['text']];
        }

        return $pairs;
    }

    /** @param array<mixed, mixed> $score */
    private static function grammarPath(array $score): string
    {
        $terms = \is_array($score['terms'] ?? null) ? $score['terms'] : [];
        $maintenance = 0;
        $security = '-';
        foreach ($terms as $term) {
            if (\is_array($term) && ($term['part'] ?? null) === 'security') {
                $security = 'x'.(\is_int($term['multiplier'] ?? null) ? $term['multiplier'] : '');
            } else {
                ++$maintenance;
            }
        }
        $modifiers = [];
        foreach (\is_array($score['modifiers'] ?? null) ? $score['modifiers'] : [] as $modifier) {
            if (\is_array($modifier)) {
                $modifiers[] = (\is_string($modifier['reason'] ?? null) ? $modifier['reason'] : '').(($modifier['before'] ?? null) === ($modifier['after'] ?? null) ? '=' : '<');
            }
        }

        return implode('|', [$maintenance, $security, implode(',', $modifiers), ($score['rounded_down'] ?? false) === true ? 'r' : '', \count(\is_array($score['accepted'] ?? null) ? $score['accepted'] : [])]);
    }

    /**
     * The model's goldens that PHP owns, against PHP's. A golden is compared only when the fields
     * it renders from (§7.11, the x-rendered-from lists) agree between the model's case document
     * and PHP's: a hydrated input that differs from the model's tests the hydration, not the grammar.
     *
     * @param array<mixed, mixed>                     $case
     * @param array<string, string|list<string>|null> $goldens
     */
    private function compare(string $id, array $case, array $goldens, string $json): void
    {
        $model = JsonPath::arrayAt($case, ['golden']);
        $theirs = JsonPath::decodeFile(self::DOCUMENTS.'cases/'.CaseRecording::fileName($id).'.json');
        $ours = (array) json_decode($json, true);
        foreach (self::RENDERED_FROM as $key => $inputs) {
            if (!\array_key_exists($key, $model)) {
                continue;
            }
            $package = JsonPath::stringAt($case, ['finding', 'package']);
            if (self::inputsOf($theirs, $inputs, $package) !== self::inputsOf($ours, $inputs, $package)) {
                $this->skipped[$key] = ($this->skipped[$key] ?? 0) + 1;
                continue;
            }
            $this->compared[$key] = ($this->compared[$key] ?? 0) + 1;
            $expected = $model[$key] === [] && $goldens[$key] === null ? null : $model[$key];
            if ($expected !== $goldens[$key]) {
                $this->mismatches[$id][$key] = ['expected' => $expected, 'actual' => $goldens[$key]];
            }
        }
    }

    /**
     * The finding of $package in a report-2 document: a case document holds the case finding, alone
     * or with the findings that I25 needs.
     *
     * @param array<mixed, mixed> $document
     *
     * @return array<mixed, mixed>
     *
     * @throws \UnexpectedValueException when the document holds no finding of $package
     */
    public static function findingOf(array $document, string $package): array
    {
        foreach (JsonPath::arrayAt($document, ['findings']) as $finding) {
            if (\is_array($finding) && ($finding['package'] ?? null) === $package) {
                return $finding;
            }
        }
        throw new \UnexpectedValueException('the document holds no finding of '.$package);
    }

    /**
     * The fields of $document that the string of $inputs renders from, every `text` key left out:
     * the root for the run's gate line, else the case's finding with `run`.
     *
     * @param array<mixed, mixed> $document
     *
     * @return array<mixed, mixed>
     */
    private static function inputsOf(array $document, string $inputs, string $package): array
    {
        $paths = $inputs === 'commands' ? self::COMMANDS : ReferenceText::INPUTS[$inputs];
        $fields = $inputs === 'gate.text' ? $document : self::findingOf($document, $package) + ['run' => JsonPath::arrayAt($document, ['run'])];
        $kept = self::withoutText(Fields::only($fields, $paths));

        return \is_array($kept) ? $kept : [];
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function withoutText($value)
    {
        if (!\is_array($value)) {
            return $value;
        }
        unset($value['text']);

        return array_map([self::class, 'withoutText'], $value);
    }

    /** The years values and the commands of a written report, for the tables. */
    private function collect(string $json): void
    {
        $report = (array) json_decode($json, true);
        foreach (\is_array($report['findings'] ?? null) ? $report['findings'] : [] as $finding) {
            if (!\is_array($finding)) {
                continue;
            }
            foreach (\is_array($finding['age'] ?? null) ? $finding['age'] : [] as $reading) {
                if (\is_array($reading) && (\is_float($reading['years'] ?? null) || \is_int($reading['years'] ?? null))) {
                    $this->years[(string) $reading['years']] = true;
                }
            }
            $moves = \is_array($finding['next_step'] ?? null) ? array_merge([$finding['next_step']], \is_array($finding['next_step']['also'] ?? null) ? $finding['next_step']['also'] : []) : [];
            foreach ($moves as $move) {
                foreach (\is_array($move) && \is_array($move['commands'] ?? null) ? $move['commands'] : [] as $argv) {
                    $words = array_values(array_filter(\is_array($argv) ? $argv : [], 'is_string'));
                    $this->argvs[implode("\0", $words)] = $words;
                }
            }
        }
    }

    /** @param array<string, string> $formats */
    private function putFormats(string $name, array $formats): void
    {
        $this->putRaw('report-2/'.$name.'.json', $formats['json']);
        $this->putRaw('sarif/'.$name.'.json', $formats['sarif']);
        $this->putRaw('gitlab/'.$name.'.json', $formats['gitlab']);
        foreach (self::TEXT as $format => $suffix) {
            $this->putRaw('text/'.$name.$suffix, $formats[$format]);
        }
    }

    /** @param mixed $data */
    private function put(string $path, $data): void
    {
        $json = JsonWriter::encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        if ($json === null) {
            throw new \RuntimeException('cannot encode '.$path.': '.json_last_error_msg());
        }
        $this->putRaw($path, $json."\n");
    }

    private function putRaw(string $path, string $contents): void
    {
        if (isset($this->files[$path])) {
            throw new \LogicException('two writes of '.$path);
        }
        $this->files[$path] = VersionMask::apply($contents);
    }
}
