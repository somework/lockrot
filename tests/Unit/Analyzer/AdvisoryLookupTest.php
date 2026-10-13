<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Analyzer\AdvisoryLookup;
use Lockrot\Analyzer\Report;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\AdvisoryFeed;
use Lockrot\Data\Advisory\AdvisoryNameCoverage;
use Lockrot\Data\Advisory\AdvisoryRepository;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FindingDetails;
use PHPUnit\Framework\TestCase;

final class AdvisoryLookupTest extends TestCase
{
    private const PACKAGIST = 'composer repo (https://repo.packagist.org)';

    private static function finding(string $package, ?AdvisoryNameCoverage $lookup): Finding
    {
        $finding = (new FindingBuilder())->withPackage($package)->build();
        $details = $finding->details();

        return $finding->withDetails(new FindingDetails($details->metadataStatus(), null, null, $details->skipped(), $details->installedPhp(), null, 'complete', null, [], null, $details->activity(), $details->age(), $lookup, null));
    }

    public function testAFindingIsAskedWhenAFeedAnsweredOrFailedForIt(): void
    {
        $coverage = new AdvisoryCoverage(AdvisoryCoverage::SCOPE_COMPOSER_REPOSITORIES, AdvisoryCoverage::SOURCE_CONFIG, [AdvisoryRepository::of(self::PACKAGIST, AdvisoryRepository::ANSWERED)->withRecords(1, 1)], 2, []);
        $findings = [
            self::finding('a/answered', new AdvisoryNameCoverage([AdvisoryFeed::answered(self::PACKAGIST, 1)], 1, null)),
            self::finding('b/failed', new AdvisoryNameCoverage([AdvisoryFeed::failed(self::PACKAGIST, 'HTTP 503')], null, 'lookup_failed')),
            self::finding('c/outside', new AdvisoryNameCoverage([AdvisoryFeed::notAsked(self::PACKAGIST, 'not_from_composer_repository')], null, 'not_from_composer_repository')),
            self::finding('d/none', null),
        ];

        self::assertSame([
            'scope' => 'composer-repositories',
            'scope_source' => 'config',
            'packages_asked' => 2,
            'packages_not_asked' => 2,
            'other_repositories' => 2,
            'repositories' => [['composer_repository' => self::PACKAGIST, 'registry' => 'packagist.org', 'outcome' => 'answered', 'reason' => null, 'message' => null, 'packages_with_records' => 1, 'records' => 1]],
        ], JsonPath::decoded(AdvisoryLookup::of($coverage, $findings)));
    }

    public function testARunWithoutALookupAskedNothing(): void
    {
        $lookup = JsonPath::decoded(AdvisoryLookup::of(AdvisoryCoverage::none(), [self::finding('a/a', null)]));

        self::assertSame(['all', 'default', 0, 1, 0, []], [$lookup['scope'], $lookup['scope_source'], $lookup['packages_asked'], $lookup['packages_not_asked'], $lookup['other_repositories'], $lookup['repositories']]);
    }

    public function testTheReportWritesTheLookupItWasGiven(): void
    {
        $coverage = new AdvisoryCoverage(AdvisoryCoverage::SCOPE_COMPOSER_REPOSITORIES, AdvisoryCoverage::SOURCE_CONFIG, [], 0, []);
        $report = new Report([self::finding('a/a', null)], [], new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), 1, 0, null, null, false, $coverage);

        self::assertSame(['composer-repositories', 'config'], [JsonPath::stringAt(JsonPath::decoded($report->toArray()), ['run', 'advisory_lookup', 'scope']), JsonPath::stringAt(JsonPath::decoded($report->toArray()), ['run', 'advisory_lookup', 'scope_source'])]);
    }
}
