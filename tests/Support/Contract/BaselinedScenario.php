<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support\Contract;

use Composer\Console\Application;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Repository\RepositoryFactory;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analysis;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineFile;
use Lockrot\Clock;
use Lockrot\Composer\LockrotCommand;
use Lockrot\Config\Gate;
use Lockrot\Config\Policy;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Deadline;
use Lockrot\Explain\Explanation;
use Lockrot\Html\PageData;
use Lockrot\Html\ReportDocument;
use Lockrot\Json\JsonWriter;
use Lockrot\Json\Schemas;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Solver\MoveCheckers;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\SplitStreamTester;
use Lockrot\Tests\Support\VersionMask;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\MoveChecks;
use Lockrot\Version;

/**
 * One real project under a committed baseline-2 (items C78). The earlier lock writes the baseline on
 * its own day. The as-of rule removes the advisories reported after that day. The recorder checks the
 * later lock against the file in every format, regenerates the file and checks again. Every answer
 * comes from the scenario's recorded HTTP. A request that no recording answers fails the run.
 */
final class BaselinedScenario
{
    public const FIXTURES = __DIR__.'/../../fixtures/';
    public const SCENARIOS = [
        'wallabag-main' => ['before' => 'baselined/wallabag-main/before', 'after' => 'apps/wallabag_wallabag', 'before_ref' => 'c7e57555632f277f6260eb3f7a953500624227a8', 'before_kind' => 'commit', 'after_kind' => 'fixture', 't0' => '2024-05-17T00:00:00+00:00', 't1' => '2026-10-14T00:00:00+00:00', 'dev' => true, 'targets' => []],
        'wallabag-2.6' => ['before' => 'baselined/wallabag-2.6/before', 'after' => 'baselined/wallabag-2.6/after', 'before_ref' => '2.6.10', 'before_kind' => 'tag', 'after_kind' => 'tag', 'after_ref' => '2.6.14', 't0' => '2024-11-03T15:02:01+00:00', 't1' => '2026-10-14T00:00:00+00:00', 'dev' => false, 'targets' => ['8.3']],
    ];
    public const TARGET_PHP = '8.4';
    private const PROJECT = 'wallabag/wallabag';
    private const BASELINE = 'lockrot-baseline.json';
    private const SERVER = 'http://127.0.0.1';
    private const GATES = 'left-behind,vulnerable-high';
    /** The signals whose data holds the dates that R5 lists. */
    private const DATED_SIGNALS = ['S2', 'S4', 'S8'];
    private const JSON = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    private string $name;
    /** @var array{before: string, after: string, before_ref: string, before_kind: string, after_kind: string, after_ref?: string, t0: string, t1: string, dev: bool, targets: list<string>} */
    private array $scenario;
    /** Whether each step's exit code comes from a LockrotCommand run, else from the gate as the command maps it. */
    private bool $cli;
    private ?FixtureRepositoryServer $server = null;
    private string $github = '';
    /** @var array<string, string> each written file by its path below the scenario directory */
    private array $files = [];
    /** @var list<array{step: string, argv: list<string>, exit: int}> */
    private array $runs = [];

    private function __construct(string $name, bool $cli)
    {
        if (!isset(self::SCENARIOS[$name])) {
            throw new \InvalidArgumentException('no baselined scenario '.$name);
        }
        $this->name = $name;
        $this->scenario = self::SCENARIOS[$name];
        $this->cli = $cli;
    }

    /**
     * Every file of the scenario, `provenance.json` included, by its path below
     * tests/fixtures/baselined/<scenario>/.
     *
     * @param bool $cli true to take each step's exit code from a LockrotCommand run of that step
     *
     * @return array<string, string>
     */
    public static function record(string $name, bool $cli = false): array
    {
        $scenario = new self($name, $cli);
        $scenario->server = self::repositoryServer($name);
        $scenario->server->start();
        $scenario->github = $scenario->githubAnswers();
        try {
            return $scenario->steps();
        } finally {
            $scenario->server->stop();
            array_map('unlink', glob($scenario->github.'/*') ?: []);
            rmdir($scenario->github);
        }
    }

    /** @return array<string, string> */
    private function steps(): array
    {
        $options = array_merge(['--target-php='.self::TARGET_PHP], $this->scenario['dev'] ? ['--dev'] : []);
        $beforeDir = $this->scenario['before'];
        $afterDir = $this->scenario['after'];
        $t1 = $this->scenario['t1'];
        $before = $this->analyse($beforeDir, $this->scenario['t0'], self::TARGET_PHP);
        $t0 = $before->report()->withRun($this->settings(Gate::MODE_GENERATE_BASELINE, 'high', self::TARGET_PHP, $beforeDir));
        $this->put('t0/report.json', Formatters::for('json')->format($t0));
        $this->step('generate', $beforeDir, $this->scenario['t0'], array_merge(['--generate-baseline', '--fail-on=high', '--output=json:t0/report.json'], $options), null, $t0);
        [$baseline, $asOf] = $this->asOf(Baseline::fromReport($t0), $t0);
        $this->put(self::BASELINE, ContractBundle::written($baseline));

        $after = $this->analyse($afterDir, $t1, self::TARGET_PHP);
        $check = $this->checked($after->report(), $baseline, 'high', self::TARGET_PHP);
        $this->putFormats('check/report', $check, ['json', 'table', 'markdown', 'github', 'gitlab', 'sarif']);
        $this->putJson('check/report.bundle.json', (new ReportDocument($check, new PageData($after, new Thresholds(), self::TARGET_PHP, self::projectPhp($afterDir), [2])))->toArray());
        $this->step('check', $afterDir, $t1, array_merge(['--fail-on=high', '--format=json'], $options), $baseline, $check);
        $explained = $this->explain($after, $check);
        $gates = $this->checked($after->report(), $baseline, self::GATES, self::TARGET_PHP);
        $this->putFormats('check/gates-left-behind-vulnerable-high', $gates, ['json', 'sarif']);
        $this->step('check', $afterDir, $t1, array_merge(['--fail-on='.self::GATES, '--format=json'], $options), $baseline, $gates);
        foreach ($this->scenario['targets'] as $target) {
            $other = $this->checked($this->analyse($afterDir, $t1, $target)->report(), $baseline, 'high', $target);
            $this->putFormats('check/target-'.$target, $other, ['json']);
            $this->step('check', $afterDir, $t1, array_merge(['--fail-on=high', '--format=json', '--target-php='.$target], $this->scenario['dev'] ? ['--dev'] : []), $baseline, $other);
        }

        $regenerate = $after->report()->withRun($this->settings(Gate::MODE_GENERATE_BASELINE, 'high', self::TARGET_PHP, $afterDir));
        $regenerated = Baseline::fromReport($regenerate, $baseline);
        $this->put('regenerate/'.self::BASELINE, ContractBundle::written($regenerated));
        $this->step('regenerate', $afterDir, $t1, array_merge(['--generate-baseline', '--fail-on=high'], $options), $baseline, $regenerate);
        $recheck = $this->checked($after->report(), $regenerated, 'high', self::TARGET_PHP);
        $this->putFormats('regenerate/report', $recheck, ['json']);
        $this->step('recheck', $afterDir, $t1, array_merge(['--fail-on=high', '--format=json'], $options), $regenerated, $recheck);

        $this->putJson('provenance.json', $this->provenance($asOf, $explained, $before, $t0));
        ksort($this->files);

        return $this->files;
    }

    /**
     * Records one step of the scenario with its exit code: lockrot's own, when the recording runs
     * the command, else the gate's as LockrotCommand maps it.
     *
     * @param list<string> $argv
     */
    private function step(string $step, string $side, string $now, array $argv, ?Baseline $baseline, Report $report): void
    {
        $gate = $report->gate();
        $exit = $gate !== null && $gate->fails() ? Policy::EXIT_FINDINGS : Policy::EXIT_OK;
        $this->runs[] = ['step' => $step, 'argv' => $argv, 'exit' => $this->cli ? $this->command($side, $now, $argv, $baseline) : $exit];
    }

    /**
     * The exit code of LockrotCommand run with $argv in a copy of $side at $now, with $baseline as
     * its lockrot-baseline.json.
     *
     * @param list<string> $argv
     */
    private function command(string $side, string $now, array $argv, ?Baseline $baseline): int
    {
        $dir = sys_get_temp_dir().'/lockrot-baselined-run-'.bin2hex(random_bytes(8));
        mkdir($dir);
        copy(self::FIXTURES.$side.'/composer.json', $dir.'/composer.json');
        copy(self::FIXTURES.$side.'/composer.lock', $dir.'/composer.lock');
        // An --output file goes below the directory that lockrot runs in, which must exist.
        mkdir($dir.'/t0');
        if ($baseline !== null) {
            BaselineFile::resolve($dir, self::BASELINE, Version::STRING)->write($baseline);
        }
        $factory = fn (): Analyzer => $this->analyzer(Clock::fixed($now), self::argValue($argv, '--target-php'), $side);
        $command = new LockrotCommand($factory);
        $app = new Application();
        $app->setAutoExit(false);
        $app->add($command);
        $command->setApplication($app);
        $input = [];
        foreach ($argv as $arg) {
            $parts = explode('=', $arg, 2);
            $input[$parts[0]] = $parts[1] ?? true;
        }
        $cwd = (string) getcwd();
        chdir($dir);
        try {
            return (new SplitStreamTester($command))->execute($input);
        } finally {
            chdir($cwd);
            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($dir);
        }
    }

    /** @param list<string> $argv */
    private static function argValue(array $argv, string $option): string
    {
        foreach ($argv as $arg) {
            if (strpos($arg, $option.'=') === 0) {
                return substr($arg, \strlen($option) + 1);
            }
        }

        throw new \LogicException('no '.$option.' in the step');
    }

    /**
     * R4: the advisory ids that the T0 run read and that were reported after T0 leave the file, then
     * every entry with no fact left. An advisory with no report date stays.
     *
     * @return array{Baseline, array{removed: list<array{package: string, id: string, reported_at: string}>, entries_removed: list<string>}}
     */
    private function asOf(Baseline $written, Report $t0): array
    {
        $t0Time = new \DateTimeImmutable((string) $this->scenario['t0']);
        $later = [];
        foreach (JsonPath::arrayAt(JsonPath::decoded($t0->findingRows()), []) as $package => $finding) {
            foreach (\is_array($finding) ? JsonPath::arrayAt($finding, ['signals']) : [] as $signal) {
                if (!\is_array($signal) || ($signal['id'] ?? null) !== 'S9') {
                    continue;
                }
                foreach (JsonPath::arrayAt($signal, ['data', 'advisories']) as $row) {
                    $at = \is_array($row) && \is_string($row['reported_at'] ?? null) ? $row['reported_at'] : null;
                    if ($at !== null && new \DateTimeImmutable($at) > $t0Time) {
                        $later[(string) $package][JsonPath::stringAt($row, ['id'])] = $at;
                    }
                }
            }
        }
        $data = JsonPath::decoded($written->toArray());
        $removed = [];
        $entriesRemoved = [];
        $findings = JsonPath::arrayAt($data, ['findings']);
        foreach ($findings as $package => $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $advisories = \is_array($entry['advisories'] ?? null) ? $entry['advisories'] : [];
            foreach ($later[(string) $package] ?? [] as $id => $at) {
                if (isset($advisories[$id])) {
                    unset($advisories[$id]);
                    $removed[] = ['package' => (string) $package, 'id' => (string) $id, 'reported_at' => $at];
                }
            }
            unset($entry['advisories']);
            if ($advisories !== []) {
                $entry['advisories'] = $advisories;
            }
            if (($entry['flags'] ?? []) === [] && !isset($entry['advisories'])) {
                unset($findings[$package]);
                $entriesRemoved[] = (string) $package;
                continue;
            }
            $findings[$package] = $entry;
        }
        $data['findings'] = $findings;

        return [Baseline::fromArray(ContractBundle::stringKeys(['$schema' => Schemas::url(Schemas::BASELINE, Baseline::SCHEMA)] + $data)), ['removed' => $removed, 'entries_removed' => $entriesRemoved]];
    }

    /**
     * R6: one package per standing shape, the first by name, as explain-2.
     *
     * @return list<string>
     */
    private function explain(Analysis $analysis, Report $check): array
    {
        $shapes = [];
        foreach (JsonPath::arrayAt(JsonPath::decoded($check->findingRows()), []) as $package => $finding) {
            if (!\is_array($finding) || !\is_array($finding['baseline'] ?? null)) {
                continue;
            }
            $status = $finding['baseline']['status'] ?? null;
            $worsened = \is_array($finding['baseline']['worsened_by'] ?? null) ? $finding['baseline']['worsened_by'] : [];
            $kinds = array_map(static fn ($fact): string => \is_array($fact) && \is_string($fact['kind'] ?? null) ? $fact['kind'] : '', $worsened);
            $covered = array_filter(\is_array($finding['flags'] ?? null) ? $finding['flags'] : [], static fn ($flag): bool => \is_array($flag) && \is_array($flag['baseline'] ?? null) && ($flag['baseline']['state'] ?? null) === 'covered');
            $dev = ($finding['dev'] ?? false) === true && \is_array($finding['baseline']['recorded'] ?? null);
            foreach (['known' => $status === 'known', 'new' => $status === 'new', 'worsened by a flag' => \in_array('flag', $kinds, true), 'worsened by an advisory' => \in_array('advisory', $kinds, true), 'covered' => $covered !== [], 'dev' => $dev] as $shape => $matches) {
                if ($matches && !isset($shapes[$shape])) {
                    $shapes[$shape] = (string) $package;
                }
            }
        }
        $packages = array_values(array_unique($shapes));
        sort($packages);
        foreach ($packages as $package) {
            $finding = null;
            foreach ($check->findings() as $each) {
                $finding = $each->package() === $package ? $each : $finding;
            }
            $facts = $analysis->facts($package);
            if ($facts === null || $finding === null) {
                throw new \LogicException('no facts for '.$package);
            }
            $this->put('check/explain/'.str_replace('/', '--', $package).'.json', (new ExplainFormatter())->json(new Explanation($finding, $facts, new Thresholds(), self::TARGET_PHP, $check, self::projectPhp($this->scenario['after']))));
        }

        return $packages;
    }

    /** The check of the later lock against $baseline. Every package of the lock counts against staleness, as LockrotCommand counts. */
    private function checked(Report $report, Baseline $baseline, string $failOn, string $target): Report
    {
        $names = array_map(static fn ($package): string => $package->name(), LockFile::fromFile(self::FIXTURES.$this->scenario['after'].'/composer.lock')->packages(true));
        $withRun = $report->withRun($this->settings(Gate::MODE_CHECK, $failOn, $target, $this->scenario['after']));

        return $withRun->withBaseline(BaselineComparison::compare($baseline, $withRun, self::BASELINE, $names))->withRun($this->settings(Gate::MODE_CHECK, $failOn, $target, $this->scenario['after']));
    }

    /** The run settings of the project in $side: its own `require.php`. */
    private function settings(string $mode, string $failOn, string $target, string $side): RunSettings
    {
        return new RunSettings(self::PROJECT, self::PROJECT, $target, RunSettings::SOURCE_OPTION, 'composer.lock', FailOn::parse($failOn), RunSettings::SOURCE_OPTION, new Thresholds(), self::projectPhp($side), false, $mode, $this->scenario['dev']);
    }

    private function analyse(string $dir, string $now, string $target): Analysis
    {
        $project = ProjectConfig::fromFile(self::FIXTURES.$dir.'/composer.json');
        $lock = LockFile::fromFile(self::FIXTURES.$dir.'/composer.lock');
        $dev = $this->scenario['dev'];

        return $this->analyzer(Clock::fixed($now), $target, $dir)->analyzeWithFacts($lock->packages($dev), $lock, $project, $dev);
    }

    /** An Analyzer that checks the moves of the project in $side, as lockrot run in it does. */
    private function analyzer(Clock $clock, string $target, string $side): Analyzer
    {
        $server = $this->server;
        if ($server === null) {
            throw new \LogicException('the fixture server is not started');
        }
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $project = ProjectConfig::fromFile(self::FIXTURES.$side.'/composer.json');
        $json = JsonPath::decodeFile(self::FIXTURES.$side.'/composer.json');
        $config = $server->config();
        $settings = \is_array($json['config'] ?? null) ? $json['config'] : [];
        $platform = $settings['platform'] ?? [];
        $config->merge(['config' => ['platform' => \is_array($platform) ? $platform : []]]);
        $io = new NullIO();
        /** @var array<string, mixed> $json */
        $root = (new RootPackageLoader(RepositoryFactory::manager($io, $config, Factory::createHttpDownloader($io, $config)), $config, null, null, $io))->load($json + ['version' => '1.0.0']);
        $checks = MoveChecks::of(MoveCheckers::forProject($root, LockFile::fromFile(self::FIXTURES.$side.'/composer.lock'), $project, $config, $server->repositories(), $target, false, Deadline::never()));

        return (new Analyzer(
            new RepositoryMetadataLoader($server->repositories(), $clock),
            new ActivityClient(new RecordedHttpClient($this->github), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), $target, PhpReleaseDates::load(), $project->requirePhp()),
            $clock,
            false,
            new RepositoryAdvisoryLoader($server->repositories())
        ))->withMoveChecks($checks);
    }

    /**
     * The scenario's Composer repository, not started: the recorded answers of both locks' names,
     * and their advisories. The move checks also ask names that no lock lists: the answers of
     * `corpus/solver-p2` serve each one that the scenario's own recordings do not answer.
     */
    public static function repositoryServer(string $name): FixtureRepositoryServer
    {
        $scenario = self::SCENARIOS[$name];
        $http = self::FIXTURES.'baselined/'.$name.'/http/';
        $locks = [self::FIXTURES.$scenario['before'].'/composer.lock', self::FIXTURES.$scenario['after'].'/composer.lock'];
        $solverP2 = self::FIXTURES.'corpus/solver-p2';
        $solverNames = array_values(array_filter(JsonPath::decodeFile($solverP2.'/names.json'), 'is_string'));
        $server = FixtureRepositoryServer::fromLockFiles($locks, self::FIXTURES.'http/p2', $solverNames, [['https://repo.packagist.org/p2/', $http.'p2'], ['https://repo.packagist.org/p2/', $solverP2]]);
        $advisories = [];
        foreach ([$scenario['before'], $scenario['after']] as $side) {
            $recorded = is_file($http.'advisories/'.basename((string) $side).'.json') ? $http.'advisories/' : self::FIXTURES.'http/advisories/';
            $result = HttpResult::fromEnvelopeJson('https://packagist.org/api/security-advisories/', (string) file_get_contents($recorded.basename((string) $side).'.json'));
            $answer = $result === null ? null : json_decode((string) $result->body(), true);
            foreach (\is_array($answer) && \is_array($answer['advisories'] ?? null) ? $answer['advisories'] : [] as $package => $list) {
                if (\is_array($list)) {
                    /** @var list<array<string, mixed>> $list the recorded answer of packagist.org */
                    $advisories[(string) $package] = $list;
                }
            }
        }
        $server->withAdvisoryApi($advisories);

        return $server;
    }

    /** The shared GitHub answers and the scenario's own, in one new temporary directory. */
    private function githubAnswers(): string
    {
        $dir = sys_get_temp_dir().'/lockrot-baselined-github-'.bin2hex(random_bytes(8));
        mkdir($dir);
        foreach ([self::FIXTURES.'http/github', self::FIXTURES.'baselined/'.$this->name.'/http/github'] as $source) {
            foreach (glob($source.'/*.json') ?: [] as $file) {
                copy($file, $dir.'/'.basename($file));
            }
        }

        return $dir;
    }

    private static function projectPhp(string $side): ?string
    {
        return ProjectConfig::fromFile(self::FIXTURES.$side.'/composer.json')->requirePhp();
    }

    /** @param list<string> $formats */
    private function putFormats(string $stem, Report $report, array $formats): void
    {
        $suffix = ['json' => '.json', 'table' => '.table.txt', 'markdown' => '.md', 'github' => '.github.txt', 'gitlab' => '.gitlab.json', 'sarif' => '.sarif.json'];
        $run = $report->run();
        $context = FormatContext::forFailOn('composer.lock', $run === null || $run->failOn() === null ? FailOn::none() : $run->failOn());
        // The formats read `composer.lock` from the working directory, as lockrot run in the project does: SARIF takes its
        // line numbers from it.
        $cwd = (string) getcwd();
        chdir(self::FIXTURES.$this->scenario['after']);
        try {
            foreach ($formats as $format) {
                $this->put($stem.$suffix[$format], Formatters::for($format, $context)->format($report));
            }
        } finally {
            chdir($cwd);
        }
    }

    /** @param array<mixed, mixed> $data */
    private function putJson(string $path, array $data): void
    {
        $this->put($path, JsonWriter::encode($data, self::JSON)."\n");
    }

    /** The fixture server listens on a free port: its address loses the port, so two recordings agree. */
    private function put(string $path, string $contents): void
    {
        // Markdown escapes the scheme of a bare URL as `http\://`.
        $this->files[$path] = (string) preg_replace('{http(\\\\?)://127\.0\.0\.1:\d+}', 'http$1://127.0.0.1', VersionMask::apply($contents));
    }

    /**
     * @param array{removed: list<array{package: string, id: string, reported_at: string}>, entries_removed: list<string>} $asOf
     * @param list<string>                                                                                                $explained
     *
     * @return array<string, mixed>
     */
    private function provenance(array $asOf, array $explained, Analysis $before, Report $t0): array
    {
        $side = static function (string $dir, string $ref, string $kind): array {
            $path = self::FIXTURES.$dir;

            return ['ref' => $ref, 'ref_kind' => $kind, 'lock_sha256' => (string) hash_file('sha256', $path.'/composer.lock'), 'manifest_sha256' => (string) hash_file('sha256', $path.'/composer.json')];
        };
        $files = [];
        foreach ($this->files as $path => $contents) {
            $files[$path] = hash('sha256', $contents);
        }

        return [
            'scenario' => $this->name,
            'project' => self::PROJECT,
            'recorded_by' => 'tools/report/record --baselined '.$this->name,
            'before' => $side($this->scenario['before'], $this->scenario['before_ref'], $this->scenario['before_kind']),
            'after' => $side($this->scenario['after'], $this->scenario['after_ref'] ?? 'tests/fixtures/'.$this->scenario['after'], $this->scenario['after_kind']),
            'clocks' => ['t0' => $this->scenario['t0'], 't1' => $this->scenario['t1']],
            'options' => array_merge(['--target-php='.self::TARGET_PHP], $this->scenario['dev'] ? ['--dev'] : []),
            'runs' => $this->runs,
            'as_of' => $asOf + ['later_than_t0' => $this->laterThan($before, $t0)],
            'explain' => $explained,
            'version_mask' => ['paths' => VersionMask::PATHS, 'value' => VersionMask::VALUE],
            'server_mask' => self::SERVER.':<port> reads '.self::SERVER,
            'files' => $files,
        ];
    }

    /**
     * R5: every date that the T0 run read and that is later than T0: the age readings, the S2, S4
     * and S8 dates and the dates of the release-branch rows. Δs is clamped to 0, so no years value
     * can show it.
     *
     * @return list<array{package: string, reading: string, at: string}>
     */
    private function laterThan(Analysis $before, Report $t0): array
    {
        $limit = new \DateTimeImmutable($this->scenario['t0']);
        $later = [];
        $add = static function (string $package, string $reading, $at) use ($limit, &$later): void {
            if ($at instanceof \DateTimeImmutable) {
                $at = $at->format(\DATE_ATOM);
            }
            if (\is_string($at) && new \DateTimeImmutable($at) > $limit) {
                $later[] = ['package' => $package, 'reading' => $reading, 'at' => $at];
            }
        };
        foreach (JsonPath::arrayAt(JsonPath::decoded($t0->findingRows()), []) as $package => $finding) {
            if (!\is_array($finding)) {
                continue;
            }
            foreach (\is_array($finding['age'] ?? null) ? $finding['age'] : [] as $reading => $value) {
                $add((string) $package, 'age.'.$reading.'.at', \is_array($value) ? ($value['at'] ?? null) : null);
            }
            foreach (\is_array($finding['signals'] ?? null) ? $finding['signals'] : [] as $signal) {
                $id = \is_array($signal) && \is_string($signal['id'] ?? null) ? $signal['id'] : '';
                foreach (\in_array($id, self::DATED_SIGNALS, true) && \is_array($signal['data'] ?? null) ? $signal['data'] : [] as $key => $value) {
                    if (\is_string($value) && preg_match('{^\d{4}-\d\d-\d\dT}', $value) === 1) {
                        $add((string) $package, $id.'.data.'.$key, $value);
                    }
                }
            }
        }
        foreach ($t0->findings() as $finding) {
            $facts = $before->facts($finding->package());
            if ($facts === null) {
                continue;
            }
            foreach ((new Explanation($finding, $facts, new Thresholds(), self::TARGET_PHP, $t0, self::projectPhp($this->scenario['before'])))->branches() as $row) {
                foreach (['highest_released', 'highest_commit_date', 'newest_dated_released'] as $key) {
                    $add($finding->package(), 'branches['.$row['branch'].'].'.$key, $row[$key]);
                }
            }
        }

        return $later;
    }
}
