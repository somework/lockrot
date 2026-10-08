<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Repository\AdvisoryProviderInterface;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
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
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Verdict\ScoreModel;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\TestCase;

/**
 * Each finding's score agrees with its flags, its security block and its S9 rows, by the score
 * model's tables: the grade is the band of the total, each term carries its flag's weight and
 * its role's divisor, the security term is the deciding S9 row, and the halvings follow the reach
 * and `dev`. The runs seed advisories so that every severity and every fix kind occurs, and each
 * halving and each advisory multiplier occurs at least once.
 */
final class ScoreFactsConsistencyTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/';
    private const NOW = '2026-09-14T00:00:00+00:00';
    private const WALLABAG = 'apps/wallabag_wallabag';

    /**
     * Advisories on wallabag packages, each with the fix kind the recorded releases give it:
     * update on the installed branch, upgrade to a higher branch, raise-php above the project's
     * `>=8.2` and blocked under target PHP 8.2 (8.x needs PHP 8.4), none (every release affected).
     */
    private const WALLABAG_ADVISORIES = [
        'doctrine/dbal' => ['3.10.5', 'medium'],
        'doctrine/event-manager' => ['<2.0', 'critical'],
        'scheb/2fa-bundle' => ['<8.0.0', 'high'],
        'lcobucci/jwt' => ['>=0.0.1', null],
        'smalot/pdfparser' => ['1.1.0', 'low'],
    ];

    /** A package whose metadata the repository does not serve: its fix is unknown. */
    private const DRUPAL_ADVISORIES = ['composer/installers' => ['<9.0', 'high']];

    /** @var list<FixtureRepositoryServer> */
    private array $servers = [];

    protected function setUp(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer 2.2 has no advisory API, so no run carries S9.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];
    }

    public function testEveryScoreAgreesWithItsFlagsAndItsAdvisories(): void
    {
        $wallabag = $this->server([self::WALLABAG], self::advisories(self::WALLABAG_ADVISORIES), false);
        $others = $this->server(['apps/matomo-org_matomo', 'skeletons/laravel'], [], true);
        $drupal = $this->server(['apps/drupal_drupal'], self::advisories(self::DRUPAL_ADVISORIES), true);
        $runs = [
            'wallabag --dev' => [$wallabag, self::WALLABAG, true, true, '8.4'],
            'wallabag without composer.json, target PHP 8.2' => [$wallabag, self::WALLABAG, false, false, '8.2'],
            'matomo' => [$others, 'apps/matomo-org_matomo', true, false, '8.4'],
            'laravel' => [$others, 'skeletons/laravel', true, false, '8.4'],
            'drupal' => [$drupal, 'apps/drupal_drupal', true, false, '8.4'],
        ];
        $seen = ['severities' => [], 'fix kinds' => [], 'modifiers' => [], 'multipliers' => []];
        $broken = [];
        foreach ($runs as $run => [$server, $dir, $withManifest, $dev, $target]) {
            foreach (self::findings($server, $dir, $withManifest, $dev, $target) as $finding) {
                foreach (self::violations($finding, $seen) as $violation) {
                    $broken[] = $run.' '.$finding['package'].': '.$violation;
                }
            }
        }

        self::assertSame([], $broken);
        self::assertSame(['critical', 'high', 'low', 'medium', 'unrated'], self::sortedKeys($seen['severities']));
        self::assertSame(['blocked', 'none', 'raise-php', 'unknown', 'update', 'upgrade'], self::sortedKeys($seen['fix kinds']));
        self::assertSame(['dev', 'transitive', 'unreached'], self::sortedKeys($seen['modifiers']));
        self::assertSame([1, 2], self::sortedKeys($seen['multipliers']));
    }

    /**
     * @param array<string, mixed>                   $f
     * @param array<string, array<int|string, true>> $seen
     *
     * @return list<string>
     */
    private static function violations(array $f, array &$seen): array
    {
        $out = [];
        $score = $f['score'];
        $terms = $score['terms'] ?? null;
        $graded = \in_array($f['verdict'], ScoreModel::GRADES, true);
        $check = static function (bool $holds, string $what) use (&$out): void {
            if (!$holds) {
                $out[] = $what;
            }
        };
        $check($f['priority'] === ($graded ? $f['verdict'] : 'none'), 'priority is the grade, else none');
        $check($graded === \is_array($terms), 'a graded score has terms, a score-0 one has none');
        $check($graded === ($score['total'] >= 1), 'a graded score totals 1 or more');
        $rows = [];
        foreach ($f['signals'] as $signal) {
            if ($signal['id'] === 'S9') {
                $rows = $signal['data']['advisories'];
            }
        }
        $severityPoints = array_column(ScoreModel::toArray()['severities'], 'points', 'id');
        foreach ($rows as $row) {
            $seen['severities'][$row['severity']] = true;
            $seen['fix kinds'][$row['fix']['kind']] = true;
            $doubles = \in_array($row['fix']['kind'], ScoreModel::DOUBLING, true);
            $check($row['points'] === $severityPoints[$row['severity']] * ($doubles ? 2 : 1), 'S9 row '.$row['id'].' points');
        }
        $deciding = array_values(array_filter($rows, static fn (array $row): bool => $row['deciding'] === true));
        $check(\count($deciding) === ($rows === [] ? 0 : 1), 'one deciding S9 row');
        if (!$graded) {
            return $out;
        }

        $check($f['verdict'] === self::band($score['total']), 'the grade is the band of the total');
        $check($score['total'] === (int) floor($score['exact']), 'the total is the exact score rounded down');
        $check($score['rounded_down'] === ($score['exact'] != $score['total']), 'rounded_down says whether the exact score was whole');
        $maintenance = array_values(array_filter($terms, static fn (array $term): bool => $term['part'] === 'maintenance'));
        $security = array_values(array_filter($terms, static fn (array $term): bool => $term['part'] === 'security'));
        foreach ($maintenance as $i => $term) {
            $check($term['role'] === ($i === 0 ? 'lead' : 'corroborating'), $term['flag'].' role');
            $check($term['divisor'] === ($i === 0 ? 1 : 4), $term['flag'].' divisor');
            $check($term['weight'] === ScoreModel::POINTS[$term['flag']], $term['flag'].' weight');
            $check($term['points'] === intdiv($term['weight'], $term['divisor']), $term['flag'].' points');
        }
        $check($f['lead'] === ($maintenance[0]['flag'] ?? null), 'the lead is the first maintenance term');
        $check(\count($security) <= 1, 'one security term at most');
        $check(($f['security']['status'] === 'vulnerable') === ($security !== []), 'a security term exactly when vulnerable');
        foreach ($security as $term) {
            $seen['multipliers'][$term['multiplier']] = true;
            $check($term['advisory'] === ($deciding[0]['id'] ?? null), 'the security term is the deciding S9 row');
            $check($term['weight'] === $severityPoints[$term['severity']], 'security weight');
            $check($term['multiplier'] === (\in_array($term['fix_kind'], ScoreModel::DOUBLING, true) ? 2 : 1), 'security multiplier');
            $check($term['points'] === $term['weight'] * $term['multiplier'], 'security points');
        }
        $roles = [];
        foreach ($f['flags'] as $flag) {
            if ($flag['role'] !== 'accepted') {
                $roles[$flag['id']] = $flag['role'];
            }
        }
        $check($roles === array_column($terms, 'role', 'flag'), 'the counted flags are the terms, with their roles');
        $reasons = [];
        foreach ($score['modifiers'] as $modifier) {
            $seen['modifiers'][$modifier['reason']] = true;
            $reasons[] = $modifier['reason'];
            $check($modifier['applies_to'] === ($modifier['reason'] === 'dev' ? 'total' : 'maintenance'), $modifier['reason'].' applies to');
            $check(abs($modifier['after'] - $modifier['before'] / $modifier['divide_by']) < 1e-9, $modifier['reason'].' halves');
        }
        // A halving is listed whenever its fact holds and a term exists, even when it halves 0.
        $reach = \in_array($f['reach'], ['transitive', 'unreached'], true) ? [$f['reach']] : [];
        $check($reasons === array_merge($reach, $f['dev'] ? ['dev'] : []), 'the halvings follow the reach and dev');

        return $out;
    }

    private static function band(int $total): string
    {
        foreach (ScoreModel::BANDS as $grade => $floor) {
            if ($total >= $floor) {
                return $grade;
            }
        }

        return 'none';
    }

    /**
     * @param array<int|string, true> $set
     *
     * @return list<int|string>
     */
    private static function sortedKeys(array $set): array
    {
        $keys = array_keys($set);
        sort($keys);

        return $keys;
    }

    /**
     * @param array<string, array{string, ?string}> $seeds the affected versions and the severity per package
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function advisories(array $seeds): array
    {
        $out = [];
        foreach ($seeds as $package => [$affected, $severity]) {
            $id = 'PKSA-'.substr(sha1($package), 0, 8);
            $out[$package] = [[
                'advisoryId' => $id,
                'packageName' => $package,
                'remoteId' => 'GHSA-'.$id,
                'title' => 'Seeded advisory for '.$package,
                'link' => 'https://example.test/'.$id,
                'cve' => null,
                'affectedVersions' => $affected,
                'sources' => [['name' => 'GitHub', 'remoteId' => 'GHSA-'.$id]],
                'reportedAt' => '2024-03-01 12:00:00',
                'composerRepository' => 'Packagist',
                'severity' => $severity,
            ]];
        }

        return $out;
    }

    /**
     * @param list<string>                              $dirs
     * @param array<string, list<array<string, mixed>>> $advisories
     * @param bool                                      $api        the advisories through the API, else in the p2 files
     */
    private function server(array $dirs, array $advisories, bool $api): FixtureRepositoryServer
    {
        $server = FixtureRepositoryServer::fromLockFiles(array_map(static fn (string $dir): string => self::FIXTURES.$dir.'/composer.lock', $dirs));
        if ($api) {
            $server->withAdvisoryApi($advisories);
        } else {
            $server->withSecurityAdvisories($advisories);
        }
        $server->start();
        $this->servers[] = $server;

        return $server;
    }

    /** @return list<array<string, mixed>> the findings of the report-2 document */
    private static function findings(FixtureRepositoryServer $server, string $dir, bool $withManifest, bool $dev, string $target): array
    {
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $project = $withManifest ? ProjectConfig::fromFile(self::FIXTURES.$dir.'/composer.json') : ProjectConfig::empty();
        $analyzer = new Analyzer(
            new RepositoryMetadataLoader($server->repositories(), $clock),
            new ActivityClient(new RecordedHttpClient(self::FIXTURES.'http/github'), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), $target, PhpReleaseDates::load(), $project->requirePhp()),
            new VerdictEngine(),
            $clock,
            false,
            new RepositoryAdvisoryLoader($server->repositories())
        );
        $report = $analyzer->analyze(LockFile::fromFile(self::FIXTURES.$dir.'/composer.lock'), $project, $dev);
        $document = json_decode((string) json_encode($report->toArray()), true);
        self::assertIsArray($document);
        self::assertNotSame([], $document['findings'], $dir);

        return $document['findings'];
    }
}
