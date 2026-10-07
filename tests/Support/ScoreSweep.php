<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Score\ScoreBasis;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\ScoreModel;

/**
 * The sweep of score model 1 in PHP: the enumeration that tools/score/sweep.py walks, in its order, and
 * the canonical row of each evaluated score. tools/score/sweep.py documents the row's 14 fields.
 * tests/fixtures/score/sweep-rows.txt.gz holds the rows that sweep.py prints. Regenerate it with:
 *
 *     python3 tools/score/sweep.py > rows.txt
 *     sha256sum rows.txt | cut -d' ' -f1 > tests/fixtures/score/sweep-rows.txt.sha256
 *     gzip -9n < rows.txt > tests/fixtures/score/sweep-rows.txt.gz
 *
 * @phpstan-import-type Graded from ScoreBasis
 * @phpstan-import-type Zero from ScoreBasis
 *
 * @phpstan-type Advisory array{id: string, severity: string, fix_kind: string}
 * @phpstan-type Inputs array{axis: string, flags: list<string>, advisories: list<array{string, string}>, reach: string, dev: bool, under: ?string, accepted: ?string}
 */
final class ScoreSweep
{
    public const GOLDEN = __DIR__.'/../fixtures/score/sweep-rows.txt.gz';
    public const GOLDEN_SHA256 = __DIR__.'/../fixtures/score/sweep-rows.txt.sha256';

    public const AXES = ['base' => 47612, 'unreached' => 23806, 'under' => 35712, 'under_entry' => 35712, 'accepted' => 91264];

    /** The default suite reads every SAMPLE-th row of each axis. */
    public const SAMPLE = 1000;

    /** The context of every sweep row: maintenance judged, a complete advisory lookup, both liveness signals read. */
    public const CONTEXT = ['maintenance_judged' => true, 'advisories_complete' => true, 'liveness_complete' => true, 's3_unread' => false, 's8_unread' => false];

    /**
     * Every input of the sweep, in the order of tools/score/sweep.py.
     *
     * @return \Generator<int, Inputs>
     */
    public static function inputs(): \Generator
    {
        foreach ([null, 'abandoned', 'silent', 'stale'] as $live) {
            foreach ([false, true] as $pinned) {
                foreach ([false, true] as $leftBehind) {
                    foreach ([false, true] as $oldPromise) {
                        foreach (self::advisorySets() as $advisories) {
                            foreach ([true, false] as $direct) {
                                foreach ([false, true] as $dev) {
                                    $flags = array_values(array_filter([$live, $pinned ? 'pinned' : null, $leftBehind ? 'left-behind' : null, $oldPromise ? 'old-promise' : null]));
                                    if (($flags === [] && $advisories === []) || ($pinned && $leftBehind)) {
                                        continue;
                                    }
                                    yield from self::variants($flags, $advisories, $direct, $dev);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @param list<string>                $flags
     * @param list<array{string, string}> $advisories
     *
     * @return \Generator<int, Inputs>
     */
    private static function variants(array $flags, array $advisories, bool $direct, bool $dev): \Generator
    {
        foreach ($direct ? ['direct'] : ['transitive', 'unreached'] as $reach) {
            foreach ($flags !== [] && $flags[0] === 'abandoned' ? [null, 'silent', 'stale'] : [null] as $under) {
                $row = ['axis' => 'base', 'flags' => $flags, 'advisories' => $advisories, 'reach' => $reach, 'dev' => $dev, 'under' => $under, 'accepted' => null];
                if ($under !== null) {
                    yield ['axis' => 'under'] + $row;
                    yield ['axis' => 'under_entry', 'accepted' => $under] + $row;
                    continue;
                }
                if ($reach === 'unreached') {
                    yield ['axis' => 'unreached'] + $row;
                    continue;
                }
                yield $row;
                foreach ($flags as $flag) {
                    yield ['axis' => 'accepted', 'accepted' => $flag] + $row;
                }
            }
        }
    }

    /** @return list<list<array{string, string}>> no advisory, one, and every pair with repetition */
    private static function advisorySets(): array
    {
        $one = [];
        foreach (ScoreModel::SEVERITIES as $severity) {
            foreach (ScoreModel::FIX_KINDS as $fix) {
                $one[] = [$severity, $fix];
            }
        }
        $sets = [[]];
        foreach ($one as $advisory) {
            $sets[] = [$advisory];
        }
        foreach ($one as $i => $first) {
            foreach (\array_slice($one, $i) as $second) {
                $sets[] = [$first, $second];
            }
        }

        return $sets;
    }

    /**
     * The flag set that a finding with these facts has: each flag raised by its signals, the hidden
     * liveness word by S2 and S4 beside S1, the accepted flag by an ignore[] entry that lists it.
     *
     * @param Inputs $inputs
     */
    public static function flagSet(array $inputs): FlagSet
    {
        $signals = [];
        foreach ($inputs['flags'] as $flag) {
            $signals = array_merge($signals, self::signalsOf($flag));
        }
        if ($inputs['under'] !== null) {
            $signals = array_merge($signals, self::signalsOf($inputs['under']));
        }
        $entry = $inputs['accepted'] === null ? null : new AllowlistEntry('acme/package', null, 'the sweep accepts it', null, 'project', [$inputs['accepted']]);

        return FlagSet::fromSignals($signals, $entry, self::advisories($inputs['advisories']));
    }

    /**
     * @param list<array{string, string}> $advisories
     *
     * @return list<Advisory>
     */
    public static function advisories(array $advisories): array
    {
        $out = [];
        foreach ($advisories as $i => [$severity, $fix]) {
            $out[] = ['id' => 'A'.$i, 'severity' => $severity, 'fix_kind' => $fix];
        }

        return $out;
    }

    /** @return list<Signal> */
    public static function signalsOf(string $flag): array
    {
        switch ($flag) {
            case 'abandoned':
                return [new Signal(Signal::S1, Signal::LEVEL_HIGH, 'marked abandoned by its repository')];
            case 'silent':
                return [new Signal(Signal::S2, Signal::LEVEL_HIGH, 'no release'), new Signal(Signal::S4, Signal::LEVEL_HIGH, 'no push')];
            case 'stale':
                return [new Signal(Signal::S2, Signal::LEVEL_WARN, 'no release')];
            case 'pinned':
                return [new Signal(Signal::S6, Signal::LEVEL_HIGH, 'branch snapshot')];
            case 'left-behind':
                return [new Signal(Signal::S8, Signal::LEVEL_HIGH, 'branch left behind')];
            case 'old-promise':
                return [new Signal(Signal::S5, Signal::LEVEL_HIGH, 'old promise')];
        }
        throw new \InvalidArgumentException('no signal raises '.$flag);
    }

    /** @param Inputs $inputs */
    public static function basis(array $inputs): ScoreBasis
    {
        return ScoreBasis::of(self::flagSet($inputs), $inputs['reach'], $inputs['dev'], self::CONTEXT);
    }

    /**
     * @param Graded|Zero $score
     *
     * @return Graded
     */
    public static function graded(array $score): array
    {
        if (!isset($score['terms'])) {
            throw new \UnexpectedValueException('a score of 0 has no arithmetic: '.$score['text']);
        }

        return $score;
    }

    /** @param Inputs $inputs */
    public static function row(array $inputs): string
    {
        return self::format($inputs, self::basis($inputs)->toArray());
    }

    /**
     * @param Inputs               $inputs
     * @param array<string, mixed> $score  the score object, `finding.score`
     */
    public static function format(array $inputs, array $score): string
    {
        $total = self::int($score['total']);
        $without = [];
        foreach (self::listOf($score['without'] ?? []) as $row) {
            $remove = self::map($row['remove']);
            $without[] = self::string($remove['id']).':'.self::int($row['total']).':'.($row['verdict'] === null ? '-' : self::string($row['verdict'])).($row['at_least'] === true ? '+' : '');
        }
        $ifCounted = [];
        foreach (self::listOf($score['accepted']) as $row) {
            $counted = self::map($row['if_counted']);
            $ifCounted[] = self::string($row['flag']).':'.self::int($counted['total']).':'.($counted['verdict'] === null ? '-' : self::string($counted['verdict'])).($counted['at_least'] === true ? '+' : '');
        }
        $gate = '';
        foreach (ScoreModel::GRADES as $grade) {
            $gate .= $total >= ScoreModel::floorOf($grade) ? '1' : '0';
        }

        return implode('|', [
            $inputs['axis'],
            self::join($inputs['flags']),
            self::join(array_map(static fn (array $a): string => $a[0].':'.$a[1], $inputs['advisories'])),
            $inputs['reach'],
            $inputs['dev'] ? '1' : '0',
            $inputs['under'] ?? '-',
            $inputs['accepted'] ?? '-',
            (string) self::halves($score['exact']),
            (string) $total,
            ScoreModel::band($total) ?? '-',
            isset($score['decided_by']) ? self::string($score['decided_by']) : '-',
            self::join($without),
            self::join($ifCounted),
            $gate,
        ]);
    }

    /**
     * The inputs that fields 1 to 7 of a row give.
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
            'axis' => $field[0],
            'flags' => $field[1] === '-' ? [] : explode(',', $field[1]),
            'advisories' => $advisories,
            'reach' => $field[3],
            'dev' => $field[4] === '1',
            'under' => $field[5] === '-' ? null : $field[5],
            'accepted' => $field[6] === '-' ? null : $field[6],
        ];
    }

    /**
     * The committed rows, in their order.
     *
     * @return \Generator<int, string>
     */
    public static function golden(): \Generator
    {
        $handle = gzopen(self::GOLDEN, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('cannot read '.self::GOLDEN);
        }
        try {
            while (($line = gzgets($handle)) !== false) {
                yield rtrim($line, "\n");
            }
        } finally {
            gzclose($handle);
        }
    }

    /**
     * Every SAMPLE-th committed row of each axis, the first one included.
     *
     * @return array<string, string> the row by its axis and its index on the axis
     */
    public static function sample(): array
    {
        $seen = [];
        $rows = [];
        foreach (self::golden() as $row) {
            $axis = substr($row, 0, (int) strpos($row, '|'));
            $index = $seen[$axis] = ($seen[$axis] ?? -1) + 1;
            if ($index % self::SAMPLE === 0) {
                $rows[$axis.' #'.$index] = $row;
            }
        }

        return $rows;
    }

    /** @param mixed $value an exact, a contribution, a before or an after: a multiple of 0.5 */
    public static function halves($value): int
    {
        if (\is_int($value)) {
            return 2 * $value;
        }
        if (!\is_float($value) || floor(2 * $value) !== 2 * $value) {
            throw new \UnexpectedValueException('not on the half-point grid: '.var_export($value, true));
        }

        return (int) (2 * $value);
    }

    /** @param list<string> $items */
    private static function join(array $items): string
    {
        return $items === [] ? '-' : implode(',', $items);
    }

    /** @param mixed $value */
    private static function int($value): int
    {
        if (!\is_int($value)) {
            throw new \UnexpectedValueException('not an integer: '.var_export($value, true));
        }

        return $value;
    }

    /** @param mixed $value */
    private static function string($value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('not a string: '.var_export($value, true));
        }

        return $value;
    }

    /**
     * @param mixed $value
     *
     * @return array<string, mixed>
     */
    private static function map($value): array
    {
        if (!\is_array($value)) {
            throw new \UnexpectedValueException('not an object: '.var_export($value, true));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param mixed $value
     *
     * @return list<array<string, mixed>>
     */
    private static function listOf($value): array
    {
        if (!\is_array($value)) {
            throw new \UnexpectedValueException('not a list: '.var_export($value, true));
        }
        $out = [];
        foreach ($value as $item) {
            $out[] = self::map($item);
        }

        return $out;
    }
}
