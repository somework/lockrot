<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\AdvisoryRepository;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\RunFooter;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FindingDetails;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

/** The run footer lines that the composite does not reach. */
final class RunFooterTest extends TestCase
{
    private const AT = '2026-10-08T00:00:00+00:00';
    private const PACKAGIST = 'composer repo (https://repo.packagist.org)';

    /** @param list<Finding> $findings */
    private static function report(array $findings, ?AdvisoryCoverage $coverage = null): Report
    {
        return new Report($findings, [], new \DateTimeImmutable(self::AT), \count($findings), 0, null, null, false, $coverage);
    }

    /** @param list<string> $packages */
    private static function composite(array $packages): Report
    {
        return self::report(array_values(array_filter(CaseHydrator::composite()->findings(), static fn (Finding $finding): bool => \in_array($finding->package(), $packages, true))));
    }

    public static function asWritten(string $package): string
    {
        return $package;
    }

    /** @param list<string> $chain */
    private static function stale(string $package, array $chain): Finding
    {
        return (new FindingBuilder())->withPackage($package)->withVerdict(Verdict::STALE)->withChain($chain)->build();
    }

    /** A finding that no advisory feed was asked about. */
    private static function notAsked(string $package): Finding
    {
        $finding = self::stale($package, [$package]);
        $details = $finding->details();

        return $finding->withDetails(new FindingDetails($details->metadataStatus(), null, null, $details->skipped(), $details->installedPhp(), null, 'complete', null, [], null, $details->activity(), $details->age(), null, null));
    }

    public function testTheFlagsLineCountsThePackagesWithMoreThanOneFlagOnlyWhenOneHasThem(): void
    {
        self::assertSame('flags: vulnerable 1', RunFooter::flags(self::composite(['twig/twig'])));
        self::assertSame('flags: abandoned 1 · old-promise 1 · vulnerable 1 · 1 package carries more than one', RunFooter::flags(self::composite(['twig/twig', 'symfony/debug'])));
    }

    public function testTheDepthLineOfALockOnlyRunSaysWhyNothingIsMeasured(): void
    {
        $one = (new FindingBuilder())->withPackage('a/one')->withVerdict(Verdict::STALE)->withChain([])->withComposerJsonRead(false)->build();
        $two = (new FindingBuilder())->withPackage('a/two')->withVerdict(Verdict::STALE)->withChain([])->withComposerJsonRead(false)->build();

        self::assertSame('depth: composer.json not read, so depth is not measured for 1 flagged package', RunFooter::depth(self::report([$one]), [self::class, 'asWritten']));
        self::assertSame('depth: composer.json not read, so depth is not measured for 2 flagged packages', RunFooter::depth(self::report([$one, $two]), [self::class, 'asWritten']));
    }

    public function testTheDepthLineSaysEveryPackageIsNamedAndSkipsADepthWithNoPackage(): void
    {
        self::assertSame('depth: every flagged package is named in composer.json · direct 1', RunFooter::depth(self::composite(['twig/twig']), [self::class, 'asWritten']));
        self::assertSame('depth: 1 of 1 flagged package is named by no line of composer.json · 1 hop 1 · deepest: symfony/polyfill-intl-idn, 1 hop (guzzlehttp/guzzle › symfony/polyfill-intl-idn)', RunFooter::depth(self::composite(['symfony/polyfill-intl-idn']), [self::class, 'asWritten']));
    }

    public function testTheDeepestPartNamesTwoOrThreePackagesWithAnd(): void
    {
        $two = self::report([self::stale('x/a', ['root/r', 'x/a']), self::stale('x/b', ['root/r', 'x/b'])]);
        $three = self::report([self::stale('x/a', ['root/r', 'x/a']), self::stale('x/b', ['root/r', 'x/b']), self::stale('x/c', ['root/r', 'x/c'])]);

        self::assertStringContainsString(' · deepest: x/a and x/b, 1 hop (root/r › x/a)', (string) RunFooter::depth($two, [self::class, 'asWritten']));
        self::assertStringContainsString(' · deepest: x/a, x/b and x/c, 1 hop (root/r › x/a)', (string) RunFooter::depth($three, [self::class, 'asWritten']));
    }

    public function testTheSecurityLineOfOneVulnerablePackageListsOnlyItsSeverities(): void
    {
        self::assertSame('security: 1 vulnerable package · 1 advisory: 1 high', RunFooter::security(self::composite(['symfony/monolog-bridge']), false));
    }

    public function testTheSecurityLineWithNoVulnerablePackageStillNamesAnUncheckedLookup(): void
    {
        $report = self::report([FindingBuilder::advisoriesUnchecked('a/offline', 'offline', true)]);

        self::assertSame('security: no vulnerable package · advisories not checked', RunFooter::security($report, false));
    }

    public function testTheAdvisoriesLineSaysWhyPackagesWereNotAskedAndWhatEachRepositoryAnswered(): void
    {
        $findings = [self::notAsked('a/one'), self::notAsked('a/two')];
        $limited = new AdvisoryCoverage(AdvisoryCoverage::SCOPE_COMPOSER_REPOSITORIES, AdvisoryCoverage::SOURCE_CONFIG, [], 0, []);
        $offline = new AdvisoryCoverage(AdvisoryCoverage::SCOPE_ALL, AdvisoryCoverage::SOURCE_DEFAULT, [AdvisoryRepository::of(self::PACKAGIST, AdvisoryRepository::NO_FEED), AdvisoryRepository::of(self::PACKAGIST, AdvisoryRepository::FAILED, AdvisoryCoverage::TRANSPORT, 'timeout')], 0, []);
        $silent = new AdvisoryCoverage(AdvisoryCoverage::SCOPE_ALL, AdvisoryCoverage::SOURCE_DEFAULT, [AdvisoryRepository::of(self::PACKAGIST, AdvisoryRepository::NO_FEED)], 0, []);

        self::assertSame('advisories: 0 packages asked, 2 not asked (packages from a Composer repository only (advisory-lookup))', RunFooter::advisories(self::report($findings, $limited)));
        self::assertSame('advisories: 0 packages asked, 2 not asked (network error) · packagist.org publishes no advisories · packagist.org lookup failed (network error: timeout)', RunFooter::advisories(self::report($findings, $offline)));
        self::assertSame('advisories: 0 packages asked, 2 not asked · packagist.org publishes no advisories', RunFooter::advisories(self::report($findings, $silent)));
    }

    public function testTheDepthLineCountsTheUnreachedTheDevAndTheRequireDevOnlyPackages(): void
    {
        $unreached = (new FindingBuilder())->withPackage('a/unreached')->withVerdict(Verdict::STALE)->withChain([])->build();
        $dev = (new FindingBuilder())->withPackage('a/dev')->withVerdict(Verdict::STALE)->withDev(true)->build();
        $devOnly = (new FindingBuilder())->withPackage('a/dev-only')->withVerdict(Verdict::STALE)->withChain(['root/r', 'a/dev-only'])->withNamedIn([ProjectConfig::REQUIRE_DEV])->build();
        $report = new Report([$unreached, $dev, $devOnly], [], new \DateTimeImmutable(self::AT), 3, 0, null, null, true);

        self::assertSame('depth: 1 of 3 flagged packages is named by no line of composer.json · direct 1 · 1 hop 1 · unreached 1 · 1 in packages-dev · deepest: a/dev-only, 1 hop (root/r › a/dev-only) · 1 more is named only in require-dev, which this run did not walk (pass --dev)', RunFooter::depth($report, [self::class, 'asWritten']));
    }

    public function testTheAdvisoriesLineCountsTheRepositoriesThatPublishNoAdvisories(): void
    {
        $coverage = new AdvisoryCoverage(AdvisoryCoverage::SCOPE_ALL, AdvisoryCoverage::SOURCE_DEFAULT, [AdvisoryRepository::of(self::PACKAGIST, AdvisoryRepository::ANSWERED)->withRecords(3, 2)], 2, []);

        self::assertSame('advisories: 0 packages asked, 1 not asked · packagist.org answered (3 on record for 2 packages) · 2 other repositories publish no advisories', RunFooter::advisories(self::report([self::notAsked('a/one')], $coverage)));
    }
}
