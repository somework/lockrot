<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Allowlist\Allowlist;
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
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FakeHttpClient;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\TestCase;

/** A package whose only fact is a counted advisory has the report-1 verdict `ok` and a grade. */
final class GradePredicateTest extends TestCase
{
    private const NOW = '2026-10-01T00:00:00+00:00';

    public function testAVulnerableOnlyPackageIsGradedAndNotFlagged(): void
    {
        $report = self::report();
        $vulnerable = self::named($report, 'vendor/vulnerable');

        self::assertSame(Verdict::OK, $vulnerable->verdict());
        self::assertSame('high', $vulnerable->grade());
        self::assertTrue($vulnerable->isGraded());
        self::assertNull($vulnerable->lead(), 'no maintenance flag counts');
        self::assertSame(['vendor/vulnerable', 'vendor/stale'], self::packages($report->graded()));
        self::assertSame(['vendor/stale'], self::packages($report->flagged()));
        self::assertSame(['critical' => 0, 'high' => 1, 'medium' => 1, 'low' => 0, 'unknown' => 0, 'finished' => 0, 'ok' => 1], $report->byGrade());
    }

    public function testByVerdictCountsTheReport1Verdicts(): void
    {
        $counts = self::report()->byVerdict();

        self::assertSame(2, $counts[Verdict::OK]);
        self::assertSame(1, $counts[Verdict::STALE]);
    }

    public function testAVulnerableOnlyTransitivePackageCountsInItsParentsS7AndInTheCounts(): void
    {
        $report = self::report(true);
        $parent = self::named($report, 'vendor/fresh');

        $s7 = null;
        foreach ($parent->signals() as $signal) {
            if ($signal->id() === Signal::S7) {
                $s7 = $signal->data();
            }
        }

        self::assertNotNull($s7, 'the parent of a graded package carries S7');
        self::assertSame(1, JsonPath::intAt($s7, ['flagged']));
        self::assertSame(['package' => 'vendor/vulnerable', 'verdict' => 'high', 'lead' => null, 'flag_ids' => ['vulnerable']], array_intersect_key(JsonPath::arrayAt($s7, ['packages', 0]), array_flip(['package', 'verdict', 'lead', 'flag_ids'])));
        self::assertSame(1, JsonPath::intAt($report->toArray(), ['counts', 'high']));
    }

    private static function report(bool $transitive = false): Report
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'vendor/vulnerable', 'version' => '2.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
            ['name' => 'vendor/stale', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
            ['name' => 'vendor/fresh', 'version' => '3.0.0', 'notification-url' => 'https://packagist.org/downloads/', 'require' => $transitive ? ['vendor/vulnerable' => '^2.0'] : []],
        ]]);
        $require = ['vendor/vulnerable' => '^2.0', 'vendor/stale' => '^1.0', 'vendor/fresh' => '^3.0'];
        $project = ProjectConfig::fromArray(['require' => $transitive ? array_diff_key($require, ['vendor/vulnerable' => true]) : $require]);
        $metadata = [
            'vendor/vulnerable' => self::metadata('vendor/vulnerable', '2026-08-01T00:00:00+00:00', '2.0.0'),
            'vendor/stale' => self::metadata('vendor/stale', '2022-01-01T00:00:00+00:00', '1.0.0'),
            'vendor/fresh' => self::metadata('vendor/fresh', '2026-08-01T00:00:00+00:00', '3.0.0'),
        ];
        $advisories = new AdvisoryBatch(['vendor/vulnerable' => [new Advisory('PKSA-vuln-1', 'CVE-2026-0001', 'Title', null, 'high', null)]]);
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens('t', null));
        $analyzer = new Analyzer(
            self::loader($metadata),
            new ActivityClient(new FakeHttpClient(), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            new Allowlist([]),
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            false,
            self::advisories($advisories)
        );

        return $analyzer->analyze($lock, $project, false);
    }

    private static function metadata(string $name, string $releasedAt, string $version): PackageMetadata
    {
        return new PackageMetadata($name, false, null, true, new \DateTimeImmutable($releasedAt), $version, 1, null, 'library', new \DateTimeImmutable(self::NOW));
    }

    /** @param array<string, PackageMetadata> $metadata */
    private static function loader(array $metadata): MetadataLoaderInterface
    {
        return new class (new MetadataBatch($metadata, [], [])) implements MetadataLoaderInterface {
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

    /**
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    private static function packages(array $findings): array
    {
        return array_map(static fn (Finding $finding): string => $finding->package(), $findings);
    }
}
