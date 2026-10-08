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
use Lockrot\Lock\PackageOrigin;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\JsonFormatter;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
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
 * A finding's `origin` against every fixture lock and its manifest, and against everything else a
 * document says about the same entry: `from_composer_repository`, the note, the root count, the
 * libyears bucket, the page's `details` and the explanation. The kinds per fixture are counted
 * exactly, so the check cannot pass on zero; nothing asks a repository, since an origin needs none.
 *
 * It covers nothing: it validates every fixture app's report, which pushes the lines it covers past
 * the timeout of a mutation run. The unit tests of the finding and its origin kill those mutants.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class OriginAgreementTest extends TestCase
{
    use ValidatesJsonSchemas;

    private const FIXTURES = __DIR__.'/../fixtures/';
    private const NOW = '2026-09-14T00:00:00+00:00';

    /**
     * The findings of every kind but `packagist`, per fixture, without `--dev`. Every other finding
     * is `packagist`. `origins` is the hand-built lock with one entry per case.
     */
    private const EXPECTED = [
        'origins' => ['composer' => 4, 'vcs' => 1, 'package' => 1, 'path' => 1, 'artifact' => 1, 'unknown' => 3],
        'apps/BookStackApp_BookStack' => [],
        'apps/PrestaShop_PrestaShop' => ['vcs' => 2],
        'apps/drupal_drupal' => ['path' => 5],
        'apps/firefly-iii_firefly-iii' => [],
        'apps/grokability_snipe-it' => ['vcs' => 1],
        'apps/koel_koel' => ['vcs' => 1],
        'apps/magento_magento2' => [],
        'apps/matomo-org_matomo' => [],
        'apps/mautic_mautic' => ['path' => 1],
        'apps/monicahq_monica' => [],
        'apps/nextcloud_3rdparty' => [],
        'apps/wallabag_wallabag' => [],
        'mini' => ['unknown' => 1],
        'mini-split' => [],
        'skeletons/bedrock' => ['composer' => 1],
        'skeletons/cakephp' => [],
        'skeletons/drupal' => [],
        'skeletons/laminas' => [],
        'skeletons/laravel' => [],
        'skeletons/shopware' => ['unknown' => 1],
        'skeletons/sylius' => [],
        'skeletons/symfony-webapp' => [],
        'skeletons/yii2' => ['unknown' => 4],
    ];

    /** matomo's one VCS entry is a packages-dev entry. */
    private const EXPECTED_WITH_DEV = ['apps/matomo-org_matomo' => ['vcs' => 1]];

    /** The origin of each entry of the hand-built lock; a `path` dist is on the machine whichever registry notified it. */
    private const ORIGINS = [
        'acme/from-packagist' => ['packagist', 'packagist.org', 'https://packagist.org/packages/acme/from-packagist', false],
        'acme/from-private-packagist' => ['composer', 'repo.packagist.com', null, false],
        'acme/from-wp-packages' => ['composer', 'wp-packages.org', 'https://wp-packages.org/packages/acme/from-wp-packages', false],
        'acme/from-drupal-path' => ['composer', 'packages.drupal.org', null, true],
        'acme/from-private-registry' => ['composer', null, null, false],
        'acme/inline-copied' => ['packagist', 'packagist.org', 'https://packagist.org/packages/acme/inline-copied', false],
        'acme/vcs-lib' => ['vcs', null, null, false],
        'acme/renamed' => ['unknown', null, null, false],
        'acme/inline' => ['package', null, null, false],
        'acme/local' => ['path', null, null, true],
        'acme/artifact' => ['artifact', null, null, true],
        'acme/after-satis' => ['unknown', null, null, false],
        'acme/metapackage' => ['unknown', null, null, false],
        'acme/crafted"onmouseover="x?next=evil.example#x' => ['packagist', 'packagist.org', null, false],
    ];

    /** Values in the hand-built lock and manifest that a document must not carry: logins, a password, an organisation, a machine path, a private host. */
    private const NEVER_WRITTEN = ['ci-user', 's3cr3t', 'secret-org', 'secret-client', 'satis.internal'];

    /** @return iterable<string, array{string, array<string, int>, bool}> */
    public static function fixtures(): iterable
    {
        foreach (self::EXPECTED as $dir => $kinds) {
            yield $dir => [$dir, $kinds, false];
        }
        foreach (self::EXPECTED_WITH_DEV as $dir => $kinds) {
            yield $dir.' --dev' => [$dir, $kinds, true];
        }
    }

    /**
     * @param array<string, int> $expected
     *
     * @dataProvider fixtures
     */
    #[DataProvider('fixtures')]
    public function testEveryFindingsOriginAgreesWithItsLockEntryAndTheRestOfTheDocument(string $dir, array $expected, bool $includeDev): void
    {
        $analysis = $this->analysis($dir, ProjectConfig::fromFile(self::FIXTURES.$dir.'/composer.json'), $includeDev);
        $report = $analysis->report();
        $json = (new JsonFormatter())->format($report);
        $document = json_decode($json, true);
        self::assertIsArray($document);
        $sample = ReportSample::json($json);
        $this->assertValid(Schemas::REPORT, $sample, $dir);
        $this->assertValid(Schemas::REPORT, $sample, $dir, true);
        $page = (new ReportDocument($report, new PageData($analysis, new Thresholds(), '8.4')))->toArray(true);
        $pageFindings = JsonPath::arrayAt($page, ['report', 'findings']);
        $notifications = self::notificationUrls($dir);

        $kinds = [];
        $notFromARepository = 0;
        foreach (JsonPath::arrayAt($document, ['findings']) as $at => $row) {
            self::assertIsArray($row);
            $package = JsonPath::stringAt($document, ['findings', $at, 'package']);
            $what = $dir.' '.$package;
            $origin = JsonPath::arrayAt($row, ['origin']);
            self::assertSame(['kind', 'registry', 'package_url', 'local'], array_keys($origin), $what);
            [$kind, $registry, $url] = [$origin['kind'], $origin['registry'], $origin['package_url']];
            self::assertIsString($kind, $what);
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;

            self::assertSame(\in_array($kind, ['packagist', 'composer'], true), $row['from_composer_repository'], $what.': one rule');
            self::assertArrayHasKey($package, $notifications, $what);
            self::assertSame($notifications[$package] !== null, $row['from_composer_repository'], $what.': the lock entry\'s notification-url');
            self::assertSame(self::hostOf($notifications[$package]) === 'packagist.org', $kind === PackageOrigin::PACKAGIST, $what.': packagist is packagist.org\'s notification-url');
            self::assertSame($kind === PackageOrigin::PACKAGIST, $registry === 'packagist.org', $what);
            if (!$row['from_composer_repository']) {
                ++$notFromARepository;
                self::assertNull($registry, $what);
                self::assertSame(Finding::NOTE_NOT_IN_REPOSITORY, $row['note'], $what);
            }
            self::assertTrue($registry === null || \is_string($registry), $what);
            self::assertSame(self::pageOf(\is_string($registry) ? $registry : null, $package), $url, $what);

            $finding = $analysis->finding($package);
            $facts = $analysis->facts($package);
            self::assertNotNull($finding, $what);
            self::assertNotNull($facts, $what);
            self::assertSame($origin, $finding->origin()->toArray(), $what);
            self::assertSame($origin, $facts->package()->origin()->toArray(), $what.': the facts the finding was decided on');
            self::assertSame($origin, JsonPath::arrayAt($pageFindings, [$at, 'origin']), $what.': the page\'s report');
            self::assertSame($row['from_composer_repository'], JsonPath::arrayAt($page, ['details', $package, 'lock'])['from_composer_repository'], $what.': the page\'s details');
            $explained = (new Explanation($finding, $facts, new Thresholds(), '8.4', $report))->toArray();
            self::assertSame($origin, JsonPath::arrayAt($explained, ['finding', 'origin']), $what.': the explanation');
            self::assertSame($row['from_composer_repository'], JsonPath::arrayAt($explained, ['lock'])['from_composer_repository'], $what.': the explanation\'s lock');
            self::assertIsBool($origin['local'], $what);
            if (\in_array($kind, [PackageOrigin::PATH, PackageOrigin::ARTIFACT], true)) {
                self::assertTrue($origin['local'], $what.': a path or artifact repository is on the machine');
            }
            $shown = JsonPath::arrayAt($explained, ['lock'])['repository'];
            if (\is_string($shown) && strncmp($shown, '...', 3) === 0) {
                self::assertTrue($origin['local'], $what.': the explanation shows a path');
            }
        }

        $others = $kinds;
        unset($others[PackageOrigin::PACKAGIST]);
        ksort($others);
        ksort($expected);
        self::assertSame($expected, $others, $dir.': the kinds');
        self::assertSame($notFromARepository, $document['not_from_composer_repository'], $dir.': the root count');
        self::assertSame($notFromARepository, JsonPath::arrayAt($document, ['libyears', 'unmeasured'])[Libyears::NOT_FROM_COMPOSER_REPOSITORY], $dir.': the libyears bucket');
    }

    public function testEachHandBuiltEntryGetsItsOriginAndNothingOfItsUrlsIsWritten(): void
    {
        $analysis = $this->analysis('origins', ProjectConfig::fromFile(self::FIXTURES.'origins/composer.json'));
        $origins = [];
        foreach ($analysis->report()->findings() as $finding) {
            $origins[$finding->package()] = array_values($finding->origin()->toArray());
        }
        ksort($origins);
        $expected = self::ORIGINS;
        ksort($expected);
        self::assertSame($expected, $origins);

        $page = (string) json_encode((new ReportDocument($analysis->report(), new PageData($analysis, new Thresholds(), '8.4')))->toArray(true));
        $documents = ['json' => (new JsonFormatter())->format($analysis->report()), 'page' => $page];
        foreach ($analysis->report()->findings() as $finding) {
            $facts = $analysis->facts($finding->package());
            self::assertNotNull($facts);
            $documents['explain '.$finding->package()] = (string) json_encode((new Explanation($finding, $facts, new Thresholds(), '8.4', $analysis->report()))->toArray());
        }
        foreach ($documents as $which => $text) {
            foreach (self::NEVER_WRITTEN as $secret) {
                self::assertStringNotContainsString($secret, $text, $which);
            }
        }
    }

    /** Without its manifest, a lock says only what its entries say: a VCS entry is unknown, a path dist and a notification-url are what they were. */
    public function testALockWithoutItsManifestKnowsOnlyWhatItsEntriesSay(): void
    {
        $kinds = [];
        foreach ($this->analysis('origins', ProjectConfig::empty())->report()->findings() as $finding) {
            $kinds[$finding->package()] = $finding->origin()->kind();
        }

        self::assertSame('unknown', $kinds['acme/vcs-lib']);
        self::assertSame('unknown', $kinds['acme/inline']);
        self::assertSame('unknown', $kinds['acme/artifact']);
        self::assertSame('path', $kinds['acme/local']);
        self::assertSame('composer', $kinds['acme/from-drupal-path']);
        self::assertSame('packagist', $kinds['acme/inline-copied']);
    }

    /**
     * Per package, the lock entry's non-empty notification-url, or null — read as plain JSON, not
     * through lockrot.
     *
     * @return array<string, ?string>
     */
    private static function notificationUrls(string $dir): array
    {
        $lock = json_decode((string) file_get_contents(self::FIXTURES.$dir.'/composer.lock'), true);
        self::assertIsArray($lock);
        $urls = [];
        foreach (['packages', 'packages-dev'] as $section) {
            $entries = $lock[$section] ?? [];
            self::assertIsArray($entries);
            foreach ($entries as $entry) {
                self::assertIsArray($entry);
                self::assertIsString($entry['name'] ?? null);
                $url = $entry['notification-url'] ?? null;
                $urls[strtolower($entry['name'])] = \is_string($url) && $url !== '' ? $url : null;
            }
        }

        return $urls;
    }

    private static function hostOf(?string $url): ?string
    {
        $host = $url === null ? null : parse_url($url, \PHP_URL_HOST);

        return \is_string($host) ? strtolower($host) : null;
    }

    /** The page lockrot links, spelt out here rather than read from PackageOrigin. */
    private static function pageOf(?string $registry, string $package): ?string
    {
        if (preg_match('{^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$}', $package) !== 1) {
            return null;
        }
        switch ($registry) {
            case 'packagist.org':
                return 'https://packagist.org/packages/'.$package;
            case 'wp-packages.org':
                return 'https://wp-packages.org/packages/'.$package;
            default:
                return null;
        }
    }

    private function analysis(string $dir, ProjectConfig $project, bool $includeDev = false): Analysis
    {
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $analyzer = new Analyzer(
            new RepositoryMetadataLoader([], $clock, true),
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

        return $analyzer->analyzeWithFacts($lock->packages($includeDev), $lock, $project, $includeDev);
    }
}
