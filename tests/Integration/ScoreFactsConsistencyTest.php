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
use Lockrot\Tests\Support\JsonPath;
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
                    $broken[] = $run.' '.JsonPath::stringAt($finding, ['package']).': '.$violation;
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
     * @param array<mixed, mixed>                    $f
     * @param array<string, array<int|string, true>> $seen
     *
     * @return list<string>
     */
    private static function violations(array $f, array &$seen): array
    {
        $out = [];
        $check = static function (bool $holds, string $what) use (&$out): void {
            if (!$holds) {
                $out[] = $what;
            }
        };
        $verdict = JsonPath::stringAt($f, ['verdict']);
        $score = JsonPath::arrayAt($f, ['score']);
        $graded = \in_array($verdict, ScoreModel::GRADES, true);
        $total = JsonPath::intAt($score, ['total']);
        $check(JsonPath::stringAt($f, ['priority']) === ($graded ? $verdict : 'none'), 'priority is the grade, else none');
        $check($graded === JsonPath::has($score, ['terms']), 'a graded score has terms, a score-0 one has none');
        $check($graded === ($total >= 1), 'a graded score totals 1 or more');
        $rows = [];
        foreach (self::rows($f, ['signals']) as $signal) {
            if (JsonPath::stringAt($signal, ['id']) === 'S9') {
                $rows = self::rows($signal, ['data', 'advisories']);
            }
        }
        $severityPoints = [];
        foreach (self::rows(ScoreModel::toArray(), ['severities']) as $severity) {
            $severityPoints[JsonPath::stringAt($severity, ['id'])] = JsonPath::intAt($severity, ['points']);
        }
        $deciding = [];
        foreach ($rows as $row) {
            $severity = JsonPath::stringAt($row, ['severity']);
            $kind = JsonPath::stringAt($row, ['fix', 'kind']);
            $seen['severities'][$severity] = true;
            $seen['fix kinds'][$kind] = true;
            $doubles = \in_array($kind, ScoreModel::DOUBLING, true);
            $check(JsonPath::intAt($row, ['points']) === $severityPoints[$severity] * ($doubles ? 2 : 1), 'S9 row '.JsonPath::stringAt($row, ['id']).' points');
            if (JsonPath::boolAt($row, ['deciding'])) {
                $deciding[] = JsonPath::stringAt($row, ['id']);
            }
        }
        $check(\count($deciding) === ($rows === [] ? 0 : 1), 'one deciding S9 row');
        if (!$graded) {
            return $out;
        }

        $exact = self::number($score, ['exact']);
        $check($verdict === self::band($total), 'the grade is the band of the total');
        $check($total === (int) floor($exact), 'the total is the exact score rounded down');
        $check(JsonPath::boolAt($score, ['rounded_down']) === ($exact !== (float) $total), 'rounded_down says whether the exact score was whole');
        $terms = self::rows($score, ['terms']);
        $maintenance = array_values(array_filter($terms, static fn (array $term): bool => JsonPath::stringAt($term, ['part']) === 'maintenance'));
        $security = array_values(array_filter($terms, static fn (array $term): bool => JsonPath::stringAt($term, ['part']) === 'security'));
        foreach ($maintenance as $i => $term) {
            $flag = JsonPath::stringAt($term, ['flag']);
            $divisor = JsonPath::intAt($term, ['divisor']);
            $check(JsonPath::stringAt($term, ['role']) === ($i === 0 ? 'lead' : 'corroborating'), $flag.' role');
            $check($divisor === ($i === 0 ? 1 : 4), $flag.' divisor');
            $check(JsonPath::intAt($term, ['weight']) === ScoreModel::POINTS[$flag], $flag.' weight');
            $check(JsonPath::intAt($term, ['points']) === intdiv(JsonPath::intAt($term, ['weight']), $divisor), $flag.' points');
        }
        $lead = $f['lead'] ?? null;
        $check($lead === ($maintenance === [] ? null : JsonPath::stringAt($maintenance[0], ['flag'])), 'the lead is the first maintenance term');
        $check(\count($security) <= 1, 'one security term at most');
        $check((JsonPath::stringAt($f, ['security', 'status']) === 'vulnerable') === ($security !== []), 'a security term exactly when vulnerable');
        foreach ($security as $term) {
            $multiplier = JsonPath::intAt($term, ['multiplier']);
            $weight = JsonPath::intAt($term, ['weight']);
            $seen['multipliers'][$multiplier] = true;
            $check(JsonPath::stringAt($term, ['advisory']) === ($deciding[0] ?? null), 'the security term is the deciding S9 row');
            $check($weight === $severityPoints[JsonPath::stringAt($term, ['severity'])], 'security weight');
            $check($multiplier === (\in_array(JsonPath::stringAt($term, ['fix_kind']), ScoreModel::DOUBLING, true) ? 2 : 1), 'security multiplier');
            $check(JsonPath::intAt($term, ['points']) === $weight * $multiplier, 'security points');
        }
        $roles = [];
        foreach (self::rows($f, ['flags']) as $flag) {
            if (JsonPath::stringAt($flag, ['role']) !== 'accepted') {
                $roles[JsonPath::stringAt($flag, ['id'])] = JsonPath::stringAt($flag, ['role']);
            }
        }
        $termRoles = [];
        foreach ($terms as $term) {
            $termRoles[JsonPath::stringAt($term, ['flag'])] = JsonPath::stringAt($term, ['role']);
        }
        $check($roles === $termRoles, 'the counted flags are the terms, with their roles');
        $reasons = [];
        foreach (self::rows($score, ['modifiers']) as $modifier) {
            $reason = JsonPath::stringAt($modifier, ['reason']);
            $seen['modifiers'][$reason] = true;
            $reasons[] = $reason;
            $check(JsonPath::stringAt($modifier, ['applies_to']) === ($reason === 'dev' ? 'total' : 'maintenance'), $reason.' applies to');
            $check(abs(self::number($modifier, ['after']) - self::number($modifier, ['before']) / JsonPath::intAt($modifier, ['divide_by'])) < 1e-9, $reason.' halves');
        }
        $running = (float) self::points($maintenance);
        foreach (self::rows($score, ['modifiers']) as $modifier) {
            if (JsonPath::stringAt($modifier, ['reason']) === 'dev') {
                $running += self::points($security);
                $check(abs(self::number($modifier, ['before']) - $running) < 1e-9, 'dev halves the total');
            } else {
                $check(abs(self::number($modifier, ['before']) - $running) < 1e-9, 'the reach halves the maintenance points');
            }
            $running = self::number($modifier, ['after']);
        }
        if (!\in_array('dev', array_column(self::rows($score, ['modifiers']), 'reason'), true)) {
            $running += self::points($security);
        }
        $check(abs($exact - $running) < 1e-9, 'the exact score is the terms after the halvings');
        // A halving is listed whenever its fact holds and a term exists, even when it halves 0.
        $reach = JsonPath::stringAt($f, ['reach']);
        $expected = \in_array($reach, ['transitive', 'unreached'], true) ? [$reach] : [];
        $check($reasons === array_merge($expected, JsonPath::boolAt($f, ['dev']) ? ['dev'] : []), 'the halvings follow the reach and dev');

        return $out;
    }

    /**
     * @param array<mixed, mixed> $data
     * @param list<int|string>    $path
     *
     * @return list<array<mixed, mixed>>
     */
    private static function rows(array $data, array $path): array
    {
        $rows = [];
        foreach (JsonPath::arrayAt($data, $path) as $row) {
            self::assertIsArray($row);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<mixed, mixed> $data
     * @param list<int|string>    $path
     */
    private static function number(array $data, array $path): float
    {
        $value = $data;
        foreach ($path as $key) {
            $value = \is_array($value) ? ($value[$key] ?? null) : null;
        }
        if (!\is_int($value) && !\is_float($value)) {
            self::fail(implode('.', $path).' is no number');
        }

        return (float) $value;
    }

    /** @param list<array<mixed, mixed>> $terms */
    private static function points(array $terms): int
    {
        $points = 0;
        foreach ($terms as $term) {
            $points += JsonPath::intAt($term, ['points']);
        }

        return $points;
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

    /** @return list<array<mixed, mixed>> the findings of the report-2 document */
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
        $findings = self::rows($document, ['findings']);
        self::assertNotSame([], $findings, $dir);

        return $findings;
    }
}
