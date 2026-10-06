<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analysis;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\AgeMeasure;
use Lockrot\Signal\AgeReading;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Rule\LeftBehindRule;
use Lockrot\Signal\Rule\NoPushRule;
use Lockrot\Signal\Rule\NoReleaseRule;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Unit\Signal\FactsBuilder;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\TestCase;

/**
 * A signal quotes the reading it judged (issue #39.3, SPEC §2.1): S2, S4 and S8 take their date,
 * version, years, `dated_by` and level from {@see AgeMeasure}, so S2 fires exactly when the release
 * reading has a level, S4 exactly when the push reading has one, and S8 only when the branch
 * reading has one. Checked over a sweep of dates around every threshold and over every fixture app.
 */
final class AgeSignalParityTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/';
    private const NOW = '2026-10-01T00:00:00+00:00';
    private const TARGET_PHP = '8.4';

    /** @return list<Thresholds> */
    private static function thresholdSets(): array
    {
        return [new Thresholds(), new Thresholds(1, 2, 1, 2), new Thresholds(2, 7, 4, 6)];
    }

    /**
     * Seconds before the run clock: one second, one day and the half-tenth either side of every
     * whole year up to eight, so each threshold, and each place the tenths round, is crossed.
     *
     * @return list<int>
     */
    private static function sweep(): array
    {
        $out = [-86400, 0, 1];
        $half = intdiv(Clock::SECONDS_PER_YEAR, 20);
        for ($year = 1; $year <= 8; ++$year) {
            $at = $year * Clock::SECONDS_PER_YEAR;
            foreach ([-86400, -$half, -1, 0, 1, $half - 1, $half, 86400] as $delta) {
                $out[] = $at + $delta;
            }
        }

        return $out;
    }

    private static function ago(int $seconds): string
    {
        return (new \DateTimeImmutable(self::NOW))->modify(\sprintf('%+d seconds', -$seconds))->format(\DATE_ATOM);
    }

    public function testOverASweepOfDatesEverySignalEqualsItsReading(): void
    {
        $clock = Clock::fixed(self::NOW);
        $checked = 0;
        foreach (self::thresholdSets() as $thresholds) {
            $measure = new AgeMeasure($clock, $thresholds);
            $rules = [new NoReleaseRule($clock, $thresholds), new NoPushRule($clock, $thresholds), new LeftBehindRule($clock, $thresholds, new PhpFloor(self::TARGET_PHP))];
            foreach (self::sweep() as $seconds) {
                $at = self::ago($seconds);
                // A living higher branch, so S8 fires whenever the installed branch's reading has a level.
                $facts = FactsBuilder::facts(
                    FactsBuilder::package(['version' => '1.2.0']),
                    FactsBuilder::metadata([['2.0.0', self::ago(86400)], ['1.2.0', $at]]),
                    FactsBuilder::activity(false, $at)
                );
                $withoutHigherBranch = FactsBuilder::facts(FactsBuilder::package(['version' => '1.2.0']), FactsBuilder::metadata([['1.2.0', $at]]));
                foreach ([$facts, $withoutHigherBranch] as $case) {
                    $signals = [];
                    foreach ($rules as $rule) {
                        $signal = $rule->evaluate($case);
                        if ($signal !== null) {
                            $signals[$signal->id()] = $signal;
                        }
                    }
                    $checked += self::assertParity($measure, $case, $signals, \sprintf('%d s ago, thresholds %d/%d/%d/%d', $seconds, $thresholds->releaseWarnYears(), $thresholds->releaseHighYears(), $thresholds->pushWarnYears(), $thresholds->pushHighYears()));
                }
                // S8 fires on every swept date past warn, the whole point of the higher branch.
                $branch = $measure->branchRelease($facts);
                self::assertSame($branch->level() !== null, $rules[2]->evaluate($facts) !== null, $at);
            }
        }
        self::assertGreaterThan(400, $checked);
    }

    public function testOverEveryFixtureAppEverySignalEqualsItsReading(): void
    {
        $dirs = glob(self::FIXTURES.'apps/*', \GLOB_ONLYDIR);
        self::assertIsArray($dirs);
        self::assertCount(12, $dirs);
        $locks = array_map(static fn (string $dir): string => $dir.'/composer.lock', $dirs);
        $server = FixtureRepositoryServer::fromLockFiles($locks);
        $server->start();
        $clock = Clock::fixed(self::NOW);
        $thresholds = new Thresholds();
        $measure = new AgeMeasure($clock, $thresholds);
        $fired = [Signal::S2 => 0, Signal::S4 => 0, Signal::S8 => 0];
        try {
            foreach ($dirs as $dir) {
                $project = ProjectConfig::fromFile($dir.'/composer.json');
                $analysis = self::analyze($server, $clock, $thresholds, $dir, $project);
                $rules = SignalSet::default($clock, $thresholds, self::TARGET_PHP, PhpReleaseDates::load(), $project->requirePhp());
                foreach ($analysis->report()->findings() as $finding) {
                    $facts = $analysis->facts($finding->package());
                    self::assertNotNull($facts);
                    $signals = [];
                    foreach ($rules->evaluate($facts) as $signal) {
                        $signals[$signal->id()] = $signal;
                    }
                    $what = basename($dir).' '.$finding->package();
                    self::assertParity($measure, $facts, $signals, $what);
                    // What the report publishes is the same signal the rules give on the facts.
                    foreach ($finding->signals() as $published) {
                        if (isset($fired[$published->id()])) {
                            ++$fired[$published->id()];
                            self::assertSame($signals[$published->id()]->data(), $published->data(), $what);
                            self::assertSame($signals[$published->id()]->level(), $published->level(), $what);
                        }
                    }
                }
            }
        } finally {
            $server->stop();
        }
        // The clock is pinned and the answers recorded, so the counts are exact: a signal that
        // stops firing on one package shows here, where the parity checks above only see the
        // signals that fired. A re-recorded fixture moves them on purpose.
        self::assertSame([Signal::S2 => 160, Signal::S4 => 42, Signal::S8 => 24], $fired);
    }

    /**
     * @param array<string, Signal> $signals by id, as the rules gave them on $facts
     *
     * @return int the number of readings compared
     */
    private static function assertParity(AgeMeasure $measure, PackageFacts $facts, array $signals, string $what): int
    {
        $release = $measure->release($facts);
        $push = $measure->push($facts);
        $branch = $measure->branchRelease($facts);

        self::assertSame($release->level() !== null, isset($signals[Signal::S2]), $what.': S2 fired ⇔ release.level');
        self::assertSame($push->level() !== null, isset($signals[Signal::S4]), $what.': S4 fired ⇔ push.level');
        if (isset($signals[Signal::S2])) {
            self::assertReading($release, $signals[Signal::S2], ['at' => 'last_release', 'version' => 'last_version', 'dated_by' => 'dated_by'], $what.' S2');
        }
        if (isset($signals[Signal::S4])) {
            self::assertReading($push, $signals[Signal::S4], ['at' => 'last_push'], $what.' S4');
        }
        if (isset($signals[Signal::S8])) {
            self::assertNotNull($branch->level(), $what.': S8 fired ⇒ branch_release.level');
            self::assertReading($branch, $signals[Signal::S8], ['at' => 'branch_last_release', 'version' => 'branch_last_version', 'dated_by' => 'dated_by'], $what.' S8');
        }

        return 3;
    }

    /** @param array<string, string> $keys the reading's part => the signal's data key */
    private static function assertReading(AgeReading $reading, Signal $signal, array $keys, string $what): void
    {
        $data = $signal->data();
        self::assertSame($reading->level(), $signal->level(), $what.': level');
        self::assertSame($reading->years(), $data['years'], $what.': years');
        $at = $reading->at();
        self::assertSame($at === null ? null : $at->format(\DATE_ATOM), $data[$keys['at']], $what.': date');
        if (isset($keys['version'])) {
            self::assertSame($reading->version(), $data[$keys['version']], $what.': version');
        }
        if (isset($keys['dated_by'])) {
            self::assertSame($reading->datedBy(), $data[$keys['dated_by']], $what.': dated_by');
        }
    }

    private static function analyze(FixtureRepositoryServer $server, Clock $clock, Thresholds $thresholds, string $dir, ProjectConfig $project): Analysis
    {
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $analyzer = new Analyzer(
            new RepositoryMetadataLoader($server->repositories(), $clock),
            new ActivityClient(new RecordedHttpClient(self::FIXTURES.'http/github'), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, $thresholds, self::TARGET_PHP, PhpReleaseDates::load(), $project->requirePhp()),
            new VerdictEngine(),
            $clock,
            false
        );
        $lock = LockFile::fromFile($dir.'/composer.lock');

        return $analyzer->analyzeWithFacts($lock->packages(false), $lock, $project, false);
    }
}
