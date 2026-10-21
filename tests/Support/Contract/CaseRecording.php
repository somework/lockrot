<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support\Contract;

use Lockrot\Analyzer\Analysis;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Analyzer\UpdateNow;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Explain\Explanation;
use Lockrot\Output\AgeText;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Output\FindingBlock;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\ShellCommand;

/**
 * One case of tests/fixtures/flags/cases.json as lockrot writes it. The report holds the case
 * package's finding with the case's own run, and the findings of the case's `parents`. A case with
 * parents is a whole report, and I25 holds over it. The recording gives every format, the explain-2
 * document and the strings that the PHP grammars own. The report keeps the run notes and the
 * advisory lookup of the case's hydrated lock.
 */
final class CaseRecording
{
    /** The formats of a recorded case, by the file suffix each one is written to. */
    public const FORMATS = ['json' => '.json', 'table' => '.table.txt', 'markdown' => '.md', 'github' => '.github.txt', 'gitlab' => '.gitlab.json', 'sarif' => '.sarif.json'];

    private const CASE_BASELINES = __DIR__.'/../../fixtures/schema/documents/baseline/cases/';
    private const BASELINE_PATH = 'lockrot-baseline.json';

    /** @var array<mixed, mixed> */
    private array $case;
    private Report $report;
    private Analysis $analysis;
    private Finding $finding;

    /** @param array<mixed, mixed> $case */
    private function __construct(array $case, Report $report, Analysis $analysis, Finding $finding)
    {
        $this->case = $case;
        $this->report = $report;
        $this->analysis = $analysis;
        $this->finding = $finding;
    }

    /**
     * @param array<mixed, mixed> $case a case with an `inputs` block
     *
     * @throws \InvalidArgumentException for a case without an `inputs` block
     */
    public static function of(array $case): self
    {
        $analysis = CaseHydrator::analysisOf($case);
        $package = JsonPath::stringAt($case, ['finding', 'package']);
        $finding = $analysis->finding($package);
        if ($finding === null) {
            throw new \InvalidArgumentException('the case\'s lock has no finding for '.$package);
        }
        $findings = [$finding];
        foreach (\is_array($case['parents'] ?? null) ? $case['parents'] : [] as $parent) {
            $name = JsonPath::stringAt(JsonPath::decoded($parent), ['package']);
            $found = $analysis->finding($name);
            if ($found === null) {
                throw new \InvalidArgumentException('the case\'s lock has no finding for '.$name);
            }
            $findings[] = $found;
        }
        $full = $analysis->report();
        $lookup = $full->advisoryLookup();
        $coverage = new AdvisoryCoverage($lookup->scope(), JsonPath::stringAt(JsonPath::decoded($lookup), ['scope_source']), $lookup->repositories(), $lookup->otherRepositories(), []);
        // A whole report checks the packages it holds. A case finding alone keeps the count of its lock.
        $checked = isset($case['parents']) ? \count($findings) : $full->packagesChecked();
        $report = (new Report($findings, $full->runNotes(), $full->generatedAt(), $checked, $full->notFromComposerRepository(), null, $full->activityCacheOldestAt(), $full->includesDev(), $coverage))
            ->withRun(self::run(JsonPath::arrayAt($case, ['run'])))
            ->withPhpWindow($full->phpWindow());
        $checks = CaseHydrator::moveChecksOf(JsonPath::arrayAt($case, ['inputs']));
        $moveCheck = $full->moveCheck();
        if ($checks !== null && $moveCheck !== null) {
            $report = $report->withMoveChecks($moveCheck, UpdateNow::of($report->sorted(), $checks));
        }
        $baseline = self::baselineOf(JsonPath::stringAt($case, ['id']));
        if ($baseline !== null) {
            $report = $report->withBaseline(BaselineComparison::compare($baseline, $report, self::BASELINE_PATH, [$package]));
        }
        foreach ($report->findings() as $written) {
            if ($written->package() === $package) {
                return new self($case, $report, $analysis, $written);
            }
        }
        throw new \LogicException('the report lost the finding of '.$package);
    }

    /** The file name of a case: `‡` reads `-dagger`, as tools/schema/project_fixtures.py names the case documents. */
    public static function fileName(string $id): string
    {
        return (string) preg_replace('{[^A-Za-z0-9._-]+}', '_', str_replace('‡', '-dagger', $id));
    }

    public function report(): Report
    {
        return $this->report;
    }

    /** @return array<string, string> each format's output, by format */
    public function formats(): array
    {
        return self::formatted($this->report, $this->failOn(), CaseHydrator::lockJson([JsonPath::stringAt($this->case, ['id'])]));
    }

    /**
     * Each format's output of $report, written as lockrot run in the project writes it: beside a
     * `composer.lock` with $lock, from which SARIF and the annotations take each package's line.
     *
     * @return array<string, string> by format
     */
    public static function formatted(Report $report, FailOn $failOn, string $lock): array
    {
        $dir = sys_get_temp_dir().'/lockrot-case-'.bin2hex(random_bytes(8));
        mkdir($dir);
        file_put_contents($dir.'/composer.lock', $lock);
        $cwd = (string) getcwd();
        chdir($dir);
        try {
            $context = FormatContext::forFailOn('composer.lock', $failOn);
            $outputs = [];
            foreach (array_keys(self::FORMATS) as $format) {
                $outputs[$format] = Formatters::for($format, $context)->format($report, true);
            }

            return $outputs;
        } finally {
            chdir($cwd);
            unlink($dir.'/composer.lock');
            rmdir($dir);
        }
    }

    public function explain(): string
    {
        $facts = $this->analysis->facts($this->finding->package());
        $run = $this->report->run();
        if ($facts === null || $run === null) {
            throw new \LogicException('no facts or run for '.$this->finding->package());
        }
        $explanation = new Explanation($this->finding, $facts, $this->thresholds(), $run->targetPhp(), $this->report, $this->projectPhp());

        return (new ExplainFormatter())->json($explanation);
    }

    /**
     * The strings that the PHP grammars own, by the key of the model's `golden` block. A string
     * that the finding does not print is null. `score_text`, `next_step_text` and `run_gate_line`
     * are read from the written report.
     *
     * @return array<string, string|list<string>|null>
     */
    public function goldens(string $json): array
    {
        $written = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($written)) {
            throw new \UnexpectedValueException('the report is not a JSON object');
        }
        $finding = null;
        foreach (JsonPath::arrayAt($written, ['findings']) as $row) {
            if (\is_array($row) && ($row['package'] ?? null) === $this->finding->package()) {
                $finding = $row;
            }
        }
        if ($finding === null) {
            throw new \UnexpectedValueException('the report has no finding for '.$this->finding->package());
        }
        $baseline = $this->report->baseline();
        $gate = $this->report->gate();
        $move = $this->finding->nextStep();
        $commands = $move === null ? [] : $move->commands();

        return [
            'score_text' => JsonPath::stringAt($finding, ['score', 'text']),
            'next_step_text' => \is_array($finding['next_step'] ?? null) ? JsonPath::stringAt($finding, ['next_step', 'text']) : null,
            'gate_line' => FindingBlock::gateLine($this->finding, $gate === null ? null : $gate->standingOf($this->finding->package())),
            'run_gate_line' => \is_array($written['gate'] ?? null) ? JsonPath::stringAt($written, ['gate', 'text']) : null,
            'age_line' => AgeText::line($this->finding),
            'explain_age' => AgeText::block($this->finding, $this->thresholds()),
            'baseline_suffix' => FindingBlock::baselineSuffix($this->finding, $baseline === null ? null : $baseline->standingOf($this->finding->package())),
            'commands_posix' => self::rendered(array_map([ShellCommand::class, 'posix'], $commands)),
            'commands_cmd' => self::rendered(array_map([ShellCommand::class, 'cmd'], $commands)),
        ];
    }

    private function thresholds(): Thresholds
    {
        return self::thresholdsOf(JsonPath::arrayAt($this->case, ['run']));
    }

    private function projectPhp(): ?string
    {
        $php = JsonPath::arrayAt($this->case, ['run'])['project_php'] ?? null;

        return \is_string($php) ? $php : null;
    }

    private function failOn(): FailOn
    {
        $run = $this->report->run();

        return $run === null || $run->failOn() === null ? FailOn::none() : $run->failOn();
    }

    /**
     * @param list<string|null> $commands each rendering of a move's commands
     *
     * @throws \UnexpectedValueException for a command that no shell renders: lockrot writes only argvs that pass CommandArgument
     *
     * @return list<string>
     */
    private static function rendered(array $commands): array
    {
        $rendered = array_values(array_filter($commands, 'is_string'));
        if (\count($rendered) !== \count($commands)) {
            throw new \UnexpectedValueException('a move holds a command that no shell renders');
        }

        return $rendered;
    }

    /** @param array<mixed, mixed> $run the case's report-2 `run` block */
    private static function run(array $run): RunSettings
    {
        return new RunSettings(
            JsonPath::stringAt($run, ['project']),
            JsonPath::stringAt($run, ['root_package']),
            JsonPath::stringAt($run, ['target_php']),
            self::source($run, 'target_php_source'),
            JsonPath::stringAt($run, ['lock_file']),
            FailOn::parse(JsonPath::stringAt($run, ['fail_on'])),
            self::source($run, 'fail_on_source'),
            self::thresholdsOf($run),
            \is_string($run['project_php'] ?? null) ? $run['project_php'] : null,
            JsonPath::boolAt($run, ['strict_network']),
            JsonPath::stringAt($run, ['mode']),
            JsonPath::boolAt($run, ['include_dev'])
        );
    }

    /**
     * @param array<mixed, mixed> $run
     *
     * @return RunSettings::SOURCE_*
     */
    private static function source(array $run, string $key): string
    {
        $source = JsonPath::stringAt($run, [$key]);
        foreach ([RunSettings::SOURCE_OPTION, RunSettings::SOURCE_ENV, RunSettings::SOURCE_CONFIG, RunSettings::SOURCE_PLATFORM, RunSettings::SOURCE_RUNTIME, RunSettings::SOURCE_DEFAULT] as $known) {
            if ($source === $known) {
                return $known;
            }
        }
        throw new \UnexpectedValueException('run.'.$key.' is not a source lockrot writes: '.$source);
    }

    /** @param array<mixed, mixed> $run */
    private static function thresholdsOf(array $run): Thresholds
    {
        $years = static fn (string $key): int => JsonPath::intAt($run, ['thresholds', $key]);

        return new Thresholds($years('release-warn-years'), $years('release-high-years'), $years('push-warn-years'), $years('push-high-years'));
    }

    private static function baselineOf(string $id): ?Baseline
    {
        $path = self::CASE_BASELINES.str_replace('‡', '', $id).'.synthetic.json';
        if (!is_file($path)) {
            return null;
        }

        return Baseline::fromArray(ContractBundle::stringKeys(JsonPath::decodeFile($path)));
    }
}
