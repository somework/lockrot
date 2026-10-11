<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Legacy;

use Lockrot\Legacy\AdvisoryFacts013;
use Lockrot\Legacy\NoFix013;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** report-1's `no_fix_expected`: docs/schema.md#advisories-with-no-fix-expected. */
final class AdvisoryFacts013Test extends TestCase
{
    /**
     * @param list<array<string, mixed>>                $rows
     * @param list<array{id: string, reason: string}> $expected
     *
     * @dataProvider noFix
     */
    #[DataProvider('noFix')]
    public function testItNamesEachAdvisoryWithNoFixExpectedAndTheFirstReasonThatApplies(string $verdict, array $rows, bool $releasesRead, array $expected): void
    {
        self::assertSame($expected, SignalDataStub::legacy($rows, $releasesRead)->noFixExpected($verdict));
    }

    /** @return iterable<string, array{string, list<array<string, mixed>>, bool, list<array{id: string, reason: string}>}> */
    public static function noFix(): iterable
    {
        $ranged = ['id' => 'PKSA-a', 'affected_versions' => '<2.0'];
        yield 'a release outside the range fixes it' => [Verdict::ABANDONED, [['id' => 'PKSA-a', 'fixed_by' => '2.0.0']], true, []];
        yield 'the releases were not read' => [Verdict::SILENT, [$ranged], false, [['id' => 'PKSA-a', 'reason' => NoFix013::RELEASES_UNKNOWN]]];
        yield 'no affected range' => [Verdict::SILENT, [['id' => 'PKSA-a']], true, [['id' => 'PKSA-a', 'reason' => NoFix013::AFFECTED_RANGE_UNKNOWN]]];
        yield 'no release fixes it' => [Verdict::ABANDONED, [$ranged], true, [['id' => 'PKSA-a', 'reason' => NoFix013::NO_RELEASE_FIXES]]];
        yield 'left behind, fixed on another branch' => [Verdict::LEFT_BEHIND, [['id' => 'PKSA-a', 'fixed_by' => '3.0.0']], false, [['id' => 'PKSA-a', 'reason' => NoFix013::NOT_ON_INSTALLED_BRANCH]]];
        yield 'left behind, fixed nowhere' => [Verdict::LEFT_BEHIND, [['id' => 'PKSA-a']], true, [['id' => 'PKSA-a', 'reason' => NoFix013::AFFECTED_RANGE_UNKNOWN]]];
        yield 'left behind, fixed on its own branch' => [Verdict::LEFT_BEHIND, [['id' => 'PKSA-a', 'fixed_by' => '1.9.0', 'fixed_on_branch' => true]], true, []];
        yield 'each row in order' => [Verdict::ABANDONED, [['id' => 'PKSA-b'], ['id' => 'PKSA-c', 'fixed_by' => '2.0.0'], $ranged], true, [['id' => 'PKSA-b', 'reason' => NoFix013::AFFECTED_RANGE_UNKNOWN], ['id' => 'PKSA-a', 'reason' => NoFix013::NO_RELEASE_FIXES]]];
    }

    public function testNoAdvisoryNamesNone(): void
    {
        self::assertSame([], AdvisoryFacts013::none()->noFixExpected(Verdict::ABANDONED));
    }
}
