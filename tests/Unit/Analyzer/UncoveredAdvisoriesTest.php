<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Analyzer\UncoveredAdvisories;
use Lockrot\Score\CountedAdvisory;
use Lockrot\Signal\Data\AdvisoryData;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FlagSet;
use PHPUnit\Framework\TestCase;

/** docs/notes.md, `ignore_does_not_cover_advisories`: which `ignore[]` entries the note names. */
final class UncoveredAdvisoriesTest extends TestCase
{
    public function testOnlyAProjectEntryOverCountedAdvisoriesIsNamedWithItsIdsSorted(): void
    {
        $project = static fn (string $package): AllowlistEntry => new AllowlistEntry($package, null, 'kept', null, AllowlistEntry::BY_PROJECT);
        $findings = [
            self::finding('d/project', $project('d/*'), [['B', 'critical'], ['A', 'low']]),
            self::finding('c/no-advisory', $project('c/no-advisory'), []),
            self::finding('b/builtin', new AllowlistEntry('b/builtin', null, 'kept', null, AllowlistEntry::BY_BUILTIN), [['C', 'critical']]),
            self::finding('a/no-entry', null, [['D', 'critical'], ['E', 'critical']]),
        ];

        self::assertSame([['package' => 'd/project', 'pattern' => 'd/*', 'advisories' => ['A', 'B']]], JsonPath::decoded(UncoveredAdvisories::of($findings)));
    }

    public function testTheEntriesComeInReportOrder(): void
    {
        $project = static fn (string $package): AllowlistEntry => new AllowlistEntry($package, null, 'kept', null, AllowlistEntry::BY_PROJECT);
        $findings = [self::finding('a/low', $project('a/low'), [['L', 'low']]), self::finding('z/critical', $project('z/critical'), [['C', 'critical']])];

        self::assertSame(['z/critical', 'a/low'], array_map(static fn (UncoveredAdvisories $entry): string => $entry->package(), UncoveredAdvisories::of($findings)));
    }

    /** @param list<array{0: string, 1: string}> $advisories id and severity */
    private static function finding(string $package, ?AllowlistEntry $entry, array $advisories): Finding
    {
        $counted = array_map(static fn (array $a): CountedAdvisory => new CountedAdvisory($a[0], $a[1], 'update'), $advisories);
        $rows = array_map(static fn (array $a): array => ['id' => $a[0], 'severity' => $a[1]], $advisories);
        $signals = $advisories === [] ? [] : [new Signal(Signal::LEVEL_HIGH, 'advisory', new AdvisoryData(array_map([SignalDataStub::class, 'row'], $rows), true, true))];
        $built = (new FindingBuilder())->withPackage($package)->withSignals($signals)->withChain([$package])->withFlags(FlagSet::fromSignals($signals, $entry, $counted))->build();

        return $built->withDetails(FindingBuilder::detailsOf($built, true, $entry));
    }
}
