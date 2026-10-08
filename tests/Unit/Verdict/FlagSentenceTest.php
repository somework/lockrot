<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Signal\Signal;
use Lockrot\Verdict\FlagSentence;
use Lockrot\Verdict\FlagSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `flags[].summary`: the sentence is the output, so the tests compare it whole. */
final class FlagSentenceTest extends TestCase
{
    private const S8 = [
        'branch' => '1.x', 'branch_last_release' => '2019-03-02T00:00:00+00:00', 'years' => 7.5, 'dated_by' => null,
        'newest_branch' => '3.x', 'newest_version' => '3.4.1', 'newest_release' => '2026-06-01T00:00:00+00:00',
        'newest_php' => '>=8.5', 'newest_within_reach' => false, 'floor_source' => 'target', 'floor_php' => '8.4',
        'reachable_branch' => '2.x', 'reachable_version' => '2.9.0', 'reachable_release' => '2026-03-01T00:00:00+00:00',
    ];

    /**
     * @param array<string, mixed> $change
     *
     * @dataProvider leftBehind
     */
    #[DataProvider('leftBehind')]
    public function testTheLeftBehindSentenceNamesWhatIsWithinReach(array $change, string $sentence): void
    {
        self::assertSame($sentence, FlagSentence::maintenance(FlagSet::LEFT_BEHIND, [Signal::S8 => array_merge(self::S8, $change)]));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function leftBehind(): iterable
    {
        $head = 'branch 1.x last released 2019-03-02 (7.5 years ago)';
        yield 'the newest branch within reach' => [['newest_within_reach' => true], $head.'; 3.x released 3.4.1 (2026-06-01)'];
        yield 'a reachable branch below the newest' => [[], $head."; 3.x released 3.4.1 (2026-06-01), needs php >=8.5 above the target's php 8.4; 2.x released 2.9.0 (2026-03-01)"];
        yield 'the project floor holds it' => [['floor_source' => 'project', 'floor_php' => '8.2'], $head."; 3.x released 3.4.1 (2026-06-01), needs php >=8.5 above the project's php 8.2; 2.x released 2.9.0 (2026-03-01)"];
        yield 'no branch within reach' => [['reachable_branch' => null], $head."; 3.x released 3.4.1 (2026-06-01), needs php >=8.5 above the target's php 8.4; no releasing branch within reach"];
        yield 'no word on the reach of the newest' => [['newest_within_reach' => null], $head."; 3.x released 3.4.1 (2026-06-01), needs php >=8.5 above the target's php 8.4; 2.x released 2.9.0 (2026-03-01)"];
        yield 'a release dated by the monorepo' => [['newest_within_reach' => true, 'dated_by' => 'acme/monorepo'], 'branch 1.x last released 2019-03-02 (7.5 years ago, dated by acme/monorepo); 3.x released 3.4.1 (2026-06-01)'];
    }

    public function testTheSilentSentenceReadsBothDates(): void
    {
        $data = [
            Signal::S2 => ['last_release' => '2021-05-05T00:00:00+00:00', 'years' => 5.4, 'dated_by' => null],
            Signal::S4 => ['last_push' => '2022-01-10T00:00:00+00:00', 'years' => 4.7, 'activity' => 'commit'],
        ];

        self::assertSame('last release 2021-05-05 (5.4 years ago); last commit 2022-01-10 (4.7 years ago)', FlagSentence::maintenance(FlagSet::SILENT, $data));
    }

    /**
     * @param list<array<string, mixed>>                                   $rows
     * @param array<string, int>                                           $counts
     * @param ?array{fixed: int, unknown: int, of: int, fix_kind: ?string} $installed
     *
     * @dataProvider vulnerable
     */
    #[DataProvider('vulnerable')]
    public function testTheVulnerableSentence(array $rows, array $counts, string $worst, string $deciding, ?string $branch, ?array $installed, string $sentence): void
    {
        self::assertSame($sentence, FlagSentence::vulnerable($rows, $counts, $worst, $deciding, $branch, $installed));
    }

    /** @return iterable<string, array{list<array<string, mixed>>, array<string, int>, string, string, ?string, ?array{fixed: int, unknown: int, of: int, fix_kind: ?string}, string}> */
    public static function vulnerable(): iterable
    {
        $row = static fn (string $id, ?string $cve, bool $deciding): array => ['id' => $id, 'cve' => $cve, 'title' => 'title of '.$id, 'deciding' => $deciding];
        $counts = ['critical' => 0, 'high' => 1, 'medium' => 1, 'unrated' => 0, 'low' => 0];
        $two = [$row('PKSA-a', 'CVE-1', false), $row('PKSA-b', null, true)];
        $one = [$row('PKSA-a', 'CVE-1', true)];
        $oneCount = ['critical' => 0, 'high' => 1, 'medium' => 0, 'unrated' => 0, 'low' => 0];

        yield 'the deciding advisory is the worst' => [$two, $counts, 'high', 'high', null, null, '2 advisories: 1 high, 1 medium; worst: PKSA-b title of PKSA-b'];
        yield 'the deciding advisory weighs most' => [$two, $counts, 'high', 'medium', null, null, '2 advisories: 1 high, 1 medium; weighs most: PKSA-b title of PKSA-b'];
        yield 'one advisory, none fixed on the branch' => [$one, $oneCount, 'high', 'high', '1.x', ['fixed' => 0, 'unknown' => 0, 'of' => 1, 'fix_kind' => null], '1 advisory: 1 high — none fixed on 1.x; advisory: CVE-1 title of PKSA-a'];
        yield 'all fixed by an update' => [$one, $oneCount, 'high', 'high', '1.x', ['fixed' => 1, 'unknown' => 0, 'of' => 1, 'fix_kind' => 'update'], '1 advisory: 1 high — all fixed on 1.x; advisory: CVE-1 title of PKSA-a'];
        yield 'all fixed by an upgrade' => [$one, $oneCount, 'high', 'high', '1.x', ['fixed' => 1, 'unknown' => 0, 'of' => 1, 'fix_kind' => 'upgrade'], '1 advisory: 1 high — all fixed on 1.x (upgrade); advisory: CVE-1 title of PKSA-a'];
        yield 'some fixed by a kind of a later release' => [$two, $counts, 'high', 'high', '2.x', ['fixed' => 1, 'unknown' => 0, 'of' => 2, 'fix_kind' => 'acme:patch'], '2 advisories: 1 high, 1 medium — 1 of 2 fixed on 2.x (acme:patch); worst: PKSA-b title of PKSA-b'];
        yield 'all fixed by a php floor raise' => [$one, $oneCount, 'high', 'high', '1.x', ['fixed' => 1, 'unknown' => 0, 'of' => 1, 'fix_kind' => 'raise-php'], '1 advisory: 1 high — all fixed on 1.x (php floor raise); advisory: CVE-1 title of PKSA-a'];
        yield 'some not known' => [$two, $counts, 'high', 'high', '2.x', ['fixed' => 1, 'unknown' => 1, 'of' => 2, 'fix_kind' => 'blocked'], '2 advisories: 1 high, 1 medium — 1 of 2 fixed on 2.x (blocked); 1 not known; worst: PKSA-b title of PKSA-b'];
        yield 'none known' => [$one, $oneCount, 'high', 'high', '1.x', ['fixed' => 0, 'unknown' => 1, 'of' => 1, 'fix_kind' => null], '1 advisory: 1 high — fix not known on 1.x; advisory: CVE-1 title of PKSA-a'];
        yield 'a branch without its row' => [$one, $oneCount, 'high', 'high', '1.x', null, '1 advisory: 1 high; advisory: CVE-1 title of PKSA-a'];
        yield 'counts that leave severities out' => [$one, ['high' => 1], 'high', 'high', null, null, '1 advisory: 1 high; advisory: CVE-1 title of PKSA-a'];
        yield 'rows without the deciding key name the first' => [[['id' => 'PKSA-a', 'cve' => 'CVE-1', 'title' => 'a'], ['id' => 'PKSA-b', 'cve' => 'CVE-2', 'title' => 'b']], $counts, 'high', 'high', null, null, '2 advisories: 1 high, 1 medium; worst: CVE-1 a'];
        yield 'a branch row of a snapshot' => [$one, $oneCount, 'high', 'high', null, ['fixed' => 0, 'unknown' => 1, 'of' => 1, 'fix_kind' => null], '1 advisory: 1 high; advisory: CVE-1 title of PKSA-a'];
        yield 'fixed with no class, from the S9 rows' => [$two, $counts, 'high', 'high', '2.x', ['fixed' => 1, 'unknown' => 0, 'of' => 2, 'fix_kind' => null], '2 advisories: 1 high, 1 medium — 1 of 2 fixed on 2.x; worst: PKSA-b title of PKSA-b'];
        yield 'no row marked deciding names the first' => [[$row('PKSA-a', 'CVE-1', false), $row('PKSA-b', null, false)], $counts, 'high', 'high', null, null, '2 advisories: 1 high, 1 medium; worst: CVE-1 title of PKSA-a'];
    }
}
