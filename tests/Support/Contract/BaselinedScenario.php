<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support\Contract;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analysis;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Clock;
use Lockrot\Config\Gate;
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
use Lockrot\Explain\Explanation;
use Lockrot\Html\PageData;
use Lockrot\Html\ReportDocument;
use Lockrot\Json\JsonWriter;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\VersionMask;
use Lockrot\Verdict\FailOn;

/**
 * One real project under a committed baseline-2 (items C78): the earlier lock writes the baseline on
 * its own day, the as-of rule removes the advisories reported after that day, and the later lock is
 * checked against it in every format, then regenerated and checked again. Every answer comes from
 * the scenario's recorded HTTP; a request that no recording answers fails the run.
 */
final class BaselinedScenario
{
    public const FIXTURES = __DIR__.'/../../fixtures/';
    public const SCENARIOS = [
        'wallabag-main' => ['before' => 'baselined/wallabag-main/before', 'after' => 'apps/wallabag_wallabag', 'before_ref' => 'c7e57555632f277f6260eb3f7a953500624227a8', 'before_kind' => 'commit', 'after_kind' => 'fixture', 't0' => '2024-05-17T00:00:00+00:00', 'dev' => true, 'targets' => []],
        'wallabag-2.6' => ['before' => 'baselined/wallabag-2.6/before', 'after' => 'baselined/wallabag-2.6/after', 'before_ref' => '2.6.10', 'before_kind' => 'tag', 'after_kind' => 'tag', 'after_ref' => '2.6.14', 't0' => '2024-11-03T15:02:01+00:00', 'dev' => false, 'targets' => ['8.3']],
    ];
    public const TARGET_PHP = '8.4';
    private const PROJECT = 'wallabag/wallabag';
    private const BASELINE = 'lockrot-baseline.json';
    private const SERVER = 'http://127.0.0.1';
    private const GATES = 'left-behind,vulnerable-high';
    private const JSON = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    private string $name;
    /** @var array{before: string, after: string, before_ref: string, before_kind: string, after_kind: string, after_ref?: string, t0: string, dev: bool, targets: list<string>} */
    private array $scenario;
    private string $t1;
    /** @var array<string, string> each written file by its path below the scenario directory */
    private array $files = [];
    /** @var list<array{step: string, argv: list<string>, exit: int}> */
    private array $runs = [];

    private function __construct(string $name, string $t1)
    {
        if (!isset(self::SCENARIOS[$name])) {
            throw new \InvalidArgumentException('no baselined scenario '.$name);
        }
        $this->name = $name;
        $this->scenario = self::SCENARIOS[$name];
        $this->t1 = $t1;
    }

    /**
     * Every file of the scenario, `provenance.json` included, by its path below
     * tests/fixtures/baselined/<scenario>/.
     *
     * @return array<string, string>
     */
    public static function record(string $name, string $t1): array
    {
        $scenario = new self($name, $t1);
        $server = $scenario->server();
        $server->start();
        $github = $scenario->githubAnswers();
        try {
            return $scenario->steps($server, $github);
        } finally {
            $server->stop();
            array_map('unlink', glob($github.'/*') ?: []);
            rmdir($github);
        }
    }

    /** @return array<string, string> */
    private function steps(FixtureRepositoryServer $server, string $github): array
    {
        $options = array_merge(['--target-php='.self::TARGET_PHP], $this->scenario['dev'] ? ['--dev'] : []);
        $before = $this->analyse($server, $github, (string) $this->scenario['before'], (string) $this->scenario['t0'], self::TARGET_PHP);
        $t0 = $before->report()->withRun($this->settings(Gate::MODE_GENERATE_BASELINE, 'high', self::TARGET_PHP));
        $this->put('t0/report.json', Formatters::for('json')->format($t0));
        $this->runs[] = ['step' => 'generate', 'argv' => array_merge(['--generate-baseline', '--output=json:t0/report.json'], $options), 'exit' => 0];
        [$baseline, $asOf] = $this->asOf(Baseline::fromReport($t0), $t0);
        $this->putJson(self::BASELINE, ['$schema' => 'https://lockrot.dev/schema/baseline-2.json'] + $baseline->toArray());

        $after = $this->analyse($server, $github, (string) $this->scenario['after'], $this->t1, self::TARGET_PHP);
        $check = $this->checked($after->report(), $baseline, 'high', self::TARGET_PHP);
        $this->putFormats('check/report', $check, ['json', 'table', 'markdown', 'github', 'gitlab', 'sarif']);
        $this->putJson('check/report.bundle.json', (new ReportDocument($check, new PageData($after, new Thresholds(), self::TARGET_PHP, $this->projectPhp(), [2])))->toArray());
        $this->runs[] = ['step' => 'check', 'argv' => array_merge(['--fail-on=high', '--format=json'], $options), 'exit' => self::exit($check)];
        $explained = $this->explain($after, $check);
        $gates = $this->checked($after->report(), $baseline, self::GATES, self::TARGET_PHP);
        $this->putFormats('check/gates-left-behind-vulnerable-high', $gates, ['json', 'sarif']);
        $this->runs[] = ['step' => 'check', 'argv' => array_merge(['--fail-on='.self::GATES, '--format=json'], $options), 'exit' => self::exit($gates)];
        foreach ($this->scenario['targets'] as $target) {
            $other = $this->analyse($server, $github, (string) $this->scenario['after'], $this->t1, (string) $target);
            $this->putFormats('check/target-'.$target, $this->checked($other->report(), $baseline, 'high', (string) $target), ['json']);
            $this->runs[] = ['step' => 'check', 'argv' => ['--fail-on=high', '--format=json', '--target-php='.$target], 'exit' => self::exit($this->checked($other->report(), $baseline, 'high', (string) $target))];
        }

        $regenerated = Baseline::fromReport($after->report()->withRun($this->settings(Gate::MODE_GENERATE_BASELINE, 'high', self::TARGET_PHP)), $baseline);
        $this->putJson('regenerate/'.self::BASELINE, ['$schema' => 'https://lockrot.dev/schema/baseline-2.json'] + $regenerated->toArray());
        $this->runs[] = ['step' => 'regenerate', 'argv' => array_merge(['--generate-baseline'], $options), 'exit' => 0];
        $recheck = $this->checked($after->report(), $regenerated, 'high', self::TARGET_PHP);
        $this->putFormats('regenerate/report', $recheck, ['json']);
        $this->runs[] = ['step' => 'recheck', 'argv' => array_merge(['--fail-on=high', '--format=json'], $options), 'exit' => self::exit($recheck)];

        $this->putJson('provenance.json', $this->provenance($asOf, $explained, $t0, $after->report()));
        ksort($this->files);

        return $this->files;
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

        return [Baseline::fromArray(ContractBundle::stringKeys(['$schema' => 'https://lockrot.dev/schema/baseline-2.json'] + $data)), ['removed' => $removed, 'entries_removed' => $entriesRemoved]];
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
            $this->put('check/explain/'.str_replace('/', '--', $package).'.json', (new ExplainFormatter())->json(new Explanation($finding, $facts, new Thresholds(), self::TARGET_PHP, $check, $this->projectPhp())));
        }

        return $packages;
    }

    private function checked(Report $report, Baseline $baseline, string $failOn, string $target): Report
    {
        $names = array_map(static fn ($finding): string => $finding->package(), $report->findings());
        $withRun = $report->withRun($this->settings(Gate::MODE_CHECK, $failOn, $target));

        return $withRun->withBaseline(BaselineComparison::compare($baseline, $withRun, self::BASELINE, $names))->withRun($this->settings(Gate::MODE_CHECK, $failOn, $target));
    }

    private function settings(string $mode, string $failOn, string $target): RunSettings
    {
        return new RunSettings(self::PROJECT, self::PROJECT, $target, RunSettings::SOURCE_OPTION, 'composer.lock', FailOn::parse($failOn), RunSettings::SOURCE_OPTION, new Thresholds(), $this->projectPhp(), false, $mode, (bool) $this->scenario['dev']);
    }

    private function analyse(FixtureRepositoryServer $server, string $github, string $dir, string $now, string $target): Analysis
    {
        $clock = Clock::fixed($now);
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $project = ProjectConfig::fromFile(self::FIXTURES.$dir.'/composer.json');
        $analyzer = new Analyzer(
            new RepositoryMetadataLoader($server->repositories(), $clock),
            new ActivityClient(new RecordedHttpClient($github), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), $target, PhpReleaseDates::load(), $project->requirePhp()),
            $clock,
            false,
            new RepositoryAdvisoryLoader($server->repositories())
        );
        $lock = LockFile::fromFile(self::FIXTURES.$dir.'/composer.lock');
        $dev = (bool) $this->scenario['dev'];

        return $analyzer->analyzeWithFacts($lock->packages($dev), $lock, $project, $dev);
    }

    private function server(): FixtureRepositoryServer
    {
        $http = self::FIXTURES.'baselined/'.$this->name.'/http/';
        $locks = [self::FIXTURES.$this->scenario['before'].'/composer.lock', self::FIXTURES.$this->scenario['after'].'/composer.lock'];
        $server = FixtureRepositoryServer::fromLockFiles($locks, self::FIXTURES.'http/p2', [], [['https://repo.packagist.org/p2/', $http.'p2']]);
        $advisories = [];
        foreach ([$this->scenario['before'], $this->scenario['after']] as $side) {
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

    private function projectPhp(): ?string
    {
        return ProjectConfig::fromFile(self::FIXTURES.$this->scenario['after'].'/composer.json')->requirePhp();
    }

    /** @param list<string> $formats */
    private function putFormats(string $stem, Report $report, array $formats): void
    {
        $suffix = ['json' => '.json', 'table' => '.table.txt', 'markdown' => '.md', 'github' => '.github.txt', 'gitlab' => '.gitlab.json', 'sarif' => '.sarif.json'];
        $run = $report->run();
        $context = FormatContext::forFailOn('composer.lock', $run === null || $run->failOn() === null ? FailOn::none() : $run->failOn());
        foreach ($formats as $format) {
            $this->put($stem.$suffix[$format], Formatters::for($format, $context)->format($report));
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
        $this->files[$path] = (string) preg_replace('{'.preg_quote(self::SERVER, '}').':\d+}', self::SERVER, VersionMask::apply($contents));
    }

    private static function exit(Report $report): int
    {
        $gate = $report->gate();

        return $gate !== null && $gate->fails() ? 1 : 0;
    }

    /**
     * @param array{removed: list<array{package: string, id: string, reported_at: string}>, entries_removed: list<string>} $asOf
     * @param list<string>                                                                                                $explained
     *
     * @return array<string, mixed>
     */
    private function provenance(array $asOf, array $explained, Report $t0, Report $after): array
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
            'clocks' => ['t0' => $this->scenario['t0'], 't1' => $this->t1],
            'options' => array_merge(['--target-php='.self::TARGET_PHP], $this->scenario['dev'] ? ['--dev'] : []),
            'runs' => $this->runs,
            'as_of' => $asOf + ['later_than_t0' => self::laterThan($t0, (string) $this->scenario['t0'])],
            'explain' => $explained,
            'version_mask' => ['paths' => VersionMask::PATHS, 'value' => VersionMask::VALUE],
            'server_mask' => self::SERVER.':<port> reads '.self::SERVER,
            'files' => $files,
        ];
    }

    /**
     * R5: every date that the T0 run read and that is later than T0. Δs is clamped to 0, so no years
     * value can show it.
     *
     * @return list<array{package: string, reading: string, at: string}>
     */
    private static function laterThan(Report $report, string $clock): array
    {
        $limit = new \DateTimeImmutable($clock);
        $later = [];
        foreach (JsonPath::arrayAt(JsonPath::decoded($report->findingRows()), []) as $package => $finding) {
            foreach (\is_array($finding) && \is_array($finding['age'] ?? null) ? $finding['age'] : [] as $reading => $value) {
                if (\is_array($value) && \is_string($value['at'] ?? null) && new \DateTimeImmutable($value['at']) > $limit) {
                    $later[] = ['package' => (string) $package, 'reading' => 'age.'.$reading, 'at' => $value['at']];
                }
            }
        }

        return $later;
    }
}
