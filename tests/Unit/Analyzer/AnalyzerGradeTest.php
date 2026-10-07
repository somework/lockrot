<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Semver\VersionParser;
use Lockrot\Allowlist\Allowlist;
use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Report;
use Lockrot\Clock;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Advisory\AdvisoryBatch;
use Lockrot\Data\Advisory\AdvisoryLoaderInterface;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FakeHttpClient;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The grade that Analyzer::analyze() gives each finding, from the facts that the run reads. */
final class AnalyzerGradeTest extends TestCase
{
    private const NOW = '2026-10-01T00:00:00+00:00';
    private const RECENT = '2026-09-01T00:00:00+00:00';
    private const OLD = '2021-01-01T00:00:00+00:00';
    private const PACKAGIST = 'https://packagist.org/downloads/';

    /** @return iterable<string, array{string, string, ?string, int}> */
    public static function packages(): iterable
    {
        yield 'a release outside the range fixes the advisory' => ['vendor/update', 'medium', null, 8];
        yield 'no release fixes the advisory: it counts twice' => ['vendor/none', 'high', null, 16];
        yield 'the fixing release needs a PHP that the target does not run: it counts twice' => ['vendor/blocked', 'high', null, 16];
        yield 'the advisory with the most points decides' => ['vendor/two', 'high', null, 16];
        yield 'no release metadata' => ['vendor/absent', 'unknown', null, 0];
        yield 'an entry accepts the whole package' => ['vendor/accepted', 'finished', null, 0];
        yield 'packages-dev halves the whole score' => ['vendor/devtool', 'medium', null, 8];
        yield 'nothing reaches it: maintenance counts half' => ['vendor/orphan', 'low', 'stale', 4];
        yield 'a direct stale package' => ['vendor/stale', 'medium', 'stale', 8];
    }

    /** @dataProvider packages */
    #[DataProvider('packages')]
    public function testTheAnalyzerGradesEachFindingFromItsFacts(string $package, string $grade, ?string $lead, int $total): void
    {
        $finding = self::named(self::report(), $package);

        self::assertSame($grade, $finding->grade());
        self::assertSame($lead, $finding->lead());
        self::assertSame($total, $finding->score()->total());
    }

    private static function report(): Report
    {
        $lock = LockFile::fromArray([
            'packages' => [
                self::locked('vendor/update'),
                self::locked('vendor/none'),
                self::locked('vendor/blocked'),
                self::locked('vendor/absent'),
                self::locked('vendor/accepted'),
                self::locked('vendor/orphan'),
                self::locked('vendor/stale'),
                self::locked('vendor/two'),
            ],
            'packages-dev' => [self::locked('vendor/devtool')],
        ]);
        $project = ProjectConfig::fromArray([
            'require' => ['vendor/update' => '^1.0', 'vendor/none' => '^1.0', 'vendor/blocked' => '^1.0', 'vendor/absent' => '^1.0', 'vendor/accepted' => '^1.0', 'vendor/stale' => '^1.0', 'vendor/two' => '^1.0'],
            'require-dev' => ['vendor/devtool' => '^1.0'],
        ]);
        $metadata = [
            'vendor/update' => self::metadata('vendor/update', [['1.0.0', null, self::RECENT], ['1.1.0', null, self::RECENT]]),
            'vendor/none' => self::metadata('vendor/none', [['1.0.0', null, self::RECENT], ['1.1.0', null, self::RECENT]]),
            'vendor/blocked' => self::metadata('vendor/blocked', [['1.0.0', null, self::RECENT], ['1.1.0', '>=8.5', self::RECENT]]),
            'vendor/accepted' => self::metadata('vendor/accepted', [['1.0.0', null, self::OLD]]),
            'vendor/orphan' => self::metadata('vendor/orphan', [['1.0.0', null, self::OLD]]),
            'vendor/stale' => self::metadata('vendor/stale', [['1.0.0', null, self::OLD]]),
            'vendor/two' => self::metadata('vendor/two', [['1.0.0', null, self::RECENT], ['1.1.0', null, self::RECENT]]),
            'vendor/devtool' => self::metadata('vendor/devtool', [['1.0.0', null, self::RECENT], ['1.1.0', null, self::RECENT]]),
        ];
        $advisories = new AdvisoryBatch([
            'vendor/update' => [self::advisory('PKSA-update', 'medium', '<1.1.0')],
            'vendor/none' => [self::advisory('PKSA-none', 'medium', '<2.0.0')],
            'vendor/blocked' => [self::advisory('PKSA-blocked', 'medium', '<1.1.0')],
            'vendor/devtool' => [self::advisory('PKSA-dev', 'high', '<1.1.0')],
            'vendor/two' => [self::advisory('PKSA-two-low', 'low', '<1.1.0'), self::advisory('PKSA-two-high', 'high', '<1.1.0')],
        ]);
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens('t', null));
        $analyzer = new Analyzer(
            self::loader($metadata, ['vendor/absent']),
            new ActivityClient(new FakeHttpClient(), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            new Allowlist([new AllowlistEntry('vendor/accepted', null, 'interfaces', null, 'builtin')]),
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            false,
            self::advisories($advisories)
        );

        return $analyzer->analyze($lock, $project, true);
    }

    /** @return array<string, mixed> */
    private static function locked(string $name): array
    {
        return ['name' => $name, 'version' => '1.0.0', 'notification-url' => self::PACKAGIST];
    }

    /** @param list<array{string, ?string, string}> $releases version, required PHP, release time */
    private static function metadata(string $name, array $releases): PackageMetadata
    {
        $versions = [];
        foreach ($releases as [$version, $php, $time]) {
            $loaded = (new ArrayLoader())->load(['name' => $name, 'version' => $version, 'time' => $time, 'require' => $php === null ? [] : ['php' => $php]]);
            self::assertInstanceOf(BasePackage::class, $loaded);
            $versions[] = $loaded;
        }

        return PackageMetadata::fromPackages($name, $versions, new \DateTimeImmutable(self::NOW), '1.0.0.0');
    }

    private static function advisory(string $id, string $severity, string $range): Advisory
    {
        return new Advisory($id, null, 'Title', null, $severity, null, (new VersionParser())->parseConstraints($range));
    }

    /**
     * @param array<string, PackageMetadata> $metadata
     * @param list<string>                   $notFound
     */
    private static function loader(array $metadata, array $notFound): MetadataLoaderInterface
    {
        return new class (new MetadataBatch($metadata, $notFound, [])) implements MetadataLoaderInterface {
            private MetadataBatch $batch;

            public function __construct(MetadataBatch $batch)
            {
                $this->batch = $batch;
            }

            public function load(array $installedByName): MetadataBatch
            {
                return $this->batch;
            }
        };
    }

    private static function advisories(AdvisoryBatch $batch): AdvisoryLoaderInterface
    {
        return new class ($batch) implements AdvisoryLoaderInterface {
            private AdvisoryBatch $batch;

            public function __construct(AdvisoryBatch $batch)
            {
                $this->batch = $batch;
            }

            public function load(array $versionByName, array $notFromComposerRepository = [], array $aliasVersionsByName = []): AdvisoryBatch
            {
                return $this->batch;
            }
        };
    }

    private static function named(Report $report, string $package): Finding
    {
        foreach ($report->findings() as $finding) {
            if ($finding->package() === $package) {
                return $finding;
            }
        }
        self::fail('no finding for '.$package);
    }
}
