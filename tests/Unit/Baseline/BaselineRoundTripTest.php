<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Baseline;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineSchema;
use Lockrot\Baseline\RecordedAdvisory;
use Lockrot\Score\CountedAdvisory;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\ScoreSweep;
use Lockrot\Verdict\FlagSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A baseline-2 entry written to a file and read back: the schema accepts what the writer writes,
 * and the reader gets the same facts. `json_decode(…, true)` turns `{}` into `[]`, so the writer
 * omits an empty map.
 */
final class BaselineRoundTripTest extends TestCase
{
    /**
     * The counted flags and advisories of the entry.
     *
     * @return iterable<string, array{list<string>, list<array{string, string, string}>}>
     */
    public static function entries(): iterable
    {
        yield 'maintenance only' => [['left-behind', 'stale'], []];
        yield 'advisories only, with the ids 123 and 0' => [[], [['123', 'high', 'update'], ['0', 'low', 'none']]];
        yield 'flags and advisories' => [['old-promise'], [['PKSA-1', 'critical', 'raise-php']]];
    }

    /**
     * @param list<string>                        $flags
     * @param list<array{string, string, string}> $advisories
     *
     * @dataProvider entries
     */
    #[DataProvider('entries')]
    public function testAnEntryIsReadBackAsItWasWritten(array $flags, array $advisories): void
    {
        $signals = [];
        foreach ($flags as $flag) {
            $signals = array_merge($signals, ScoreSweep::signalsOf($flag));
        }
        $counted = array_map(static fn (array $a): CountedAdvisory => new CountedAdvisory($a[0], $a[1], $a[2]), $advisories);
        $finding = (new FindingBuilder())->withPackage('acme/pkg')->withChain(['acme/pkg'])->withFlags(FlagSet::fromSignals($signals, null, $counted))->build();
        $written = Baseline::fromReport(new Report([$finding], [], new \DateTimeImmutable('2026-03-01T00:00:00+00:00'), 1, 0));

        $file = json_decode((string) json_encode(['$schema' => 'https://lockrot.dev/schema/baseline-2.json'] + $written->toArray()), true);
        self::assertIsArray($file);
        /** @var array<string, mixed> $file */
        BaselineSchema::validate($file);
        $entry = Baseline::fromArray($file)->entryFor('acme/pkg');

        self::assertNotNull($entry);
        self::assertSame($flags, array_keys($entry->flags()));
        $read = array_map(static fn (RecordedAdvisory $a): array => [$a->id(), $a->severity(), $a->fixKind()], $entry->advisories());
        $expected = $advisories;
        usort($expected, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        self::assertSame($expected, $read);
    }
}
