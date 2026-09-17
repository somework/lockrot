<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Baseline;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineEntry;
use Lockrot\Exception\ConfigException;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use Lockrot\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BaselineTest extends TestCase
{
    private const AT = '2026-09-14T00:00:00+00:00';
    private const FIXTURES = __DIR__.'/../../fixtures/baseline';

    /** @param list<array{0: string, 1: string, 2: string}> $rows package, version, verdict */
    private function report(array $rows): Report
    {
        $at = new \DateTimeImmutable(self::AT);
        $findings = [];
        foreach ($rows as [$package, $version, $verdict]) {
            $findings[] = new Finding($package, $version, $verdict, [], [$package], null, $at);
        }

        return new Report($findings, [], $at, \count($findings), 0, false);
    }

    public function testFromReportKeepsOnlyFlaggedVerdicts(): void
    {
        $baseline = Baseline::fromReport($this->report([
            ['acme/abandoned', '1.0.0', Verdict::ABANDONED],
            ['acme/stale', '2.0.0', Verdict::STALE],
            ['acme/unknown', '3.0.0', Verdict::UNKNOWN],
            ['acme/finished', '4.0.0', Verdict::FINISHED],
            ['acme/fine', '5.0.0', Verdict::OK],
        ]));

        self::assertSame(['acme/abandoned', 'acme/stale'], $baseline->packages());
        self::assertSame(2, $baseline->count());
    }

    public function testEntriesAreSortedByPackageName(): void
    {
        $baseline = Baseline::fromReport($this->report([
            ['zzz/last', '1.0.0', Verdict::STALE],
            ['acme/first', '1.0.0', Verdict::ABANDONED],
            ['mid/middle', '1.0.0', Verdict::SILENT],
        ]));

        $findings = $baseline->toArray()['findings'];
        self::assertIsArray($findings);
        self::assertSame(['acme/first', 'mid/middle', 'zzz/last'], $baseline->packages());
        self::assertSame(['acme/first', 'mid/middle', 'zzz/last'], array_keys($findings));

        // Report order already happens to be alphabetical above; of() takes entries in any order,
        // so the sort is what makes a regenerated baseline's diff reviewable.
        $unordered = Baseline::of([
            new BaselineEntry('zzz/last', '1.0.0', Verdict::STALE, '2026-01-01'),
            new BaselineEntry('acme/first', '1.0.0', Verdict::ABANDONED, '2026-01-01'),
            new BaselineEntry('mid/middle', '1.0.0', Verdict::SILENT, '2026-01-01'),
        ], self::AT);
        self::assertSame(['acme/first', 'mid/middle', 'zzz/last'], $unordered->packages());
    }

    public function testFirstSeenIsTheReportDateWhenThereIsNoPreviousEntry(): void
    {
        $baseline = Baseline::fromReport($this->report([['acme/abandoned', '1.0.0', Verdict::ABANDONED]]));
        $entry = $baseline->entryFor('acme/abandoned');

        self::assertNotNull($entry);
        self::assertSame('2026-09-14', $entry->firstSeen());
    }

    public function testFirstSeenIsCarriedOverFromThePreviousBaselineEvenWhenVersionAndVerdictChange(): void
    {
        $previous = Baseline::of(
            [new BaselineEntry('acme/abandoned', '1.0.0', Verdict::STALE, '2025-03-01')],
            self::AT
        );

        $baseline = Baseline::fromReport(
            $this->report([['acme/abandoned', '2.0.0', Verdict::ABANDONED]]),
            $previous
        );
        $entry = $baseline->entryFor('acme/abandoned');

        self::assertNotNull($entry);
        self::assertSame('2025-03-01', $entry->firstSeen());
        self::assertSame('2.0.0', $entry->version());
        self::assertSame(Verdict::ABANDONED, $entry->verdict());
    }

    public function testToArrayShape(): void
    {
        $baseline = Baseline::of([new BaselineEntry('acme/abandoned', '1.0.0', Verdict::ABANDONED, '2026-01-15')], self::AT);

        self::assertSame([
            'lockrot' => ['version' => Version::STRING, 'schema' => Baseline::SCHEMA],
            'generated_at' => self::AT,
            'findings' => [
                'acme/abandoned' => ['version' => '1.0.0', 'verdict' => Verdict::ABANDONED, 'first_seen' => '2026-01-15'],
            ],
        ], $baseline->toArray());
    }

    public function testRoundTripThroughFromArray(): void
    {
        $original = Baseline::fromReport($this->report([
            ['acme/abandoned', '1.0.0', Verdict::ABANDONED],
            ['acme/silent', '2.0.8', Verdict::SILENT],
        ]));

        self::assertSame($original->toArray(), Baseline::fromArray($original->toArray())->toArray());
    }

    public function testFromArrayReadsAFixture(): void
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents(self::FIXTURES.'/valid.json'), true);
        $baseline = Baseline::fromArray($data);

        self::assertSame(['acme/abandoned', 'acme/silent'], $baseline->packages());
        $entry = $baseline->entryFor('acme/silent');
        self::assertNotNull($entry);
        self::assertSame('2.0.8', $entry->version());
        self::assertSame(Verdict::SILENT, $entry->verdict());
        self::assertSame('2026-02-20', $entry->firstSeen());
        self::assertNull($baseline->entryFor('acme/absent'));
    }

    public function testAnEmptyFindingsObjectIsAccepted(): void
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents(self::FIXTURES.'/empty.json'), true);

        self::assertSame(0, Baseline::fromArray($data)->count());
    }

    /**
     * @dataProvider schemaInvalidFixtures
     */
    #[DataProvider('schemaInvalidFixtures')]
    public function testSchemaInvalidBaselinesAreRejected(string $fixture, string $expectedMessage): void
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents(self::FIXTURES.'/'.$fixture), true);

        $thrown = null;
        try {
            Baseline::fromArray($data);
        } catch (ConfigException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(ConfigException::class, $thrown);
        self::assertStringStartsWith(
            "baseline file is invalid:\n  - ",
            $thrown->getMessage(),
            'the reader says what file it is talking about before listing the validator findings'
        );
        self::assertStringContainsString($expectedMessage, $thrown->getMessage());
    }

    /**
     * json_decode(..., true) turns an object key that looks like an integer into an int key, which
     * the schema cannot see. A package name is never numeric, so such a document is not a baseline.
     */
    public function testANumericPackageKeyIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('findings must map package names to objects');
        Baseline::fromArray([
            'lockrot' => ['version' => '0.1.0', 'schema' => Baseline::SCHEMA],
            'findings' => [123 => ['version' => '1.0.0', 'verdict' => Verdict::STALE, 'first_seen' => '2026-01-15']],
        ]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function schemaInvalidFixtures(): iterable
    {
        yield 'a verdict outside the flagged enum' => ['unknown-verdict.json', 'verdict'];
        yield 'an entry without first_seen' => ['missing-first-seen.json', 'first_seen'];
        yield 'findings that is not an object' => ['findings-not-an-object.json', 'findings'];
    }

    public function testWordingAvoidsBannedTerms(): void
    {
        $baseline = Baseline::fromReport($this->report([
            ['acme/abandoned', '1.0.0', Verdict::ABANDONED],
            ['acme/silent', '2.0.0', Verdict::SILENT],
            ['acme/pinned', '3.0.0', Verdict::PINNED],
            ['acme/old', '4.0.0', Verdict::OLD_PROMISE],
            ['acme/stale', '5.0.0', Verdict::STALE],
        ]));
        $json = strtolower((string) json_encode($baseline->toArray()));

        foreach (['vulnerable', 'broken', 'insecure', 'dead'] as $banned) {
            self::assertStringNotContainsString($banned, $json);
        }
    }
}
