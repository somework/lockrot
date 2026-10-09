<?php

declare(strict_types=1);

namespace Lockrot\Score;

/**
 * The one score line, text grammar 1, rendered from the score object's fields alone:
 *
 *     4 = ((left-behind 16 + stale 2 [¼ of 8]) ÷ 2 transitive) ÷ 2 dev (4.5, rounded down)
 *
 * `÷` binds to the operand before it and `+` binds last, so every line evaluates to `exact`. A
 * halving that removes nothing is not printed. A change to this grammar bumps `score_text_grammar`.
 *
 * @internal
 */
final class ScoreText
{
    /** The glyph of a corroborating term's share, by its divisor. */
    private const GLYPHS = [4 => '¼'];

    /** @throws \LogicException for a corroborating share that the grammar has no glyph for */
    public static function render(ScoreBasis $score): string
    {
        $accepted = array_map(static fn (Accepted $row): string => $row->flag(), $score->accepted());
        $tail = $accepted === [] ? '' : ' ('.implode(', ', $accepted).' accepted)';
        if ($score->terms() === []) {
            return '0'.$tail;
        }
        $maintenance = [];
        foreach ($score->maintenanceTerms() as $term) {
            $maintenance[] = $term->isLead() ? $term->flag().' '.$term->points() : self::corroborating($term);
        }
        $reach = null;
        $dev = null;
        foreach ($score->modifiers() as $modifier) {
            if (!$modifier->changes()) {
                continue;
            }
            $halving = ' ÷ '.$modifier->divideBy().' '.$modifier->reason();
            if ($modifier->isDev()) {
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
        $security = $score->securityTerm();
        if ($security !== null) {
            $body[] = self::security($security);
        }
        $line = implode(' + ', $body);
        if ($dev !== null) {
            $atom = \count($body) === 1 && ($maintenance === [] || (\count($maintenance) === 1 && $reach === null));
            $line = ($atom ? $line : '('.$line.')').$dev;
        }
        return $score->total().' = '.$line.($score->roundedDown() ? ' ('.HalfPoints::text($score->exactHalves()).', rounded down)' : '').$tail;
    }

    /** @throws \LogicException for a share that the grammar has no glyph for */
    public static function corroborating(MaintenanceTerm $term): string
    {
        $divisor = $term->divisor();
        if (!isset(self::GLYPHS[$divisor])) {
            throw new \LogicException('text grammar 1 has no glyph for a share of 1/'.$divisor);
        }

        return $term->flag().' '.$term->points().' ['.self::GLYPHS[$divisor].' of '.$term->weight().']';
    }

    private static function security(SecurityTerm $term): string
    {
        $note = $term->severity().' advisory';
        if ($term->multiplier() !== 1) {
            $note .= ' '.$term->weight().' × '.$term->multiplier().': no reachable fix';
        }

        return 'vulnerable '.$term->points().' ['.$note.']';
    }
}
