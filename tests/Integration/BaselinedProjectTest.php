<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineFile;
use Lockrot\Clock;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Exception\ConfigException;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\FailOn;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * A real project under a baseline: wallabag's lock of 2024-05-17 writes the baseline that day, and
 * the fixture's later lock is checked against it (`items/C78.md` R7). The advisory lookup is off,
 * so the floors are maintenance ones. The real 0.13 file of that day is the refusal input of Q2.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class BaselinedProjectTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/';
    private const BEFORE = self::FIXTURES.'baselined/wallabag-main/before';
    private const AFTER = self::FIXTURES.'apps/wallabag_wallabag';
    private const BASELINE_1 = self::FIXTURES.'baselined/wallabag-main/lockrot-baseline-1.json';
    /** The baseline-2 file that the earlier lock writes on its own day. */
    private const BASELINE_2 = self::FIXTURES.'baselined/wallabag-main/lockrot-baseline.json';
    private const HTTP = self::FIXTURES.'baselined/wallabag-main/http/';
    private const T0 = '2024-05-17T00:00:00+00:00';
    private const NOW = '2026-09-14T00:00:00+00:00';

    /** SPEC-0.14.md §6.4 (Q2): the 0.13 file that wallabag would have committed is refused before schema validation. */
    public function testTheReal013BaselineIsRefused(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('lockrot 0.14 reads baseline-2 only. Review the findings, then run --generate-baseline.');

        BaselineFile::resolve(\dirname(self::BASELINE_1), basename(self::BASELINE_1), '0.14.0')->read();
    }

    /**
     * The later lock against the baseline that the earlier one wrote: every standing, a worsened
     * flag, a covered one, stale entries, both limits of the gate score, a failing and an exempt
     * finding at --fail-on=high, and a dev finding the baseline knows. It fails, then a
     * regenerated baseline passes.
     *
     * @runInSeparateProcess
     */
    #[RunInSeparateProcess]
    public function testTheLaterLockAgainstTheBaselineOfTheEarlierOne(): void
    {
        // The earlier lock keeps its own answers, so they move no other fixture's results.
        $server = FixtureRepositoryServer::fromLockFiles([self::BEFORE.'/composer.lock', self::AFTER.'/composer.lock'], self::FIXTURES.'http/p2', [], [['https://repo.packagist.org/p2/', self::HTTP.'p2']]);
        $server->start();
        $github = self::githubAnswers();
        try {
            $baseline = Baseline::fromReport(self::analyse($server, $github, self::BEFORE, self::T0)->withRun(self::settings('generate_baseline')));
            $written = (string) json_encode(['$schema' => 'https://lockrot.dev/schema/baseline-2.json'] + $baseline->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n";
            if (getenv('LOCKROT_REWRITE_GOLDEN') === '1') {
                file_put_contents(self::BASELINE_2, $written);
            }
            self::assertSame((string) @file_get_contents(self::BASELINE_2), $written, 'GateAgreementTest reads this file: rewrite it with LOCKROT_REWRITE_GOLDEN=1 vendor/bin/phpunit --filter BaselinedProjectTest');
            $firstSeen = [];
            foreach ($baseline->packages() as $package) {
                $entry = $baseline->entryFor($package);
                $firstSeen[] = $entry === null ? null : $entry->firstSeen();
            }
            self::assertSame(['2024-05-17'], array_values(array_unique($firstSeen)), 'the baseline the project wrote that day');
            $after = self::analyse($server, $github, self::AFTER, self::NOW);
            $checked = self::checked($after, $baseline);
            $regenerated = self::checked($after, Baseline::fromReport($after->withRun(self::settings('generate_baseline')), $baseline));
        } finally {
            $server->stop();
            array_map('unlink', glob($github.'/*') ?: []);
            rmdir($github);
        }
        $document = JsonPath::decoded($checked->toArray());
        $root = JsonPath::arrayAt($document, ['baseline']);
        $statuses = [];
        $flagStates = [];
        $why = [];
        $limitedBy = [];
        $gates = [];
        $devKnown = false;
        foreach (JsonPath::arrayAt($document, ['findings']) as $finding) {
            self::assertIsArray($finding);
            $status = self::text($finding, ['baseline', 'status']);
            $statuses[$status] = true;
            foreach (JsonPath::arrayAt($finding, ['flags']) as $flag) {
                $flagStates[\is_array($flag) ? self::text($flag, ['baseline', 'state']) : ''] = true;
            }
            foreach (JsonPath::has($finding, ['baseline', 'worsened_by']) ? JsonPath::column($finding, ['baseline', 'worsened_by'], 'why') : [] as $reason) {
                $why[\is_string($reason) ? $reason : ''] = true;
            }
            if (JsonPath::has($finding, ['gate', 'basis', 'limited_by'])) {
                $limitedBy[self::text($finding, ['gate', 'basis', 'limited_by'])] = true;
            }
            $gate = JsonPath::arrayAt($finding, ['gate']);
            $gates[(string) json_encode([$gate['reaches_fail_on'] ?? null, $gate['fails'] ?? null, $gate['exempt_by'] ?? null])] = true;
            $devKnown = $devKnown || (JsonPath::boolAt($finding, ['dev']) && $status === 'known');
        }

        self::assertGreaterThan(0, $root['known']);
        self::assertGreaterThan(0, $root['new']);
        self::assertGreaterThan(0, $root['worsened']);
        self::assertNotSame([], $root['stale'], 'packages the later lock dropped');
        self::assertArrayHasKey('covered', $flagStates);
        self::assertArrayHasKey('new', $why, 'a flag worsened by a new fact');
        self::assertSame(['cap', 'score'], self::sortedKeys($limitedBy), 'gate.basis.limited_by takes both values');
        self::assertArrayHasKey('[true,true,null]', $gates, 'a finding reaches high and fails');
        self::assertArrayHasKey('[true,false,"baseline"]', $gates, 'a known finding reaches high and is exempt');
        self::assertTrue($devKnown, 'a dev finding the baseline knows');
        self::assertNotNull($checked->gate());
        self::assertTrue($checked->gate()->fails(), 'exit 1');
        self::assertNotNull($regenerated->gate());
        self::assertFalse($regenerated->gate()->fails(), 'exit 0 after --generate-baseline');
    }

    /** The shared GitHub answers and the earlier lock's own, in one new temporary directory. */
    private static function githubAnswers(): string
    {
        $dir = sys_get_temp_dir().'/lockrot-baselined-github-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($dir));
        foreach ([self::FIXTURES.'http/github', self::HTTP.'github'] as $source) {
            foreach (glob($source.'/*.json') ?: [] as $file) {
                copy($file, $dir.'/'.basename($file));
            }
        }

        return $dir;
    }

    private static function analyse(FixtureRepositoryServer $server, string $github, string $dir, string $now): Report
    {
        $clock = Clock::fixed($now);
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $analyzer = new Analyzer(
            new RepositoryMetadataLoader($server->repositories(), $clock),
            new ActivityClient(new RecordedHttpClient($github), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            $clock,
            false
        );

        return $analyzer->analyze(LockFile::fromFile($dir.'/composer.lock'), ProjectConfig::fromFile($dir.'/composer.json'), true);
    }

    private static function checked(Report $report, Baseline $baseline): Report
    {
        $names = array_map(static fn ($finding): string => $finding->package(), $report->findings());
        $compared = $report->withBaseline(BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', $names));

        return $compared->withRun(self::settings('check'));
    }

    private static function settings(string $mode): RunSettings
    {
        return new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::parse('high'), RunSettings::SOURCE_OPTION, new Thresholds(), null, false, $mode, true);
    }

    /**
     * @param array<mixed, mixed> $data
     * @param list<string>        $path
     */
    private static function text(array $data, array $path): string
    {
        return JsonPath::has($data, $path) ? JsonPath::stringAt($data, $path) : '';
    }

    /**
     * @param array<string, true> $set
     *
     * @return list<string>
     */
    private static function sortedKeys(array $set): array
    {
        $keys = array_map('strval', array_keys($set));
        sort($keys);

        return $keys;
    }
}
