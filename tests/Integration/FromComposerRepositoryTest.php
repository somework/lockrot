<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analysis;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Libyears;
use Lockrot\Clock;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Explain\Explanation;
use Lockrot\Html\PageData;
use Lockrot\Html\ReportDocument;
use Lockrot\Json\Schemas;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Output\JsonFormatter;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ReportSample;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `from_composer_repository` on a finding is the one lock-entry fact behind four places a document
 * says it: the finding's own boolean, the `--explain` document's `lock.from_composer_repository`,
 * the root `not_from_composer_repository` count and the libyears block's bucket of the same name.
 * Every fixture lock with an entry that carries no notification-url is analysed, and all four are
 * read against each other and against the lock entry — with the exact number of such entries per
 * fixture, so the check cannot pass on 0 == 0.
 *
 * It covers nothing: it validates every fixture app's report and explanations, which pushes the
 * lines it covers past the timeout of a mutation run. The unit tests of the finding and its origin
 * kill those mutants.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class FromComposerRepositoryTest extends TestCase
{
    use ValidatesJsonSchemas;

    private const FIXTURES = __DIR__.'/../fixtures/';
    private const NOW = '2026-09-14T00:00:00+00:00';

    /**
     * The non-dev lock entries without a notification-url, per fixture: path entries (drupal), a
     * `type: composer` repository that advertises no notify URL (asset-packagist in yii2), vcs
     * entries, and three fixtures with none at all for the true side.
     */
    private const EXPECTED = [
        'apps/drupal_drupal' => 5,
        'skeletons/yii2' => 4,
        'apps/PrestaShop_PrestaShop' => 2,
        'apps/koel_koel' => 1,
        'skeletons/shopware' => 1,
        'apps/mautic_mautic' => 1,
        'apps/grokability_snipe-it' => 1,
        'mini' => 1,
        'apps/wallabag_wallabag' => 0,
        'apps/matomo-org_matomo' => 0,
        'skeletons/laravel' => 0,
    ];

    /**
     * The same count with `--dev`: matomo's one entry without a notification-url is a
     * packages-dev entry, so the false side gets a dev finding.
     */
    private const EXPECTED_WITH_DEV = [
        'apps/matomo-org_matomo' => 1,
    ];

    private static ?FixtureRepositoryServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = FixtureRepositoryServer::fromLockFiles(array_map(static fn (string $dir): string => self::FIXTURES.$dir.'/composer.lock', array_keys(self::EXPECTED)));
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            self::$server->stop();
            self::$server = null;
        }
    }

    /** @return iterable<string, array{string, int, bool}> */
    public static function fixtures(): iterable
    {
        foreach (self::EXPECTED as $dir => $count) {
            yield $dir => [$dir, $count, false];
        }
        foreach (self::EXPECTED_WITH_DEV as $dir => $count) {
            yield $dir.' --dev' => [$dir, $count, true];
        }
    }

    /**
     * @dataProvider fixtures
     */
    #[DataProvider('fixtures')]
    public function testEveryFindingSaysWhatItsLockEntrySays(string $dir, int $expected, bool $includeDev): void
    {
        $analysis = $this->analysis($dir, $includeDev);
        $lockEntries = self::lockEntries($dir);
        $report = $analysis->report();
        $json = (new JsonFormatter())->format($report);
        $document = json_decode($json, true);
        self::assertIsArray($document);
        $sample = ReportSample::json($json);
        $this->assertValid(Schemas::REPORT, $sample, $dir);
        $this->assertValid(Schemas::REPORT, $sample, $dir, true);
        $page = (new ReportDocument($report, new PageData($analysis, new Thresholds(), '8.4')))->toArray(true);
        $details = $page['details'];
        self::assertIsArray($details);

        $false = 0;
        foreach (JsonPath::arrayAt($document, ['findings']) as $at => $row) {
            self::assertIsArray($row);
            $package = JsonPath::stringAt($document, ['findings', $at, 'package']);
            $what = $dir.' '.$package;
            self::assertArrayHasKey('from_composer_repository', $row, $what);
            $value = $row['from_composer_repository'];
            self::assertIsBool($value, $what);
            $finding = $analysis->finding($package);
            $facts = $analysis->facts($package);
            self::assertNotNull($finding, $what);
            self::assertNotNull($facts, $what);

            self::assertArrayHasKey($package, $lockEntries, $what);
            self::assertSame($lockEntries[$package]['notified'], $value, $what.': the lock entry');
            self::assertSame($lockEntries[$package]['dev'], $row['dev'], $what.': the lock section');
            self::assertSame($facts->package()->isFromComposerRepository(), $value, $what.': the locked package');
            self::assertSame($value, $finding->isFromComposerRepository(), $what);
            self::assertSame($value, JsonPath::arrayAt($page, ['details', $package, 'lock'])['from_composer_repository'], $what.': the page\'s details');
            $explained = (new Explanation($finding, $facts, new Thresholds(), '8.4', $report))->toArray();
            self::assertSame($value, JsonPath::arrayAt($explained, ['finding'])['from_composer_repository'], $what.': the explanation\'s finding');
            self::assertSame($value, JsonPath::arrayAt($explained, ['lock'])['from_composer_repository'], $what.': the explanation\'s lock');
            if ($value) {
                self::assertNotSame(Finding::NOTE_NOT_IN_REPOSITORY, $row['note'], $what);
            } else {
                ++$false;
                self::assertSame(Finding::NOTE_NOT_IN_REPOSITORY, $row['note'], $what);
                self::assertNull($row['libyears'], $what);
            }
        }

        self::assertSame($expected, $false, $dir.': the entries without a notification-url');
        self::assertSame($false, $document['not_from_composer_repository'], $dir.': the root count');
        self::assertSame($false, JsonPath::arrayAt($document, ['libyears', 'unmeasured'])[Libyears::NOT_FROM_COMPOSER_REPOSITORY], $dir.': the libyears bucket');
        self::assertCount(\count($report->findings()), $details, $dir.': every package explained');
    }

    public function testAnExplanationOfAnEntryNoRepositoryWasAskedAboutValidates(): void
    {
        $analysis = $this->analysis('apps/drupal_drupal');
        $explained = 0;
        foreach ($analysis->report()->findings() as $finding) {
            if ($finding->isFromComposerRepository()) {
                continue;
            }
            $facts = $analysis->facts($finding->package());
            self::assertNotNull($facts);
            $json = (new ExplainFormatter())->json(new Explanation($finding, $facts, new Thresholds(), '8.4', $analysis->report()));
            $this->assertValid(Schemas::EXPLAIN, $json, $finding->package());
            $this->assertValid(Schemas::EXPLAIN, $json, $finding->package(), true);
            ++$explained;
        }
        self::assertSame(self::EXPECTED['apps/drupal_drupal'], $explained);
    }

    /**
     * The lock read as plain JSON, not through lockrot: per package, whether its entry carries a
     * non-empty notification-url and whether it sits in packages-dev.
     *
     * @return array<string, array{notified: bool, dev: bool}>
     */
    private static function lockEntries(string $dir): array
    {
        $raw = file_get_contents(self::FIXTURES.$dir.'/composer.lock');
        self::assertIsString($raw);
        $lock = json_decode($raw, true);
        self::assertIsArray($lock);
        $entries = [];
        foreach (['packages' => false, 'packages-dev' => true] as $section => $dev) {
            $rows = $lock[$section] ?? [];
            self::assertIsArray($rows);
            foreach ($rows as $entry) {
                self::assertIsArray($entry);
                self::assertIsString($entry['name']);
                $url = $entry['notification-url'] ?? null;
                $entries[$entry['name']] = ['notified' => \is_string($url) && $url !== '', 'dev' => $dev];
            }
        }

        return $entries;
    }

    private function analysis(string $dir, bool $includeDev = false): Analysis
    {
        $server = self::$server;
        self::assertNotNull($server);
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        // Offline: the fact is read from the lock, and no forge envelope decides it.
        $analyzer = new Analyzer(
            new RepositoryMetadataLoader($server->repositories(), $clock),
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

        return $analyzer->analyzeWithFacts($lock->packages($includeDev), $lock, ProjectConfig::fromFile(self::FIXTURES.$dir.'/composer.json'), $includeDev);
    }
}
