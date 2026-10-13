<?php

declare(strict_types=1);

namespace Lockrot\Score;

use Lockrot\Verdict\Score;

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

        return ($score->terms() === [] ? '0' : self::line($score->total(), $score->maintenanceTerms(), $score->securityTerm(), $score->modifiers(), $score->roundedDown(), $score->exactHalves())).$tail;
    }

    /**
     * A score that no flag set backs, such as the gate basis's `new`: its terms and modifiers, no
     * accepted flag.
     *
     * @throws \LogicException for a corroborating share that the grammar has no glyph for
     */
    public static function ofScore(Score $score): string
    {
        if ($score->grade() === null) {
            return '0';
        }

        return self::line($score->total(), $score->maintenanceTerms(), $score->deciding(), ScoreBasis::modifiersOf($score), $score->exactHalves() !== 2 * $score->total(), $score->exactHalves());
    }

    /**
     * @param list<MaintenanceTerm> $terms
     * @param list<Modifier>        $modifiers
     *
     * @throws \LogicException for a corroborating share that the grammar has no glyph for
     */
    private static function line(int $total, array $terms, ?SecurityTerm $security, array $modifiers, bool $roundedDown, int $exactHalves): string
    {
        $maintenance = [];
        foreach ($terms as $term) {
            $maintenance[] = $term->isLead() ? $term->flag().' '.$term->points() : self::corroborating($term);
        }
        $reach = null;
        $dev = null;
        foreach ($modifiers as $modifier) {
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
        if ($security !== null) {
            $body[] = self::security($security);
        }
        $line = implode(' + ', $body);
        if ($dev !== null) {
            $atom = \count($body) === 1 && ($maintenance === [] || (\count($maintenance) === 1 && $reach === null));
            $line = ($atom ? $line : '('.$line.')').$dev;
        }
        return $total.' = '.$line.($roundedDown ? ' ('.HalfPoints::text($exactHalves).', rounded down)' : '');
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
