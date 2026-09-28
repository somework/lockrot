<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineEntry;
use Lockrot\Clock;
use Lockrot\Config\Gate;
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
use Lockrot\Output\JsonFormatter;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\MemoisingMetadataLoader;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Verdict;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The gate a document writes against everything else that decides the same thing, on every fixture
 * lock: the exit code the run returned before the gate (Policy's loop and `--generate-baseline`'s
 * expression, frozen here), the rules that tie the gate's fields together, and the annotation level
 * the machine formats print, which reads the same two primitives by a rule of its own. Each lock is
 * read under every fail-on value, with and without --strict-network, with and without a failed
 * lookup, with no baseline and with one taken from the report and then changed so that it holds
 * known, new and worsened findings, in both modes.
 *
 * It covers nothing: Infection skips a mutant whose covering tests together outlast its timeout, and
 * this sweep would push every line of the gate past it. GateTest and ReportTest kill those mutants.
 *
 * @coversNothing
 */
#[CoversNothing]
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
     * its own for the same memory reason.
     *
     * @runInSeparateProcess
     */
    #[RunInSeparateProcess]
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

    private function agree(Report $analysed, string $dir): void
    {
        foreach ([false, true] as $networkFailures) {
            $report = self::withNetworkFailures($analysed, $networkFailures);
            foreach ([null, self::changedBaseline($report)] as $baseline) {
                $compared = $baseline === null ? $report : $report->withBaseline(BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', []));
                foreach (FailOn::allowed() as $value) {
                    $failOn = FailOn::fromString($value);
                    $levels = FormatContext::create(null, $value);
                    foreach ([false, true] as $strict) {
                        foreach (Gate::MODES as $mode) {
                            $what = \sprintf('%s fail-on=%s strict=%d network_failures=%d baseline=%d %s', $dir, $value, $strict, $networkFailures, $baseline !== null, $mode);
                            $withRun = $compared->withRun(new RunSettings(null, null, '8.4', null, $failOn, new Thresholds(), null, $strict, $mode));
                            $this->holds($withRun->toArray(), $compared, $levels, $failOn, $strict, $mode, $what);
                            // One document per lock and mode through both schemas: the fields' shape
                            // does not depend on the threshold, and validating all of them is minutes.
                            if ($value === 'medium' && $strict && $networkFailures && ($baseline !== null) === ($mode === Gate::MODE_CHECK)) {
                                $json = (new JsonFormatter())->format($withRun);
                                $this->assertValid(Schemas::REPORT, $json, $what);
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
        $this->same(FailOn::fromString($failOn->value())->kind(), $run['fail_on_kind'], $what);
        $this->same($strict, $run['strict_network'], $what);
        $this->same($mode, $run['mode'], $what);
        $gate = JsonPath::arrayAt($document, ['gate']);
        $this->same(['fails', 'tripped_by', 'fail_on_applied'], array_keys($gate), $what);
        $trippedBy = JsonPath::arrayAt($gate, ['tripped_by']);
        $applied = $gate['fail_on_applied'];
        $this->same($mode === Gate::MODE_CHECK, $applied, $what);

        $anyFails = false;
        foreach (JsonPath::arrayAt($document, ['findings']) as $at => $row) {
            if (!\is_array($row)) {
                $this->same('an object', $row, $what.': a finding');

                continue;
            }
            $finding = $report->findings()[$at];
            $where = $what.' '.$finding->package();
            $standing = JsonPath::arrayAt($row, ['gate']);
            $this->seen['finding '.json_encode($standing)] = true;
            ['reaches_fail_on' => $reaches, 'fails' => $fails, 'exempt_by' => $exemptBy] = $standing;
            $this->same($failOn->reaches($finding), $reaches, $where);
            $this->same($reaches && $exemptBy === null && $applied, $fails, $where.': fails');
            $status = $row['baseline'] === null ? null : JsonPath::stringAt($row, ['baseline', 'status']);
            $this->same($status === 'known' && $reaches, $exemptBy === Gate::EXEMPT_BASELINE, $where.': exempt by the baseline');
            if ($exemptBy !== null) {
                $this->same(true, $reaches, $where.': only what reaches is exempt');
            }
            if ($status === 'worsened' && $fails) {
                $this->seen['worsened fails'] = true;
            }
            // The annotation level reads the same two primitives by its own rule, the same in both modes.
            $this->same($reaches && $exemptBy !== Gate::EXEMPT_BASELINE, $levels->levelOf($finding, $report->baseline()) === FormatContext::LEVEL_ERROR, $where.': level');
            $anyFails = $anyFails || $fails;
        }

        $this->same($gate['fails'], $trippedBy !== [], $what);
        $this->same($anyFails, \in_array(Gate::TRIP_FAIL_ON, $trippedBy, true), $what);
        $this->same($strict && $report->hadNetworkFailures(), \in_array(Gate::TRIP_STRICT_NETWORK, $trippedBy, true), $what);
        $this->same(array_values(array_filter(Gate::TRIPS, static fn (string $trip): bool => \in_array($trip, $trippedBy, true))), $trippedBy, $what.': unique, in order');
        $this->seen['tripped_by '.json_encode($trippedBy)] = true;

        $oracle = $mode === Gate::MODE_CHECK ? self::oracleExitCode($report, $failOn, $strict) : ($strict && $report->hadNetworkFailures() ? 1 : 0);
        $this->same($oracle, $gate['fails'] ? 1 : 0, $what.': the exit code the run returned before the gate');
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

    /** Policy::exitCode() as it was before the gate, over the same inputs. */
    private static function oracleExitCode(Report $report, FailOn $threshold, bool $strict): int
    {
        if ($strict && $report->hadNetworkFailures()) {
            return 1;
        }
        if ($threshold->isNone()) {
            return 0;
        }
        $baseline = $report->baseline();
        foreach ($report->findings() as $finding) {
            if ($baseline !== null && $baseline->isKnown($finding->package())) {
                continue;
            }
            if ($threshold->reaches($finding)) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * The baseline this report would write, with the first finding more severe than `stale` accepted
     * only at `stale` (so it is worsened) and the second of the other flagged ones left out (so it is
     * new); every other one is known. Null when the lock has nothing flagged.
     */
    private static function changedBaseline(Report $report): ?Baseline
    {
        $written = Baseline::fromReport($report);
        $entries = [];
        $worsened = false;
        $skipped = 0;
        foreach ($report->flagged() as $finding) {
            $entry = $written->entryFor($finding->package());
            self::assertNotNull($entry, $finding->package());
            if (!$worsened && Verdict::severity($finding->verdict()) > Verdict::severity(Verdict::STALE)) {
                $entries[] = new BaselineEntry($entry->package(), $entry->version(), Verdict::STALE, $entry->firstSeen());
                $worsened = true;
            } elseif ($skipped++ === 1) {
                continue;
            } else {
                $entries[] = $entry;
            }
        }

        return $report->flagged() === [] ? null : Baseline::of($entries, self::NOW);
    }

    private static function withNetworkFailures(Report $report, bool $failures): Report
    {
        return new Report($report->findings(), $report->notes(), $report->generatedAt(), $report->packagesChecked(), $report->notFromComposerRepository(), $failures, null, $report->activityCacheOldestAt(), $report->includesDev());
    }

    private function analyse(MetadataLoaderInterface $loader, string $dir): Report
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
            new VerdictEngine(),
            $clock,
            true
        );
        $lock = LockFile::fromFile(self::FIXTURES.$dir.'/composer.lock');

        return $analyzer->analyze($lock, ProjectConfig::fromFile(self::FIXTURES.$dir.'/composer.json'), false);
    }
}
