<?php

declare(strict_types=1);

namespace Lockrot\Score;

/**
 * The one score line, text grammar 1, rendered from the score object's fields alone:
 *
 *     36 = left-behind 16 + old-promise 4 [¼ of 16] + vulnerable 16 [high advisory]
 *     4 = ((left-behind 16 + stale 2 [¼ of 8]) ÷ 2 transitive) ÷ 2 dev (4.5, rounded down)
 *     0 (left-behind accepted)
 *
 * Read with `÷` binding to the operand before it and `+` lowest, every line evaluates to `exact`, so
 * the dev halving wraps any body that is more than one atom. A halving that removes nothing is not
 * printed. A change to this grammar bumps `score_text_grammar`.
 *
 * @internal
 */
final class ScoreText
{
    /** The glyph of a corroborating term's share, by its divisor. */
    private const GLYPHS = [4 => '¼'];

    /**
     * @param array<string, mixed> $score the graded or the score-0 shape of `finding.score`, decoded or
     *                                    as {@see ScoreBasis::toArray()} writes it
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
        if ($term['multiplier'] !== 1) {
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
        $halves = (int) round(2 * $value);

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
