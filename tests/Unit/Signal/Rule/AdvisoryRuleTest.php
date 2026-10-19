<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Rule;

use Composer\Semver\VersionParser;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\AdvisoryNameCoverage;
use Lockrot\Data\Repository\StableRelease;
use Lockrot\Security\Candidate;
use Lockrot\Security\Fix;
use Lockrot\Security\Holder;
use Lockrot\Security\PackageFixes;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
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
    }

    /** A row writes the report date as ISO 8601 and the affected range as the repository wrote it. */
    public function testARowWritesTheReportDateAndTheRange(): void
    {
        $advisory = new Advisory('PKSA-1', null, null, null, 'high', new \DateTimeImmutable('2024-03-01T12:00:00+00:00'), (new VersionParser())->parseConstraints('>=1.0,<1.4'));

        $signal = (new AdvisoryRule())->evaluate(new PackageFacts(F::package(), null, null, [$advisory]));

        self::assertNotNull($signal);
        $row = JsonPath::arrayAt(JsonPath::decoded($signal->data()), ['advisories', 0]);
        self::assertSame('2024-03-01T12:00:00+00:00', $row['reported_at']);
        self::assertSame('>=1.0,<1.4', $row['affected_versions']);
    }

    /** report-2's rows: the severity bucket beside Composer's word, the points and the fix. The finding marks the deciding row. */
    public function testARowCarriesTheBucketThePointsAndTheFix(): void
    {
        $advisory = new Advisory('PKSA-1', 'CVE-2024-0001', 'CVE-2024-0001:  a   title ', null, 'moderate', null);
        $facts = new PackageFacts(F::package(['version' => 'v1.2.3']), null, null, [$advisory]);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame(Signal::LEVEL_WARN, $signal->level(), 'a medium advisory');
        self::assertSame(['advisories', 'releases_read', 'complete'], array_keys(JsonPath::decoded($signal->data())));
        self::assertSame([
            'id' => 'PKSA-1', 'cve' => 'CVE-2024-0001', 'title' => 'a title', 'link' => null, 'reported_at' => null,
            'severity' => 'medium', 'severity_published' => 'moderate', 'affected_versions' => null, 'counted' => true, 'points' => 8, 'deciding' => false,
            'fix' => ['kind' => 'unknown', 'to_branch' => null, 'version' => null, 'newest' => null, 'on_installed_branch' => null, 'php' => null, 'admits_target_php' => null, 'admits_project_php' => null, 'held_by' => [], 'reason' => 'affected_range_unknown'],
            'baseline' => null,
        ], JsonPath::arrayAt(JsonPath::decoded($signal->data()), ['advisories', 0]));
        self::assertFalse(JsonPath::decoded($signal->data())['complete'], 'no lookup coverage');
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
    }

    /**
     * Whether the releases were read: no metadata, or an installed version no parser reads, leaves
     * every fix unjudged. A branch snapshot and a tagged version with metadata are read.
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
            self::assertSame($read, JsonPath::decoded($signal->data())['releases_read'], $case);
        }
    }

    private function ranged(string $id, string $range, ?string $severity = 'high'): Advisory
    {
        return new Advisory($id, null, 'Title of '.$id, null, $severity, null, (new VersionParser())->parseConstraints($range));
    }

    /** @param Fix::UPDATE|Fix::UPGRADE|Fix::RAISE_PHP|Fix::BLOCKED $kind */
    private static function fixIn(string $version, string $branch, string $newest, string $kind = Fix::UPDATE): Fix
    {
        return Fix::to(new Candidate(new StableRelease((new VersionParser())->normalize($version), $version, null, null, false), $branch, $kind, []), $newest, '10', new PhpFloor(null));
    }

    /**
     * @param list<Advisory>     $advisories
     * @param array<string, Fix> $fixes      by advisory id
     */
    private static function scanned(string $version, array $advisories, array $fixes): PackageFacts
    {
        return (new PackageFacts(F::package(['version' => $version]), null, null, $advisories))
            ->withFixes(new PackageFixes($fixes, [], null, null, null));
    }

    /** spomky-labs/otphp v10.0.3 on wallabag: both advisories are fixed in 11.4.3, and 11.5.0 is only the branch's newest release. */
    public function testTheSummaryNamesTheFirstFixingReleaseNotTheNewest(): void
    {
        $fix = self::fixIn('11.4.3', '11', '11.5.0', Fix::UPGRADE);
        $facts = self::scanned('v10.0.3', [$this->ranged('PKSA-a', '<11.4.3'), $this->ranged('PKSA-b', '<11.4.3')], ['PKSA-a' => $fix, 'PKSA-b' => $fix]);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame('2 security advisories affect v10.0.3 (PKSA-a, PKSA-b); fixed in 11.4.3', $signal->summary());
    }

    /** Each group is one fixing release, counted, in the order of the named advisories. */
    public function testAdvisoriesFixedInDifferentReleasesAreCountedPerRelease(): void
    {
        $facts = self::scanned('v3.4.18', [
            $this->ranged('CVE-2019-18888', '<3.4.35', 'medium'),
            $this->ranged('CVE-2024-50345', '<5.4.50', 'high'),
            $this->ranged('CVE-2025-64500', '<5.4.50', 'high'),
        ], [
            'CVE-2019-18888' => self::fixIn('v3.4.35', '3', 'v3.4.47'),
            'CVE-2024-50345' => self::fixIn('v5.4.50', '5', 'v5.4.52', Fix::UPGRADE),
            'CVE-2025-64500' => self::fixIn('v5.4.50', '5', 'v5.4.52', Fix::UPGRADE),
        ]);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame('3 security advisories affect v3.4.18 (CVE-2024-50345, CVE-2025-64500, CVE-2019-18888); 2 fixed in v5.4.50, 1 fixed in v3.4.35', $signal->summary());
    }

    /** One of two fixed: the count stays on the clause, `fixed in` alone is for when every advisory is. */
    public function testAPartialFixKeepsItsCountEvenWhenOneReleaseFixesEverythingItFixes(): void
    {
        $facts = self::scanned('1.0.0', [$this->ranged('CVE-1', '<2.0.0'), $this->ranged('CVE-2', '>=1.0.0')], ['CVE-1' => self::fixIn('2.0.0', '2', '2.1.0', Fix::UPGRADE), 'CVE-2' => Fix::none()]);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame('2 security advisories affect 1.0.0 (CVE-1, CVE-2); 1 fixed in 2.0.0', $signal->summary());
    }

    /** A fix that the target blocks still names its release: the clause says where the fix is, `fix.kind` says whether the move runs. */
    public function testABlockedFixStillNamesItsRelease(): void
    {
        $facts = self::scanned('1.0.0', [$this->ranged('CVE-1', '<2.0.0')], ['CVE-1' => self::fixIn('2.0.0', '2', '2.0.0', Fix::BLOCKED)]);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame('1 security advisory affects 1.0.0 (CVE-1); fixed in 2.0.0', $signal->summary());
    }

    /** A fix with no version (`unknown` or `none`, or no release scan at all) adds no clause. */
    public function testWithoutAFixingReleaseTheSummaryAddsNothing(): void
    {
        $range = $this->ranged('CVE-1', '>=1.0.0');
        $meta = F::metadata([['2.0.0', '2026-01-01T00:00:00+00:00'], ['1.0.0', '2020-01-01T00:00:00+00:00']]);

        foreach ([
            'no release scan, though 2.0.0 is outside the range' => F::facts(F::package(['version' => '1.0.0']), $meta, null, [$this->ranged('CVE-1', '<2.0.0')]),
            'no release outside the range' => self::scanned('1.0.0', [$range], ['CVE-1' => Fix::none()]),
            'an unknown fix' => self::scanned('1.0.0', [$range], ['CVE-1' => Fix::unknown(Fix::RELEASES_UNKNOWN)]),
        ] as $case => $facts) {
            $signal = (new AdvisoryRule())->evaluate($facts);
            self::assertNotNull($signal, $case);
            self::assertSame('1 security advisory affects 1.0.0 (CVE-1)', $signal->summary(), $case);
        }
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
        $rows = JsonPath::arrayAt(JsonPath::decoded($signal->data()), ['advisories']);
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
        self::assertSame($reason, JsonPath::stringAt(JsonPath::decoded($signal->data()), ['advisories', 0, 'fix', 'reason']));
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
            ['source' => Holder::PACKAGE, 'package' => 'acme/b', 'version' => '2.0.0', 'link' => Holder::REQUIRE, 'target' => null, 'constraint' => '^1.0', 'holder' => null],
            JsonPath::decoded(new Holder(Holder::PACKAGE, 'acme/b', '2.0.0', Holder::REQUIRE, '^1.0'))
        );
        self::assertSame(
            ['source' => Holder::ROOT, 'package' => null, 'version' => null, 'link' => Holder::REQUIRE_DEV, 'target' => null, 'constraint' => '^1.0', 'holder' => null],
            JsonPath::decoded(new Holder(Holder::ROOT, 'acme/app', null, Holder::REQUIRE_DEV, '^1.0'))
        );
    }

    /** Two advisories of one severity sort by their points, which the fix kind doubles. */
    public function testPointsOrderTheRowsOfOneSeverity(): void
    {
        $range = (new VersionParser())->parseConstraints('<2.0');
        $advisories = [new Advisory('PKSA-a', null, null, null, 'moderate', null, $range), new Advisory('PKSA-b', null, null, null, 'moderate', null, $range)];
        $update = Fix::to(new Candidate(new StableRelease('2.0.0.0', '2.0.0', null, null, false), '2', Fix::UPDATE, []), '2.0.0', '1', new PhpFloor(null));
        $facts = (new PackageFacts(F::package(['version' => '1.0.0']), null, null, $advisories))
            ->withFixes(new PackageFixes(['PKSA-a' => $update, 'PKSA-b' => Fix::none()], [], null, null, null));

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame(['PKSA-b' => 16, 'PKSA-a' => 8], array_column(JsonPath::arrayAt(JsonPath::decoded($signal->data()), ['advisories']), 'points', 'id'));
    }

    public function testAFixHeldByALinkNamesTheHolder(): void
    {
        $holder = new Holder(Holder::PACKAGE, 'acme/b', '2.0.0', Holder::REQUIRE, '^1.0');
        $upgrade = Fix::to(new Candidate(new StableRelease('2.0.0.0', '2.0.0', null, null, false), '2', Fix::UPGRADE, [$holder]), '2.0.0', '1', new PhpFloor(null));
        $facts = (new PackageFacts(F::package(['version' => '1.0.0']), null, null, [new Advisory('PKSA-a', null, null, null, 'high', null, (new VersionParser())->parseConstraints('<2.0'))]))
            ->withFixes(new PackageFixes(['PKSA-a' => $upgrade], [], null, null, null));

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame([JsonPath::decoded($holder)], JsonPath::arrayAt(JsonPath::decoded($signal->data()), ['advisories', 0, 'fix', 'held_by']));
    }

    public function testAnIdThatLooksLikeANumberStaysAString(): void
    {
        $signal = (new AdvisoryRule())->evaluate(new PackageFacts(F::package(['version' => '1.0.0']), null, null, [$this->advisory('42')]));

        self::assertNotNull($signal);
        self::assertSame('42', JsonPath::arrayAt(JsonPath::decoded($signal->data()), ['advisories', 0])['id']);
    }

    public function testTheDataIsCompleteWhenEveryFeedAnswered(): void
    {
        $coverage = static fn (?string $reason): AdvisoryNameCoverage => new AdvisoryNameCoverage([], 0, $reason);
        $facts = static fn (?string $reason): PackageFacts => new PackageFacts(F::package(['version' => '1.0.0']), null, null, [new Advisory('PKSA-a', null, null, null, 'high', null)], null, null, $coverage($reason));

        $complete = (new AdvisoryRule())->evaluate($facts(null));
        $partial = (new AdvisoryRule())->evaluate($facts(AdvisoryCoverage::LOOKUP_FAILED));

        self::assertNotNull($complete);
        self::assertNotNull($partial);
        self::assertTrue(JsonPath::decoded($complete->data())['complete']);
        self::assertFalse(JsonPath::decoded($partial->data())['complete']);
    }

    /**
     * @dataProvider titles
     */
    #[DataProvider('titles')]
    public function testATitleDropsOnlyALeadingCveAndColon(?string $cve, string $title, string $expected): void
    {
        self::assertSame($expected, AdvisoryRule::title($cve, $title));
    }

    /** @return iterable<string, array{?string, string, string}> */
    public static function titles(): iterable
    {
        yield 'a leading CVE and colon' => ['CVE-2024-1', 'CVE-2024-1:  a   title ', 'a title'];
        yield 'a leading CVE without a colon' => ['CVE-2024-1', 'CVE-2024-1 a title', 'CVE-2024-1 a title'];
        yield 'no CVE' => [null, 'a title', 'a title'];
    }

}
