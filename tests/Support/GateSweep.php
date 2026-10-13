<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Score\Coverage;
use Lockrot\Score\GateScore;
use Lockrot\Score\SecurityTerm;
use Lockrot\Score\Term;
use Lockrot\Verdict\Score;
use Lockrot\Verdict\ScoreModel;

/**
 * The `gate` axis of tools/score/sweep.py in PHP: one row per combination, recorded lead and known
 * advisory set, in the order of `gate_rows()`. tools/score/README.md documents the row and the
 * commands that write tests/fixtures/score/gate-rows.sample.txt and its sha256.
 *
 * @phpstan-type Inputs array{flags: list<string>, advisories: list<array{string, string}>, reach: string, dev: bool, lead: ?string, known: list<string>}
 */
final class GateSweep
{
    public const SAMPLE = __DIR__.'/../fixtures/score/gate-rows.sample.txt';
    public const SHA256 = __DIR__.'/../fixtures/score/gate-rows.txt.sha256';

    /**
     * Every input of the axis, in the order of tools/score/sweep.py.
     *
     * @return \Generator<int, Inputs>
     */
    public static function inputs(): \Generator
    {
        foreach (ScoreSweep::inputs() as $base) {
            if ($base['axis'] !== 'base') {
                continue;
            }
            $ids = array_map(static fn (int $i): string => 'A'.$i, array_keys($base['advisories']));
            foreach (array_merge([null], array_keys(ScoreModel::POINTS)) as $lead) {
                foreach (self::subsets($ids) as $known) {
                    yield ['flags' => $base['flags'], 'advisories' => $base['advisories'], 'reach' => $base['reach'], 'dev' => $base['dev'], 'lead' => $lead, 'known' => $known];
                }
            }
        }
    }

    /** @param Inputs $inputs */
    public static function row(array $inputs): string
    {
        $advisories = ScoreSweep::advisories($inputs['advisories']);
        $score = Score::compute($inputs['flags'], $advisories, $inputs['reach'], $inputs['dev']);
        $unknown = array_values(array_filter($advisories, static fn ($advisory): bool => !\in_array($advisory->id(), $inputs['known'], true)));
        $gate = GateScore::of($score, Coverage::under($inputs['lead'])->uncovered($inputs['flags']), $unknown);
        $marks = [];
        foreach (ScoreModel::GRADES as $grade) {
            if ($gate->total() < ScoreModel::floorOf($grade)) {
                $marks[] = '-';
                continue;
            }
            $facts = array_map(static fn (Term $term): string => $term instanceof SecurityTerm ? 'advisory:'.$term->advisory() : 'flag:'.$term->flag(), GateScore::termsReaching($gate->newTerms(), $grade, true));
            $marks[] = $facts === [] ? '!' : implode(',', $facts);
        }

        return implode('|', array_merge([
            'gate',
            self::join($inputs['flags']),
            self::join(array_map(static fn (array $a): string => $a[0].':'.$a[1], $inputs['advisories'])),
            $inputs['reach'],
            $inputs['dev'] ? '1' : '0',
            $inputs['lead'] ?? '-',
            self::join($inputs['known']),
            (string) $score->total(),
            (string) $gate->newScore()->exactHalves(),
            (string) $gate->newScore()->total(),
            (string) $gate->total(),
            $gate->limitedBy(),
        ], $marks));
    }

    /**
     * The inputs that fields 2 to 7 of a row give.
     *
     * @return Inputs
     */
    public static function parse(string $row): array
    {
        $field = explode('|', $row);
        $advisories = [];
        foreach ($field[2] === '-' ? [] : explode(',', $field[2]) as $advisory) {
            [$severity, $fix] = explode(':', $advisory);
            $advisories[] = [$severity, $fix];
        }

        return [
            'flags' => $field[1] === '-' ? [] : explode(',', $field[1]),
            'advisories' => $advisories,
            'reach' => $field[3],
            'dev' => $field[4] === '1',
            'lead' => $field[5] === '-' ? null : $field[5],
            'known' => $field[6] === '-' ? [] : explode(',', $field[6]),
        ];
    }

    /** @return list<string> the committed rows: every 1,000th row of the stream, the first one included */
    public static function sample(): array
    {
        $lines = file(self::SAMPLE, \FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException('cannot read '.self::SAMPLE);
        }

        return $lines;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<list<string>> every subset, by size, each in id order, as itertools.combinations gives them
     */
    private static function subsets(array $ids): array
    {
        $subsets = [[]];
        if (\count($ids) >= 1) {
            foreach ($ids as $id) {
                $subsets[] = [$id];
            }
        }
        if (\count($ids) === 2) {
            $subsets[] = $ids;
        }

        return $subsets;
    }

    /** @param list<string> $items */
    private static function join(array $items): string
    {
        return $items === [] ? '-' : implode(',', $items);
    }
}
