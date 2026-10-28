<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunNote;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineEntry;
use Lockrot\Clock;
use Lockrot\Config\Gate;
use Lockrot\Data\Advisory\AdvisoryLoaderInterface;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Json\Schemas;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\FormatContext;
use Lockrot\Output\GithubFormatter;
use Lockrot\Output\GitlabFormatter;
use Lockrot\Output\JsonFormatter;
use Lockrot\Output\SarifFormatter;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\BaselineEntries;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\MemoisingMetadataLoader;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The gate a document writes against everything else that decides the same thing, on every fixture
 * lock: the exit code that `oracleExitCode()` computes, the rules that tie the gate's fields
 * together, and the annotation level that the machine formats print.
 *
 * It covers nothing: Infection skips a mutant whose covering tests together outlast its timeout, and
 * this sweep pushes every line of the gate past it. GateTest and ReportTest kill those mutants.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class GateAgreementTest extends TestCase
{
    use ValidatesJsonSchemas;

    private const FIXTURES = __DIR__.'/../fixtures/';
    private const NOW = '2026-09-14T00:00:00+00:00';

    /** @var array<string, true> every standing and cause the corpus gave, so the check is seen to bite */
    private array $seen = [];

    /** @var list<string> */
    private array $mismatches = [];

    private int $checks = 0;

    /**
     * Every fixture lock served at once, as BranchFloorAgreementTest serves them, in a process of
     * its own: that much metadata fills Composer's static caches, which stay for every later test of
     * a shared process.
     *
     * The reports have no run. So BaselineComparison judges the recorded wallabag baseline against the
     * PHP that runs the test. It compares fixes only on the target PHP that the baseline records.
     * ci.yml runs the group runtime-php on every PHP row, and the rest of covers-nothing on two.
     *
     * @runInSeparateProcess
     *
     * @group runtime-php
     */
    #[RunInSeparateProcess]
    #[Group('runtime-php')]
    public function testEveryDocumentsGateAgreesWithTheExitCodeAndTheAnnotationLevels(): void
    {
        $dirs = ['mini', 'mini-split'];
        foreach (['apps', 'skeletons'] as $group) {
            foreach ((array) glob(self::FIXTURES.$group.'/*/composer.lock') as $lock) {
                $dirs[] = $group.'/'.basename(\dirname((string) $lock));
            }
        }
        sort($dirs);
        self::assertGreaterThanOrEqual(20, \count($dirs), 'the fixture locks');
        $server = FixtureRepositoryServer::fromLockFiles(array_map(static fn (string $dir): string => self::FIXTURES.$dir.'/composer.lock', $dirs));
        $server->start();
        try {
            $loader = new MemoisingMetadataLoader(new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::NOW)));
            foreach ($dirs as $dir) {
                $this->agree($this->analyse($loader, $dir), $dir);
            }
        } finally {
            $server->stop();
        }

        self::assertGreaterThan(0, $this->checks);
        self::assertSame([], \array_slice($this->mismatches, 0, 20), \sprintf('%d of %d checks disagree', \count($this->mismatches), $this->checks));
        foreach ([
            'finding {"reaches_fail_on":true,"fails":true,"exempt_by":null}',
            'finding {"reaches_fail_on":true,"fails":false,"exempt_by":"baseline"}',
            'finding {"reaches_fail_on":true,"fails":false,"exempt_by":null}',
            'finding {"reaches_fail_on":false,"fails":false,"exempt_by":null}',
            'worsened fails',
            'tripped_by []',
            'tripped_by ["strict_network"]',
            'tripped_by ["fail_on"]',
            'tripped_by ["strict_network","fail_on"]',
        ] as $shape) {
            self::assertArrayHasKey($shape, $this->seen, 'the fixtures give '.$shape.', or the agreement proves little');
        }
    }

    /**
     * SPEC §6.2: an `--offline` run at `--fail-on=unchecked` fails on the advisories gap, and each
     * format writes the run-level record at `error` beside the failing findings' records.
     *
     * @runInSeparateProcess
     */
    #[RunInSeparateProcess]
    public function testAnOfflineRunFailsUncheckedWithARunLevelErrorRecord(): void
    {
        $server = FixtureRepositoryServer::fromLockFiles([self::FIXTURES.'mini/composer.lock']);
        $server->start();
        try {
            $loader = new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::NOW));
            $analysed = $this->analyse($loader, 'mini', new RepositoryAdvisoryLoader($server->repositories(), true));
        } finally {
            $server->stop();
        }
        $failOn = FailOn::parse('unchecked');
        $report = $analysed->withRun(new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, $failOn, RunSettings::SOURCE_OPTION, new Thresholds()));
        $this->recorded($report, FormatContext::create(null, 'unchecked'), 'mini offline fail-on=unchecked');

        self::assertSame([], $this->mismatches);
        self::assertNotNull($report->gate());
        self::assertTrue($report->gate()->fails());
        foreach (['github', 'gitlab', 'sarif'] as $format) {
            self::assertArrayHasKey($format.' run-level error', $this->seen);
        }
    }

    /**
     * I15b under a baseline: an entry exempts only a graded finding (docs/baseline.md), so an
     * ungraded finding with S10 still fails `--fail-on=unchecked`, and the run-level record stays
     * at `error`.
     */
    public function testAnOfflineRunThatTheBaselineKnowsFailsUncheckedOnlyOnItsUngradedFindings(): void
    {
        $server = FixtureRepositoryServer::fromLockFiles([self::FIXTURES.'mini/composer.lock']);
        $server->start();
        try {
            $loader = new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::NOW));
            $analysed = $this->analyse($loader, 'mini', new RepositoryAdvisoryLoader($server->repositories(), true));
        } finally {
            $server->stop();
        }
        $report = $analysed->withRun(new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::parse('unchecked'), RunSettings::SOURCE_OPTION, new Thresholds()));
        $generated = Baseline::fromReport($report);
        $entries = array_map(static fn (Finding $f): BaselineEntry => $generated->entryFor($f->package()) ?? new BaselineEntry($f->package(), $f->version(), '2026-01-01', [], []), $report->findings());
        $names = array_map(static fn (Finding $f): string => $f->package(), $report->findings());
        $known = $report->withBaseline(BaselineComparison::compare(Baseline::of($entries, self::NOW, '8.4', 'option'), $report, 'lockrot-baseline.json', $names));
        $this->recorded($known, FormatContext::create(null, 'unchecked'), 'mini offline fail-on=unchecked, every finding known');

        $ungradedUnchecked = array_values(array_map(static fn (Finding $f): string => $f->package(), array_filter($report->findings(), static fn (Finding $f): bool => !$f->isGraded() && $f->checksMissing() !== [])));
        $gate = $known->gate();
        self::assertSame([], $this->mismatches);
        self::assertNotNull($gate);
        self::assertNotSame([], $ungradedUnchecked, 'the fixture must hold an ungraded finding with S10');
        self::assertSame($ungradedUnchecked, array_values(array_filter($names, static fn (string $name): bool => $gate->packageFails($name))));
        foreach (['github', 'gitlab', 'sarif'] as $format) {
            self::assertArrayHasKey($format.' run-level error', $this->seen);
        }
    }

    private function agree(Report $analysed, string $dir): void
    {
        foreach ([false, true] as $networkFailures) {
            $report = self::withNetworkFailures($analysed, $networkFailures);
            foreach ([null, self::changedBaseline($report), self::realBaseline($dir)] as $i => $baseline) {
                if ($i > 0 && $baseline === null) {
                    continue;
                }
                $compared = $baseline === null ? $report : $report->withBaseline(BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', []));
                foreach (FailOn::allowed() as $value) {
                    $failOn = FailOn::parse($value);
                    $levels = FormatContext::create(null, $value);
                    foreach ([false, true] as $strict) {
                        foreach (Gate::MODES as $mode) {
                            $what = \sprintf('%s fail-on=%s strict=%d network_failures=%d baseline=%d %s', $dir, $value, $strict, $networkFailures, $i, $mode);
                            $withRun = $compared->withRun(new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, $failOn, RunSettings::SOURCE_OPTION, new Thresholds(), null, $strict, $mode));
                            $this->holds($withRun->toArray(), $compared, $levels, $failOn, $strict, $mode, $what);
                            if (!$strict && $mode === Gate::MODE_CHECK) {
                                $this->recorded($withRun, $levels, $what);
                            }
                            // One document per lock and mode through both schemas: the fields' shape
                            // does not depend on the threshold, and validating every document is slow.
                            if ($value === 'medium' && $strict && $networkFailures && ($baseline !== null) === ($mode === Gate::MODE_CHECK)) {
                                $json = (new JsonFormatter())->format($withRun);
                                $this->assertValid(Schemas::REPORT, $json, $what, true);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    private function holds(array $document, Report $report, FormatContext $levels, FailOn $failOn, bool $strict, string $mode, string $what): void
    {
        $run = JsonPath::arrayAt($document, ['run']);
        $this->same($failOn->value(), $run['fail_on'], $what);
        $this->same($failOn->kinds(), JsonPath::column($run, ['gates'], 'kind'), $what);
        $this->same($strict, $run['strict_network'], $what);
        $this->same($mode, $run['mode'], $what);
        $gate = JsonPath::arrayAt($document, ['gate']);
        $this->same(['fails', 'tripped_by', 'fail_on_applied', 'reaching', 'failing', 'exempt', 'standing', 'failing_packages', 'failing_by', 'not_applied', 'text'], array_keys($gate), $what);
        $trippedBy = JsonPath::arrayAt($gate, ['tripped_by']);
        $applied = $gate['fail_on_applied'];
        $this->same($mode === Gate::MODE_CHECK, $applied, $what);

        $anyFails = false;
        $byPackage = [];
        foreach ($report->findings() as $finding) {
            $byPackage[$finding->package()] = $finding;
        }
        $levelGate = Gate::decide($report, $failOn, $strict, $mode);
        foreach (JsonPath::arrayAt($document, ['findings']) as $row) {
            if (!\is_array($row)) {
                $this->same('an object', $row, $what.': a finding');

                continue;
            }
            $finding = $byPackage[JsonPath::stringAt($row, ['package'])];
            $where = $what.' '.$finding->package();
            $standing = JsonPath::arrayAt($row, ['gate']);
            $this->seen['finding '.json_encode(\array_slice($standing, 0, 3, true))] = true;
            ['reaches_fail_on' => $reaches, 'fails' => $fails, 'exempt_by' => $exemptBy] = $standing;
            $values = JsonPath::column($document, ['run', 'gates'], 'value');
            $this->same($fails, $standing['by'] !== [], $where.': a finding fails exactly when it names a value');
            foreach (JsonPath::arrayAt($row, ['gate', 'by']) as $by) {
                $this->same(true, \is_array($by) && \in_array($by['value'], $values, true) && $by['facts'] !== [], $where.': each failing value is active and names a fact (I15b)');
            }
            $this->same(false, $fails && !$applied, $where.': a generate run fails nothing');
            if ($exemptBy !== null) {
                $this->same([true, false, true], [$reaches, $fails, $standing['basis'] !== null], $where.': only a reaching finding under an entry is exempt');
            }
            $status = $report->baseline() === null ? null : $report->baseline()->statusOf($finding->package());
            if ($status === 'worsened' && $fails) {
                $this->seen['worsened fails'] = true;
            }
            $levelStanding = $levelGate->standingOf($finding->package());
            $this->same($fails, $levelStanding !== null && $levelStanding->fails() && $levelStanding->by() !== [], $where.': a failing finding names the values that mark its records error');
            $anyFails = $anyFails || $fails;
        }

        $this->same($gate['fails'], $trippedBy !== [], $what);
        $this->same($anyFails, \in_array(Gate::TRIP_FAIL_ON, $trippedBy, true), $what);
        $this->same($strict && $report->hadNetworkFailures(), \in_array(Gate::TRIP_STRICT_NETWORK, $trippedBy, true), $what);
        $this->same(array_values(array_filter(Gate::TRIPS, static fn (string $trip): bool => \in_array($trip, $trippedBy, true))), $trippedBy, $what.': unique, in order');
        $this->seen['tripped_by '.json_encode($trippedBy)] = true;

        $this->same(($strict && $report->hadNetworkFailures()) || $anyFails ? 1 : 0, $gate['fails'] ? 1 : 0, $what.': the exit code is a failing finding or a failed lookup under --strict-network');
    }

    /**
     * I15b in the records that github, gitlab and sarif write: a finding has an `error` record
     * exactly when it fails the gate, and a run-level `error` record only beside a failing gate.
     * `--strict-network` is excluded: a failed lookup fails the run with no finding to record.
     */
    private function recorded(Report $report, FormatContext $context, string $what): void
    {
        $gate = $report->gate();
        self::assertNotNull($gate);
        $failing = [];
        foreach ($report->findings() as $finding) {
            $standing = $gate->standingOf($finding->package());
            if ($standing !== null && $standing->fails()) {
                $failing[] = $finding->package();
            }
        }
        sort($failing);
        foreach (self::errorRecords($report, $context) as $format => [$packages, $runLevel]) {
            sort($packages);
            $this->same($failing, array_values(array_unique($packages)), $what.' '.$format.': the error records name the failing findings');
            $this->same(false, $runLevel && !$gate->fails(), $what.' '.$format.': a run-level error record fails the gate');
            $this->seen[$format.' run-level '.($runLevel ? 'error' : 'none or warning')] = true;
        }
    }

    /**
     * The packages that each format gives an `error` record, and whether its run-level record is an error.
     *
     * @return array<string, array{list<string>, bool}>
     */
    private static function errorRecords(Report $report, FormatContext $context): array
    {
        $github = [[], false];
        foreach (explode("\n", (new GithubFormatter($context))->format($report)) as $line) {
            if (strncmp($line, '::error ', 8) !== 0) {
                continue;
            }
            $message = (string) substr($line, (int) strpos($line, '::', 2) + 2);
            if (strncmp($message, 'advisories not checked:', 23) === 0) {
                $github[1] = true;
            } else {
                $github[0][] = explode(' ', $message)[0];
            }
        }
        $gitlab = [[], false];
        foreach (JsonPath::decoded(json_decode((new GitlabFormatter($context))->format($report), true)) as $issue) {
            if (!\is_array($issue) || $issue['severity'] !== 'major') {
                continue;
            }
            if ($issue['check_name'] === 'lockrot/advisories-unchecked') {
                $gitlab[1] = true;
            } else {
                $gitlab[0][] = explode(' ', JsonPath::stringAt($issue, ['description']))[0];
            }
        }
        $sarif = [[], false];
        $document = json_decode((new SarifFormatter($context))->format($report), true);
        self::assertIsArray($document);
        foreach (JsonPath::arrayAt($document, ['runs', 0, 'results']) as $result) {
            if (!\is_array($result) || $result['level'] !== FormatContext::LEVEL_ERROR) {
                continue;
            }
            if ($result['ruleId'] === 'lockrot/advisories-unchecked') {
                $sarif[1] = true;
            } else {
                $sarif[0][] = JsonPath::stringAt($result, ['properties', 'package']);
            }
        }

        return ['github' => $github, 'gitlab' => $gitlab, 'sarif' => $sarif];
    }

    /**
     * One comparison of the sweep, kept out of PHPUnit's count: PHPUnit 10 (PHP 8.1) records an event
     * per assertion and ships them all back from the child process, which millions of them crash.
     *
     * @param mixed $expected
     * @param mixed $actual
     */
    private function same($expected, $actual, string $what): void
    {
        ++$this->checks;
        if ($expected !== $actual) {
            $this->mismatches[] = $what.': expected '.json_encode($expected).', got '.json_encode($actual);
        }
    }

    /**
     * The baseline that this report writes, with the first graded finding whose lead is not `stale`
     * recorded at `stale` (so it is worsened) and the second of the other graded ones omitted (so it
     * is new). Every other one is known. Null when no finding is graded.
     */
    private static function changedBaseline(Report $report): ?Baseline
    {
        $written = Baseline::fromReport($report);
        $entries = [];
        $worsened = false;
        $skipped = 0;
        foreach ($report->graded() as $finding) {
            $entry = $written->entryFor($finding->package());
            self::assertNotNull($entry, $finding->package());
            if (!$worsened && $finding->lead() !== null && $finding->lead() !== Verdict::STALE) {
                $entries[] = BaselineEntries::of($entry->package(), $entry->version(), Verdict::STALE, $entry->firstSeen());
                $worsened = true;
            } elseif ($skipped++ === 1) {
                continue;
            } else {
                $entries[] = $entry;
            }
        }

        return $report->graded() === [] ? null : BaselineEntries::baseline($entries, self::NOW);
    }

    /**
     * The baseline-2 file that wallabag's lock of 2024-05-17 writes on its own day
     * (BaselinedProjectTest pins it), read against the fixture's later lock. Null for another lock.
     */
    private static function realBaseline(string $dir): ?Baseline
    {
        if ($dir !== 'apps/wallabag_wallabag') {
            return null;
        }
        /** @var array<string, mixed> $data */
        $data = JsonPath::decodeFile(self::FIXTURES.'baselined/wallabag-main/lockrot-baseline.json');

        return Baseline::fromArray($data);
    }

    /** The report with a failed lookup among its notes, or with none: the notes are what the flag is computed from. */
    private static function withNetworkFailures(Report $report, bool $failures): Report
    {
        $notes = array_values(array_filter($report->runNotes(), static fn (RunNote $note): bool => !$note->setsNetworkFailures()));
        if ($failures) {
            $notes[] = RunNote::metadataUnavailable(['vendor/unreachable' => 'HTTP 503']);
        }

        return new Report($report->findings(), $notes, $report->generatedAt(), $report->packagesChecked(), $report->notFromComposerRepository(), null, $report->activityCacheOldestAt(), $report->includesDev());
    }

    private function analyse(MetadataLoaderInterface $loader, string $dir, ?AdvisoryLoaderInterface $advisories = null): Report
    {
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $analyzer = new Analyzer(
            $loader,
            new ActivityClient(new RecordedHttpClient(self::FIXTURES.'http/github'), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            $clock,
            true,
            $advisories
        );
        $lock = LockFile::fromFile(self::FIXTURES.$dir.'/composer.lock');

        return $analyzer->analyze($lock, ProjectConfig::fromFile(self::FIXTURES.$dir.'/composer.json'), false);
    }
}
