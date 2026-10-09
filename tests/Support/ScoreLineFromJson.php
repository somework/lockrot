<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

/**
 * Renders text grammar 1 from a decoded `finding.score`, as a consumer that reads only the JSON
 * does. It shares no code with {@see \Lockrot\Score\ScoreText}, so invariant I17 shows that the
 * object carries every field that the line needs.
 */
final class ScoreLineFromJson
{
    /** The glyph of a corroborating term's share, by its divisor. */
    private const GLYPHS = [4 => '¼'];

    /**
     * @param array<string, mixed> $score the graded or the score-0 shape of `finding.score`, decoded
     *
     * @throws \LogicException for a corroborating share that the grammar has no glyph for
     */
    public static function render(array $score): string
    {
        $accepted = array_map(static fn (array $row): string => self::text($row['flag']), self::rows($score['accepted'] ?? []));
        $tail = $accepted === [] ? '' : ' ('.implode(', ', $accepted).' accepted)';
        $terms = self::rows($score['terms'] ?? []);
        if ($terms === []) {
            return '0'.$tail;
        }
        $maintenance = [];
        $security = null;
        foreach ($terms as $term) {
            if ($term['part'] === 'security') {
                $security = self::security($term);
            } elseif ($term['role'] === 'lead') {
                $maintenance[] = self::text($term['flag']).' '.self::number($term['points']);
            } else {
                $maintenance[] = self::corroborating($term);
            }
        }
        $reach = null;
        $dev = null;
        foreach (self::rows($score['modifiers'] ?? []) as $modifier) {
            if (self::number($modifier['before']) === self::number($modifier['after'])) {
                continue;
            }
            $halving = ' ÷ '.self::number($modifier['divide_by']).' '.self::text($modifier['reason']);
            if ($modifier['applies_to'] === 'total') {
                $dev = $halving;
            } else {
                $reach = $halving;
            }
        }
        $body = [];
        if ($maintenance !== []) {
            $line = implode(' + ', $maintenance);
            $body[] = $reach === null ? $line : (\count($maintenance) > 1 ? '('.$line.')' : $line).$reach;
        }
        if ($security !== null) {
            $body[] = $security;
        }
        $line = implode(' + ', $body);
        if ($dev !== null) {
            $atom = \count($body) === 1 && ($maintenance === [] || (\count($maintenance) === 1 && $reach === null));
            $line = ($atom ? $line : '('.$line.')').$dev;
        }
        $exact = self::number($score['exact']);
        $total = self::number($score['total']);

        return $total.' = '.$line.($exact === $total ? '' : ' ('.$exact.', rounded down)').$tail;
    }

    /** @param array<string, mixed> $term */
    private static function corroborating(array $term): string
    {
        $divisor = $term['divisor'];
        if (!\is_int($divisor) || !isset(self::GLYPHS[$divisor])) {
            throw new \LogicException('text grammar 1 has no glyph for a share of 1/'.self::number($divisor));
        }

        return self::text($term['flag']).' '.self::number($term['points']).' ['.self::GLYPHS[$divisor].' of '.self::number($term['weight']).']';
    }

    /** @param array<string, mixed> $term */
    private static function security(array $term): string
    {
        $note = self::text($term['severity']).' advisory';
        if (self::number($term['multiplier']) !== '1') {
            $note .= ' '.self::number($term['weight']).' × '.self::number($term['multiplier']).': no reachable fix';
        }

        return 'vulnerable '.self::number($term['points']).' ['.$note.']';
    }

    /**
     * Integers as written, a half as `4.5`.
     *
     * @param mixed $value
     */
    private static function number($value): string
    {
        if (!\is_int($value) && !\is_float($value)) {
            throw new \LogicException('not a number: '.var_export($value, true));
        }
        $halves = (int) (2 * $value);

        return intdiv($halves, 2).($halves % 2 === 0 ? '' : '.5');
    }

    /** @param mixed $value */
    private static function text($value): string
    {
        if (!\is_string($value)) {
            throw new \LogicException('not a string: '.var_export($value, true));
        }

        return $value;
    }

    /**
     * @param mixed $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function rows($rows): array
    {
        /** @var list<array<string, mixed>> $rows */
        return \is_array($rows) ? $rows : [];
    }
}
