<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Repository\AdvisoryProviderInterface;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analysis;
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
use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Explain\Explanation;
use Lockrot\Legacy\NoFix013;
use Lockrot\Legacy\Priority013;
use Lockrot\Legacy\PriorityBasis013;
use Lockrot\Legacy\Verdict013;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\ScoreModel;
use Lockrot\Verdict\Verdict;
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
    /** Abandoned in wallabag's lock: the analyses fail its metadata. */
    private const METADATA_FAILS = 'hoa/compiler';

    /**
     * Advisories on wallabag packages, each with the fix kind the recorded releases give it:
     * update on the installed branch, upgrade to a higher branch, raise-php above the project's
     * `>=8.2` and blocked under target PHP 8.2 (8.x needs PHP 8.4), none (every release affected).
     * The metadata of {@see METADATA_FAILS} fails, so its fix is unknown and its releases unread.
     */
    private const WALLABAG_ADVISORIES = [
        self::METADATA_FAILS => ['>=3.0', 'high'],
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
        $legacySeen = [];
        $broken = [];
        foreach ($runs as $run => [$server, $dir, $withManifest, $dev, $target]) {
            $analysis = self::analysis($server, $dir, $withManifest, $dev, $target);
            foreach (self::findings($analysis, $dir) as $finding) {
                $package = JsonPath::stringAt($finding, ['package']);
                foreach (array_merge(self::violations($finding, $seen), self::legacyViolations($analysis, $package, $finding, $target, $legacySeen)) as $violation) {
                    $broken[] = $run.' '.$package.': '.$violation;
                }
            }
        }

        self::assertSame([], $broken);
        foreach (array_merge(PriorityBasis013::STEPS, array_diff(NoFix013::REASONS, [NoFix013::AFFECTED_RANGE_UNKNOWN])) as $reason) {
            self::assertArrayHasKey($reason, $legacySeen, 'the analyses give '.$reason.', or the legacy rules prove little: '.json_encode($legacySeen));
        }
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

    /**
     * The legacy facts that the text formats and explain-2's `legacy` block print, checked against
     * the finding's report-2 fields: the 0.13 priority basis folds to its priority with one step
     * for each fact that holds, and the no-fix list names each unfixed S9 row with its reason.
     *
     * @param array<mixed, mixed> $row the report-2 finding
     * @param array<string, int>  $seen every step and no-fix reason the analyses gave
     *
     * @return list<string>
     */
    private static function legacyViolations(Analysis $analysis, string $package, array $row, string $target, array &$seen): array
    {
        $finding = $analysis->finding($package);
        $facts = $analysis->facts($package);
        self::assertNotNull($finding);
        self::assertNotNull($facts);
        $out = [];
        $check = static function (bool $holds, string $what) use (&$out): void {
            if (!$holds) {
                $out[] = $what;
            }
        };
        $basis = $finding->priorityBasis();
        $at = $basis->base();
        $reasons = [];
        foreach ($basis->steps() as $step) {
            $check($at === $step['from'], 'each legacy step starts where the last ended');
            $at = $step['to'];
            $reasons[] = $step['reason'];
            $seen[$step['reason']] = ($seen[$step['reason']] ?? 0) + 1;
        }
        $check($basis->priority() === $at, 'the legacy steps fold to the legacy priority');
        $noFix = $finding->noFixExpected();
        $verdict = $finding->verdict();
        $check((!\in_array($verdict, Finding::NO_FIX_VERDICTS, true) || !Verdict013::flagged($verdict)) === ($noFix === null), 'the no-fix list is null exactly off the no-fix verdicts');
        $expected = [];
        if (Verdict013::flagged($verdict)) {
            if ($row['direct'] !== true) {
                $expected[] = $row['chain'] === [] ? PriorityBasis013::STEP_UNREACHED : PriorityBasis013::STEP_TRANSITIVE;
            }
            if ($row['dev'] === true) {
                $expected[] = PriorityBasis013::STEP_DEV;
            }
            if (\is_array($noFix) && $noFix !== []) {
                $expected[] = PriorityBasis013::STEP_NO_FIX_EXPECTED;
            }
        } else {
            $check(Priority013::NONE === $basis->base(), 'an unflagged legacy verdict has no priority');
        }
        $check($expected === $reasons, 'one legacy step for each fact that holds, in order: '.json_encode($reasons));
        $check((\is_array($noFix) && $noFix !== []) === (strpos($finding->evidence(), 'no fix expected') !== false), 'the evidence says no fix expected exactly when the list names one');

        $facts013 = $finding->advisoryFacts013();
        $rows = [];
        foreach ($facts013->rows() as $advisory) {
            $rows[$advisory['id']] = $advisory;
        }
        $s9 = [];
        foreach (self::rows($row, ['signals']) as $signal) {
            if ($signal['id'] === 'S9') {
                $s9 = array_column(self::rows($signal, ['data', 'advisories']), 'id');
            }
        }
        $legacyIds = array_map('strval', array_keys($rows));
        sort($legacyIds);
        sort($s9);
        $check($legacyIds === $s9, 'the legacy rows are the S9 rows');
        if (\is_array($noFix)) {
            $unfixed = array_keys(array_filter($rows, static fn (array $advisory): bool => $verdict === Verdict::LEFT_BEHIND ? $advisory['fixed_on_branch'] !== true : $advisory['fixed_by'] === null));
            $check($unfixed === array_column($noFix, 'id'), 'the no-fix list names every unfixed legacy row, and only those');
        }
        foreach (\is_array($noFix) ? $noFix : [] as $item) {
            $advisory = $rows[$item['id']] ?? null;
            $seen[$item['reason']] = ($seen[$item['reason']] ?? 0) + 1;
            if ($advisory === null) {
                $check(false, 'a no-fix id names a legacy row: '.$item['id']);
                continue;
            }
            $read = $facts013->releasesRead();
            switch ($item['reason']) {
                case NoFix013::NOT_ON_INSTALLED_BRANCH:
                    $check($verdict === Verdict::LEFT_BEHIND && $advisory['fixed_by'] !== null && $advisory['fixed_on_branch'] === false, 'not_on_installed_branch: left-behind, fixed off the branch');
                    break;
                case NoFix013::RELEASES_UNKNOWN:
                    $check(!$read && $advisory['fixed_by'] === null, 'releases_unknown: the releases were not read');
                    break;
                case NoFix013::AFFECTED_RANGE_UNKNOWN:
                    $check($read && $advisory['affected_versions'] === null && $advisory['fixed_by'] === null, 'affected_range_unknown: no range');
                    break;
                case NoFix013::NO_RELEASE_FIXES:
                    $check($read && $advisory['fixed_by'] === null && $advisory['affected_versions'] !== null, 'no_release_fixes: read, ranged and unfixed');
                    break;
                default:
                    $check(false, 'a no-fix reason this release does not write: '.$item['reason']);
            }
        }

        if (Verdict013::flagged($verdict) || $s9 !== []) {
            $legacy = (new Explanation($finding, $facts, new Thresholds(), $target, $analysis->report()))->toArray()['legacy'] ?? null;
            $check($legacy === ['verdict' => $verdict, 'priority' => $basis->priority(), 'basis' => $basis->toArray()], 'explain-2\'s legacy block is the finding\'s legacy view');
        }

        return $out;
    }

    private static function analysis(FixtureRepositoryServer $server, string $dir, bool $withManifest, bool $dev, string $target): Analysis
    {
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $project = $withManifest ? ProjectConfig::fromFile(self::FIXTURES.$dir.'/composer.json') : ProjectConfig::empty();
        $failing = new class (new RepositoryMetadataLoader($server->repositories(), $clock), self::METADATA_FAILS) implements MetadataLoaderInterface {
            private MetadataLoaderInterface $loader;
            private string $name;

            public function __construct(MetadataLoaderInterface $loader, string $name)
            {
                $this->loader = $loader;
                $this->name = $name;
            }

            public function load(array $installedByName): MetadataBatch
            {
                $batch = $this->loader->load($installedByName);
                if (!\array_key_exists($this->name, $installedByName)) {
                    return $batch;
                }
                $metadata = $batch->metadata();
                unset($metadata[$this->name]);

                return new MetadataBatch($metadata, $batch->notFound(), array_merge($batch->failed(), [$this->name => 'HTTP 500']));
            }
        };
        $analyzer = new Analyzer(
            $failing,
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
        $lock = LockFile::fromFile(self::FIXTURES.$dir.'/composer.lock');

        return $analyzer->analyzeWithFacts($lock->packages($dev), $lock, $project, $dev);
    }

    /** @return list<array<mixed, mixed>> the findings of the report-2 document */
    private static function findings(Analysis $analysis, string $dir): array
    {
        $document = json_decode((string) json_encode($analysis->report()->toArray()), true);
        self::assertIsArray($document);
        $findings = self::rows($document, ['findings']);
        self::assertNotSame([], $findings, $dir);

        return $findings;
    }
}
