<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Baseline;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\Baseline;
use Lockrot\Exception\ConfigException;
use Lockrot\Tests\Support\BaselineEntries;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\JsonPath;
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
            $findings[] = (new FindingBuilder())->withPackage($package)->withVersion($version)->withVerdict($verdict)->withChain([$package])->withDataDate($at)->build();
        }

        return new Report($findings, [], $at, \count($findings), 0);
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

        // The report rows are already alphabetical, but of() takes entries in any order, so the
        // sort is what makes a regenerated baseline's diff reviewable.
        $unordered = BaselineEntries::baseline([
            BaselineEntries::of('zzz/last', '1.0.0', Verdict::STALE, '2026-01-01'),
            BaselineEntries::of('acme/first', '1.0.0', Verdict::ABANDONED, '2026-01-01'),
            BaselineEntries::of('mid/middle', '1.0.0', Verdict::SILENT, '2026-01-01'),
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
        $previous = BaselineEntries::baseline(
            [BaselineEntries::of('acme/abandoned', '1.0.0', Verdict::STALE, '2025-03-01')],
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
        self::assertSame(Verdict::ABANDONED, $entry->recordedLead());
        self::assertSame(['abandoned' => '2026-09-14'], $entry->flags(), 'a flag the entry did not hold is known from today');
    }

    public function testToArrayShape(): void
    {
        $baseline = BaselineEntries::baseline([BaselineEntries::of('acme/abandoned', '1.0.0', Verdict::ABANDONED, '2026-01-15')], self::AT);

        self::assertSame([
            'lockrot' => ['version' => Version::STRING, 'schema' => Baseline::SCHEMA],
            'generated_at' => self::AT,
            'target_php' => $baseline->targetPhp(),
            'target_php_source' => 'runtime',
            'score_model_id' => 1,
            'fix_model' => 1,
            'findings' => [
                'acme/abandoned' => ['version' => '1.0.0', 'first_seen' => '2026-01-15', 'flags' => [
                    'abandoned' => ['known_since' => '2026-01-15'], 'silent' => ['known_since' => '2026-01-15'], 'pinned' => ['known_since' => '2026-01-15'],
                    'left-behind' => ['known_since' => '2026-01-15'], 'old-promise' => ['known_since' => '2026-01-15'], 'stale' => ['known_since' => '2026-01-15'],
                    'capped' => ['known_since' => '2026-01-15'],
                ]],
            ],
        ], JsonPath::decoded($baseline->toArray()));
    }

    public function testRoundTripThroughFromArray(): void
    {
        $original = Baseline::fromReport($this->report([
            ['acme/abandoned', '1.0.0', Verdict::ABANDONED],
            ['acme/silent', '2.0.8', Verdict::SILENT],
        ]));

        self::assertSame(JsonPath::decoded($original->toArray()), JsonPath::decoded(Baseline::fromArray(self::stringKeys(['$schema' => 'https://lockrot.dev/schema/baseline-2.json'] + JsonPath::decoded($original->toArray())))->toArray()));
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
        self::assertSame(Verdict::SILENT, $entry->recordedLead());
        self::assertNotNull($entry->advisory('PKSA-0000-0000-0001'));
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
     * json_decode(..., true) turns an object key that looks like an integer into an int key. A
     * package name holds a `/`, so the schema refuses the key.
     */
    public function testANumericPackageKeyIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('findings: The property 123 is not defined');
        Baseline::fromArray(self::root() + [
            'findings' => [123 => ['version' => '1.0.0', 'first_seen' => '2026-01-15', 'flags' => ['stale' => ['known_since' => '2026-01-15']]]],
        ]);
    }

    /** A key is the package's Composer name: a hand-edited spelling covers the same package. */
    public function testAKeyIsReadAsTheComposerName(): void
    {
        $entry = ['version' => '1.0.0', 'first_seen' => '2026-01-15', 'flags' => ['stale' => ['known_since' => '2026-01-15']]];
        $baseline = Baseline::fromArray(self::root() + ['findings' => ['Acme/Legacy' => $entry]]);

        self::assertSame(['acme/legacy'], $baseline->packages());
        self::assertNotNull($baseline->entryFor('acme/legacy'));
    }

    public function testTwoKeysOfOneNameAreRefused(): void
    {
        $entry = ['version' => '1.0.0', 'first_seen' => '2026-01-15', 'flags' => ['stale' => ['known_since' => '2026-01-15']]];

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('baseline file is invalid: findings lists acme/legacy twice, as Acme/Legacy and acme/legacy');
        Baseline::fromArray(self::root() + ['findings' => ['Acme/Legacy' => $entry, 'acme/legacy' => $entry]]);
    }

    /**
     * Any `lockrot.schema` but the integer 2, or none, fails against baseline-2 with the schema
     * error. BaselineFile refuses the integer 1 before this reader runs.
     *
     * @return iterable<string, array{mixed, string}> the `lockrot` envelope, and what the message names
     */
    public static function envelopesNamingNoShippedNumber(): iterable
    {
        yield 'a number lockrot ships no schema for' => [['version' => '0.14.0', 'schema' => 3], 'lockrot.schema'];
        yield 'a number below the first' => [['version' => '0.14.0', 'schema' => 0], 'lockrot.schema'];
        yield 'the number as a string' => [['version' => '0.13.0', 'schema' => '1'], 'lockrot.schema'];
        yield 'no number' => [['version' => '0.13.0'], 'lockrot.schema'];
        yield 'no envelope object' => ['0.13.0', 'lockrot'];
    }

    /**
     * @param mixed $envelope
     *
     * @dataProvider envelopesNamingNoShippedNumber
     */
    #[DataProvider('envelopesNamingNoShippedNumber')]
    public function testANumberOtherThan2FailsAgainstBaseline2($envelope, string $expectedMessage): void
    {
        $thrown = null;
        try {
            Baseline::fromArray(['lockrot' => $envelope] + self::root() + ['findings' => []]);
        } catch (ConfigException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(ConfigException::class, $thrown);
        self::assertStringStartsWith("baseline file is invalid:\n  - ".$expectedMessage, $thrown->getMessage());
    }

    /** @return iterable<string, array{string, string}> */
    public static function schemaInvalidFixtures(): iterable
    {
        yield 'a flag that is not a maintenance flag' => ['unknown-verdict.json', 'flags'];
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

    /** @return array<string, mixed> the root of a baseline-2 file without `findings` */
    private static function root(): array
    {
        return ['$schema' => 'https://lockrot.dev/schema/baseline-2.json', 'lockrot' => ['version' => '0.14.0', 'schema' => 2], 'generated_at' => '2026-09-14T00:00:00+00:00', 'target_php' => '8.4', 'target_php_source' => 'option', 'score_model_id' => 1, 'fix_model' => 1];
    }

    /**
     * @param array<mixed, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
