<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support\Contract;

use Lockrot\Baseline\Baseline;
use Lockrot\Json\JsonWriter;
use Lockrot\Output\AgeText;
use Lockrot\Output\CauseWords;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Output\RunGateText;
use Lockrot\Output\ShellCommand;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ScoreSweep;
use Lockrot\Tests\Support\VersionMask;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\ScoreModel;

/**
 * The rendering contract bundle of SPEC-0.14.md §7.11, as lockrot writes it: every case of
 * tests/fixtures/flags/cases.json, the composite, the words tables and the tables of the PHP
 * grammars. tools/report/record writes it to tests/fixtures/contract/. Every file carries the
 * tool version through {@see VersionMask}.
 */
final class ContractBundle
{
    public const DIRECTORY = __DIR__.'/../../fixtures/contract';

    /** The text formats, by the suffix of the file each one is written to below `text/`. */
    private const TEXT = ['table' => '.table.txt', 'markdown' => '.md', 'github' => '.github.txt'];
    private const DOCUMENTS = __DIR__.'/../../fixtures/schema/documents/';
    /**
     * The golden keys that the PHP grammars own (§7.11 item 4), each with the document paths that it
     * renders from. `0` is the case's finding.
     */
    private const RENDERED_FROM = [
        'score_text' => [['findings', '0', 'score']],
        'next_step_text' => [['findings', '0', 'next_step'], ['findings', '0', 'package'], ['findings', '0', 'direct'], ['findings', '0', 'direct_dependents'], ['run', 'target_php'], ['run', 'project_php_lowest']],
        'gate_line' => [['findings', '0', 'gate', 'basis'], ['findings', '0', 'score', 'total']],
        'run_gate_line' => [['gate'], ['run', 'fail_on'], ['run', 'fail_on_source'], ['run', 'gates'], ['run', 'mode'], ['run', 'target_php'], ['run', 'target_php_source']],
        'age_line' => [['findings', '0', 'age'], ['findings', '0', 'version'], ['findings', '0', 'branch'], ['findings', '0', 'activity', 'event']],
        'baseline_suffix' => [['findings', '0', 'baseline'], ['findings', '0', 'flags'], ['findings', '0', 'signals']],
        'commands_posix' => [['findings', '0', 'next_step', 'commands']],
        'commands_cmd' => [['findings', '0', 'next_step', 'commands']],
    ];
    /** Up to this many score lines per grammar path of the sweep, as the model's score-lines.json keeps. */
    private const LINES_PER_PATH = 4;
    /** Commands whose quoting differs between the shells, beside the commands that the cases write. */
    private const QUOTING_ROWS = [
        ['composer', 'require', 'vendor/package:^2.0 || ^3.0'],
        ['composer', 'require', 'vendor/package:>=1.2 <2.0'],
        ['composer', 'config', 'extra.lockrot.reason', "the team's fork"],
        ['composer', 'require', 'vendor/package:~1.4,!=1.4.2'],
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
     * Every file of the bundle by its path below tests/fixtures/contract/, and the cases recorded
     * from their committed documents. `provenance.json` and `bundle_sha256` are left out:
     * tools/report/record writes them.
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

    /**
     * A PowerShell rendering of $argv: a word outside the plain set is single-quoted, its quotes doubled.
     *
     * @param list<string> $argv
     */
    public static function powershell(array $argv): string
    {
        return implode(' ', array_map(static function (string $word): string {
            return preg_match('{^[A-Za-z0-9_./:=+^~-]+$}', $word) === 1 ? $word : "'".str_replace("'", "''", $word)."'";
        }, $argv));
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
                $this->putRaw('report-2/'.$name.'.json', (string) file_get_contents(self::DOCUMENTS.'cases/'.$name.'.json'));
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
        $context = FormatContext::forFailOn(null, $failOn);
        $formats = [];
        foreach (array_keys(CaseRecording::FORMATS) as $format) {
            $formats[$format] = Formatters::for($format, $context)->format($report, true);
        }
        $this->putFormats('composite', $formats);
        $this->collect($formats['json']);
    }

    /** The baseline-2 files of the gate rows, through lockrot's reader and writer. */
    private function recordGateRows(): void
    {
        $dir = self::DOCUMENTS.'baseline/gate-rows/';
        $files = glob($dir.'*.json') ?: [];
        sort($files);
        if ($files === []) {
            throw new \UnexpectedValueException('no gate-row baseline in '.$dir);
        }
        foreach ($files as $file) {
            $this->put('baseline-2/'.basename($file), ['$schema' => 'https://lockrot.dev/schema/baseline-2.json'] + Baseline::fromArray(self::stringKeys(JsonPath::decodeFile($file)))->toArray());
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
            $rows[implode("\0", $argv)] = ['argv' => $argv, 'posix' => ShellCommand::posix($argv), 'cmd' => ShellCommand::cmd($argv), 'powershell' => self::powershell($argv)];
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
        foreach (self::RENDERED_FROM as $key => $paths) {
            if (!\array_key_exists($key, $model)) {
                continue;
            }
            if (self::inputsOf($theirs, $paths) !== self::inputsOf($ours, $paths)) {
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
     * @param array<mixed, mixed> $document
     * @param list<list<string>>  $paths
     *
     * @return list<mixed> the values at the paths, every `text` key left out
     */
    private static function inputsOf(array $document, array $paths): array
    {
        $values = [];
        foreach ($paths as $path) {
            $values[] = JsonPath::has($document, $path) ? self::withoutText(self::at($document, $path)) : null;
        }

        return $values;
    }

    /**
     * @param array<mixed, mixed> $document
     * @param list<string>        $path
     *
     * @return mixed
     */
    private static function at(array $document, array $path)
    {
        $node = $document;
        foreach ($path as $key) {
            $node = \is_array($node) ? ($node[$key] ?? null) : null;
        }

        return $node;
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
