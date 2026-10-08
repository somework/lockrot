<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Rule;

use Composer\Semver\VersionParser;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Security\Fix;
use Lockrot\Security\Holder;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Rule\AdvisoryRule;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdvisoryRuleTest extends TestCase
{
    private function advisory(string $id, ?string $cve = null, ?string $severity = 'high'): Advisory
    {
        return new Advisory($id, $cve, 'Title of '.$id, 'https://example.test/'.$id, $severity, new \DateTimeImmutable('2024-03-01T12:00:00+00:00'));
    }

    /** guzzle 6.5.5 carries 14: the three names on the line must be the worst, not the newest. */
    public function testTheWorstSeverityIsNamedFirstAndTiesKeepTheRepositorysOrder(): void
    {
        $facts = new PackageFacts(F::package(), null, null, [
            $this->advisory('M-1', null, 'medium'), $this->advisory('L-1', null, 'low'), $this->advisory('H-1', null, 'high'),
            $this->advisory('N-1', null, null), $this->advisory('C-1', null, 'critical'), $this->advisory('H-2', null, 'high'),
        ]);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame('6 security advisories affect 1.0.0 (C-1, H-1, H-2 and 3 more)', $signal->summary());
        self::assertIsArray(AdvisoryRule::legacy($facts)[1]->rows());
        self::assertSame(['C-1', 'H-1', 'H-2', 'M-1', 'L-1', 'N-1'], array_column(AdvisoryRule::legacy($facts)[1]->rows(), 'id'), 'the JSON list is ordered the same way');
    }

    /** report-2's rows: the severity bucket beside Composer's word, the points and the fix. The finding marks the deciding row. */
    public function testARowCarriesTheBucketThePointsAndTheFix(): void
    {
        $advisory = new Advisory('PKSA-1', 'CVE-2024-0001', 'CVE-2024-0001:  a   title ', null, 'moderate', null);
        $facts = new PackageFacts(F::package(['version' => 'v1.2.3']), null, null, [$advisory]);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame(Signal::LEVEL_WARN, $signal->level(), 'a medium advisory');
        self::assertSame(['advisories', 'releases_read', 'complete'], array_keys($signal->data()));
        self::assertSame([
            'id' => 'PKSA-1', 'cve' => 'CVE-2024-0001', 'title' => 'a title', 'link' => null, 'reported_at' => null,
            'severity' => 'medium', 'severity_published' => 'moderate', 'affected_versions' => null, 'counted' => true, 'points' => 8,
            'fix' => ['kind' => 'unknown', 'to_branch' => null, 'version' => null, 'newest' => null, 'on_installed_branch' => null, 'php' => null, 'held_by' => [], 'reason' => 'affected_range_unknown'],
            'baseline' => null,
        ], JsonPath::arrayAt($signal->data(), ['advisories', 0]));
        self::assertFalse($signal->data()['complete'], 'no lookup coverage');
    }

    public function testNoAdvisoriesIsNull(): void
    {
        self::assertNull((new AdvisoryRule())->evaluate(F::facts(F::package())));
    }

    public function testOneAdvisoryIsNamedByItsCve(): void
    {
        $facts = new PackageFacts(F::package(['version' => 'v1.2.3']), null, null, [$this->advisory('PKSA-1', 'CVE-2024-0001')]);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame(Signal::S9, $signal->id());
        self::assertSame(Signal::LEVEL_HIGH, $signal->level(), 'a high advisory');
        self::assertSame('1 security advisory affects v1.2.3 (CVE-2024-0001)', $signal->summary());
        self::assertSame(['advisories' => [[
            'id' => 'PKSA-1',
            'cve' => 'CVE-2024-0001',
            'title' => 'Title of PKSA-1',
            'link' => 'https://example.test/PKSA-1',
            'severity' => 'high',
            'reported_at' => '2024-03-01T12:00:00+00:00',
            'affected_versions' => null,
            'fixed_by' => null,
            'fixed_on_branch' => false,
        ]], 'releases_read' => false], AdvisoryRule::legacy($facts)[1]->data());
    }

    /**
     * Whether a null `fixed_by` was looked for: no metadata, or an installed version no parser reads,
     * leaves every row null without a release having been compared. A branch snapshot is compared
     * with the package's highest tag, and a tagged version with both candidates.
     */
    public function testTheDataSaysWhetherTheReleasesWereRead(): void
    {
        $range = $this->ranged('CVE-1', '>=1.0.0');
        $meta = F::metadata([['2.0.0', '2026-01-01T00:00:00+00:00'], ['1.0.0', '2020-01-01T00:00:00+00:00']]);

        foreach ([
            'no metadata' => [F::facts(F::package(['version' => '1.0.0']), null, null, [$range]), false],
            'an installed version that does not normalize' => [F::facts(F::package(['version' => 'not-a-version']), $meta, null, [$range]), false],
            'a branch snapshot with metadata' => [F::facts(F::package(['version' => 'dev-main']), $meta, null, [$range]), true],
            'a tagged version with metadata' => [F::facts(F::package(['version' => '1.0.0']), $meta, null, [$range]), true],
        ] as $case => [$facts, $read]) {
            $signal = (new AdvisoryRule())->evaluate($facts);
            self::assertNotNull($signal, $case);
            self::assertSame($read, AdvisoryRule::legacy($facts)[1]->releasesRead(), $case);
            self::assertSame(['advisories', 'releases_read'], array_keys(AdvisoryRule::legacy($facts)[1]->data()), $case.': next to the rows');
            self::assertNull(self::row($facts, 0)['fixed_by'], $case.': nothing fixes it either way');
        }
    }

    /** @return array<mixed, mixed> */
    private static function row(PackageFacts $facts, int $i): array
    {
        $rows = AdvisoryRule::legacy($facts)[1]->rows();
        self::assertArrayHasKey($i, $rows);

        return $rows[$i];
    }

    private function ranged(string $id, string $range, ?string $severity = 'high'): Advisory
    {
        return new Advisory($id, null, 'Title of '.$id, null, $severity, null, (new VersionParser())->parseConstraints($range));
    }

    /** symfony/http-foundation v3.4.18: one CVE fixed on 3.x (3.4.35), one fixed only in 8.x, one open even there. */
    public function testEachAdvisoryIsCheckedAgainstTheBranchsHighestTagThenThePackages(): void
    {
        $meta = F::metadata([['v8.1.7', '2026-09-14T00:00:00+00:00'], ['v3.4.47', '2020-10-24T00:00:00+00:00'], ['v3.4.18', '2018-11-20T00:00:00+00:00']]);
        $facts = F::facts(F::package(['version' => 'v3.4.18']), $meta, null, [
            $this->ranged('CVE-2019-18888', '>=3.0.0,<3.4.35|>=4.0.0,<4.2.12', 'medium'),
            $this->ranged('CVE-2024-50345', '>=2.0.0,<5.4.50|>=6.0.0,<6.4.29|>=7.0.0,<7.3.7', 'high'),
            $this->ranged('CVE-2099-1', '>=2.0.0', 'low'),
            $this->ranged('CVE-2025-64500', '>=2.0.0,<5.4.50|>=6.0.0,<6.4.29|>=7.0.0,<7.3.7', 'high'),
        ]);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame('4 security advisories affect v3.4.18 (CVE-2024-50345, CVE-2025-64500, CVE-2019-18888 and 1 more); 2 fixed by v8.1.7, 1 fixed by v3.4.47', $signal->summary());
        self::assertIsArray(AdvisoryRule::legacy($facts)[1]->rows());
        $rows = [];
        foreach (AdvisoryRule::legacy($facts)[1]->rows() as $row) {
            self::assertIsArray($row);
            $rows[] = [$row['id'], $row['fixed_by'], $row['fixed_on_branch']];
        }
        self::assertSame([
            ['CVE-2024-50345', 'v8.1.7', false],
            ['CVE-2025-64500', 'v8.1.7', false],
            ['CVE-2019-18888', 'v3.4.47', true],
            ['CVE-2099-1', null, false],
        ], $rows);
    }

    /** One of two fixed: the count stays on the clause, `fixed by` alone is for when every advisory is. */
    public function testAPartialFixKeepsItsCountEvenWhenOneReleaseFixesEverythingItFixes(): void
    {
        $meta = F::metadata([['2.0.0', '2026-01-01T00:00:00+00:00'], ['1.0.0', '2020-01-01T00:00:00+00:00']]);
        $facts = F::facts(F::package(['version' => '1.0.0']), $meta, null, [$this->ranged('CVE-1', '<2.0.0'), $this->ranged('CVE-2', '>=1.0.0')]);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame('2 security advisories affect 1.0.0 (CVE-1, CVE-2); 1 fixed by 2.0.0', $signal->summary());
    }

    /** swiftmailer 6.1.3: the one advisory is out of range by 6.3.0, the package's highest tag and the branch's. */
    public function testEveryAdvisoryFixedByOneReleaseReadsFixedBy(): void
    {
        $meta = F::metadata([['6.3.0', '2021-10-18T00:00:00+00:00'], ['6.1.3', '2018-09-11T00:00:00+00:00']]);
        $facts = F::facts(F::package(['version' => '6.1.3']), $meta, null, [$this->ranged('CVE-2024-28859', '>=4.0.0,<6.0.0|>=6.0.0,<6.2.5')]);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame('1 security advisory affects 6.1.3 (CVE-2024-28859); fixed by 6.3.0', $signal->summary());
        self::assertTrue(self::row($facts, 0)['fixed_on_branch'], 'the branch\'s highest tag is the package\'s: one candidate, named once');
    }

    /** Nothing to check against: no metadata, a branch snapshot with no branch, or a range that covers every listed release. */
    public function testWithoutAFixingReleaseTheRowsSayNullAndTheSummaryAddsNothing(): void
    {
        $range = $this->ranged('CVE-1', '>=1.0.0');
        $meta = F::metadata([['2.0.0', '2026-01-01T00:00:00+00:00'], ['1.0.0', '2020-01-01T00:00:00+00:00']]);

        foreach ([
            'no metadata' => F::facts(F::package(['version' => '1.0.0']), null, null, [$range]),
            'branch snapshot' => F::facts(F::package(['version' => 'dev-main']), $meta, null, [$range]),
            'open on every release' => F::facts(F::package(['version' => '1.0.0']), $meta, null, [$range]),
        ] as $case => $facts) {
            $signal = (new AdvisoryRule())->evaluate($facts);
            self::assertNotNull($signal, $case);
            self::assertSame('1 security advisory affects '.$facts->package()->version().' (CVE-1)', $signal->summary(), $case);
            self::assertNull(self::row($facts, 0)['fixed_by'], $case);
        }
    }

    /**
     * The repository lists nothing above what is installed — a deleted tag, a pre-release
     * ahead of every stable tag, a pretty version no parser reads. A tag the range spares is not a
     * fix when reaching it means going back, so none is named.
     */
    public function testATagBelowTheInstalledVersionIsNoFixEvenWhenTheRangeSparesIt(): void
    {
        $listedBelow = F::metadata([['1.9.0', '2026-01-01T00:00:00+00:00'], ['1.0.0', '2020-01-01T00:00:00+00:00']]);

        foreach ([
            'installed above every tag on its branch' => F::facts(F::package(['version' => '1.9.5']), $listedBelow, null, [$this->ranged('CVE-1', '>=1.9.1')]),
            'a pre-release of a major with no stable tag yet' => F::facts(F::package(['version' => '2.0.0-beta1']), $listedBelow, null, [$this->ranged('CVE-1', '>=2.0.0-alpha1')]),
            'an unparsable installed version' => F::facts(F::package(['version' => 'not-a-version']), $listedBelow, null, [$this->ranged('CVE-1', '>=1.9.1')]),
        ] as $case => $facts) {
            $signal = (new AdvisoryRule())->evaluate($facts);
            self::assertNotNull($signal, $case);
            self::assertSame('1 security advisory affects '.$facts->package()->version().' (CVE-1)', $signal->summary(), $case);
            self::assertNull(self::row($facts, 0)['fixed_by'], $case);
            self::assertFalse(self::row($facts, 0)['fixed_on_branch'], $case);
        }
    }

    /** The branch's tag is below the installed version, the package's above it: only the second is a candidate. */
    public function testWithTheBranchsTagBelowTheInstalledVersionOnlyThePackagesTagCounts(): void
    {
        $meta = F::metadata([['2.0.0', '2026-01-01T00:00:00+00:00'], ['1.9.0', '2020-01-01T00:00:00+00:00']]);
        $facts = F::facts(F::package(['version' => '1.9.5']), $meta, null, [$this->ranged('CVE-1', '>=1.9.1,<2.0.0')]);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame('1 security advisory affects 1.9.5 (CVE-1); fixed by 2.0.0', $signal->summary());
        self::assertFalse(self::row($facts, 0)['fixed_on_branch'], '1.9.0 spares the range but is below 1.9.5: not the branch\'s fix');
    }

    /** A branch snapshot has no branch, but the package's highest tag can still carry the fix. */
    public function testABranchSnapshotIsCheckedAgainstThePackagesHighestTagOnly(): void
    {
        $meta = F::metadata([['2.0.0', '2026-01-01T00:00:00+00:00'], ['1.0.0', '2020-01-01T00:00:00+00:00']]);
        $facts = F::facts(F::package(['version' => 'dev-main']), $meta, null, [$this->ranged('CVE-1', '<2.0.0')]);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame('1 security advisory affects dev-main (CVE-1); fixed by 2.0.0', $signal->summary());
        self::assertFalse(self::row($facts, 0)['fixed_on_branch']);
    }

    public function testThreeAdvisoriesAreAllNamedAndAnIdStandsInForAMissingCve(): void
    {
        $facts = new PackageFacts(F::package(), null, null, [$this->advisory('PKSA-1', 'CVE-2024-0001'), $this->advisory('GHSA-aaaa'), $this->advisory('PKSA-3', 'CVE-2024-0003')]);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame('3 security advisories affect 1.0.0 (CVE-2024-0001, GHSA-aaaa, CVE-2024-0003)', $signal->summary());
    }

    public function testBeyondThreeTheRestAreCounted(): void
    {
        $advisories = [];
        foreach (range(1, 5) as $i) {
            $advisories[] = $this->advisory('PKSA-'.$i, 'CVE-2024-000'.$i);
        }
        $facts = new PackageFacts(F::package(), null, null, $advisories);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame('5 security advisories affect 1.0.0 (CVE-2024-0001, CVE-2024-0002, CVE-2024-0003 and 2 more)', $signal->summary());
        self::assertIsArray(AdvisoryRule::legacy($facts)[1]->rows());
        self::assertCount(5, AdvisoryRule::legacy($facts)[1]->rows());
    }

    public function testExactlyThreeAreNotCounted(): void
    {
        $facts = new PackageFacts(F::package(), null, null, [$this->advisory('A'), $this->advisory('B'), $this->advisory('C')]);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertStringEndsWith('(A, B, C)', $signal->summary());
    }

    /** The rows stand in the tie-break order of the deciding advisory: points, then severity, then id. */
    public function testTheRowsAreInPointsThenSeverityThenIdOrder(): void
    {
        $advisories = [
            $this->advisory('PKSA-d', null, 'low'),
            $this->advisory('PKSA-c', null, null),
            $this->advisory('PKSA-b', null, 'moderate'),
            $this->advisory('PKSA-a', null, 'moderate'),
            $this->advisory('PKSA-e', null, 'critical'),
        ];

        $signal = (new AdvisoryRule())->evaluate(new PackageFacts(F::package(['version' => 'v1.2.3']), null, null, $advisories));

        self::assertNotNull($signal);
        $rows = JsonPath::arrayAt($signal->data(), ['advisories']);
        self::assertSame(['PKSA-e', 'PKSA-a', 'PKSA-b', 'PKSA-c', 'PKSA-d'], array_column($rows, 'id'));
        self::assertSame([32, 8, 8, 8, 2], array_column($rows, 'points'));
    }

    /**
     * Without the release scan, a ranged advisory's fix is unknown for the reason its origin gives.
     *
     * @dataProvider origins
     */
    #[DataProvider('origins')]
    public function testAnUnscannedFixNamesWhyItIsUnknown(bool $fromComposerRepository, string $reason): void
    {
        $advisory = new Advisory('PKSA-1', null, null, null, 'high', null, (new VersionParser())->parseConstraints('<2.0'));
        $facts = new PackageFacts(F::package(['version' => '1.0.0', 'fromComposerRepository' => $fromComposerRepository]), null, null, [$advisory]);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame($reason, JsonPath::stringAt($signal->data(), ['advisories', 0, 'fix', 'reason']));
    }

    /** @return iterable<string, array{bool, string}> */
    public static function origins(): iterable
    {
        yield 'from a Composer repository' => [true, Fix::RELEASES_UNKNOWN];
        yield 'from vcs or a path' => [false, Fix::NOT_FROM_COMPOSER_REPOSITORY];
    }

    public function testAHolderEntryNamesThePackageExceptForTheRoot(): void
    {
        self::assertSame(
            ['source' => Holder::PACKAGE, 'package' => 'acme/b', 'version' => '2.0.0', 'link' => Holder::REQUIRE, 'constraint' => '^1.0', 'holder' => null],
            AdvisoryRule::heldBy(new Holder(Holder::PACKAGE, 'acme/b', '2.0.0', Holder::REQUIRE, '^1.0'))
        );
        self::assertSame(
            ['source' => Holder::ROOT, 'package' => null, 'version' => null, 'link' => Holder::REQUIRE_DEV, 'constraint' => '^1.0', 'holder' => null],
            AdvisoryRule::heldBy(new Holder(Holder::ROOT, 'acme/app', null, Holder::REQUIRE_DEV, '^1.0'))
        );
    }
}
