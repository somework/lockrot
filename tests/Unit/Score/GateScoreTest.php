<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Score;

use Lockrot\Score\CountedAdvisory;
use Lockrot\Score\Coverage;
use Lockrot\Score\GateScore;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\Score;
use Lockrot\Verdict\ScoreModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The gate rows of SPEC §6.4: `gate = min(score, 2 × new)`. */
final class GateScoreTest extends TestCase
{
    /**
     * flags, advisories as [severity, fix kind, known], reach, dev, recorded lead => score, new, gate, limited_by.
     *
     * @return iterable<string, array{list<string>, list<array{string, string, bool}>, string, bool, ?string, int, int, int, string}>
     */
    public static function gateRows(): iterable
    {
        yield 'left-behind, a new high advisory' => [['left-behind'], [['high', 'upgrade', false]], 'direct', false, 'left-behind', 32, 16, 32, 'equal'];
        yield 'left-behind, a new medium advisory' => [['left-behind'], [['medium', 'update', false]], 'direct', false, 'left-behind', 24, 8, 16, 'cap'];
        yield 'left-behind, a new low advisory' => [['left-behind'], [['low', 'update', false]], 'direct', false, 'left-behind', 18, 2, 4, 'cap'];
        yield 'stale, the package goes silent' => [['silent'], [], 'direct', false, 'stale', 32, 32, 32, 'score'];
        yield 'stale, old-promise fires' => [['old-promise', 'stale'], [], 'direct', false, 'stale', 18, 16, 18, 'score'];
        yield 'old-promise, left-behind fires' => [['left-behind', 'old-promise'], [], 'direct', false, 'old-promise', 20, 16, 20, 'score'];
        yield 'old-promise, stale fires by ageing' => [['old-promise', 'stale'], [], 'direct', false, 'old-promise', 18, 0, 0, 'cap'];
        yield 'old-promise, two flags above it fire' => [['abandoned', 'left-behind', 'old-promise'], [], 'direct', false, 'old-promise', 40, 36, 40, 'score'];
        yield 'a transitive known critical, old-promise and a new medium' => [['old-promise', 'stale'], [['critical', 'update', true], ['medium', 'update', false]], 'transitive', false, 'stale', 41, 16, 32, 'cap'];
        yield 'an advisory-only entry, transitive dev, rounded down' => [['left-behind', 'stale'], [], 'transitive', true, null, 4, 4, 4, 'score'];
        yield 'everything known' => [['abandoned', 'old-promise'], [['high', 'update', true]], 'direct', false, 'abandoned', 52, 0, 0, 'cap'];
        yield 'score 0 under an entry' => [[], [], 'direct', false, 'left-behind', 0, 0, 0, 'cap'];
    }

    /** @param list<array{string, string, bool}> $advisories */
    #[DataProvider('gateRows')]
    public function testTheGateScoreIsTheScoreBoundByTwiceWhatTheNewFactsAdd(array $flags, array $advisories, string $reach, bool $dev, ?string $lead, int $score, int $new, int $gate, string $limitedBy): void
    {
        $counted = [];
        $unknown = [];
        foreach ($advisories as $i => [$severity, $fix, $known]) {
            $counted[] = new CountedAdvisory('A'.$i, $severity, $fix);
            if (!$known) {
                $unknown[] = end($counted);
            }
        }
        $gateScore = GateScore::of(Score::compute($flags, $counted, $reach, $dev), Coverage::under($lead)->uncovered($flags), $unknown);

        self::assertSame([$score, $new, $gate, 2 * $new, $limitedBy], [$gateScore->scoreTotal(), $gateScore->newScore()->total(), $gateScore->total(), $gateScore->cap(), $gateScore->limitedBy()]);
        self::assertSame(ScoreModel::band($gate), $gateScore->verdict());
    }

    public function testTheBasisWritesTheNewFactsInTheScoreShape(): void
    {
        $known = new CountedAdvisory('PKSA-known', 'critical', 'update');
        $new = new CountedAdvisory('PKSA-4wjj-gy1p-ft3r', 'high', 'upgrade');
        $gate = GateScore::of(Score::compute(['left-behind', 'stale'], [$known, $new], 'direct', false), [], [$new]);

        self::assertSame([
            'score' => 32,
            'verdict' => 'critical',
            'cap' => 32,
            'limited_by' => 'cap',
            'new' => [
                'total' => 16,
                'exact' => 16,
                'rounded_down' => false,
                'terms' => [['part' => 'security', 'flag' => 'vulnerable', 'role' => 'security', 'advisory' => 'PKSA-4wjj-gy1p-ft3r', 'severity' => 'high', 'fix_kind' => 'upgrade', 'weight' => 16, 'multiplier' => 1, 'points' => 16, 'contribution' => 16]],
                'modifiers' => [],
            ],
        ], JsonPath::decoded($gate));
        self::assertSame([], $gate->uncoveredFlags());
        self::assertSame([$new], $gate->unknownAdvisories());
    }

    public function testARoundedDownNewPartWritesItsModifiers(): void
    {
        $gate = GateScore::of(Score::compute(['left-behind', 'stale'], [], 'transitive', true), ['left-behind', 'stale'], []);
        $basis = JsonPath::decoded($gate);

        self::assertSame(['total' => 4, 'exact' => 4.5, 'rounded_down' => true], \array_slice(self::map($basis['new']), 0, 3));
        self::assertSame([
            ['reason' => 'transitive', 'applies_to' => 'maintenance', 'divide_by' => 2, 'before' => 18, 'after' => 9],
            ['reason' => 'dev', 'applies_to' => 'total', 'divide_by' => 2, 'before' => 9, 'after' => 4.5],
        ], self::map($basis['new'])['modifiers']);
        self::assertSame(['left-behind', 'stale'], $gate->uncoveredFlags());
    }

    public function testAKnownFindingHasNoTermsAndNoModifiers(): void
    {
        $basis = JsonPath::decoded(GateScore::of(Score::compute(['stale'], [], 'transitive', true), [], []));

        self::assertSame(['score' => 0, 'verdict' => null, 'cap' => 0, 'limited_by' => 'cap', 'new' => ['total' => 0, 'exact' => 0, 'rounded_down' => false, 'terms' => [], 'modifiers' => []]], $basis);
    }

    /**
     * @param mixed $value
     *
     * @return array<string, mixed>
     */
    private static function map($value): array
    {
        self::assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }
}
