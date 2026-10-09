<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Verdict\ScoreModel;

/**
 * `run.score_rules_used`: per rule of the score model, the findings on which it changed or decided
 * a number. `zero-verdicts` counts the score-0 findings, `counted` every finding with an accepted
 * flag, and `sort`, which changes no number, is null.
 *
 * @internal
 */
final class ScoreRulesUsed
{
    /**
     * @param list<array<string, mixed>> $findings report-2's finding objects
     *
     * @return array<string, int|null>
     */
    public static function of(array $findings): array
    {
        $used = ScoreModel::rulesUnused();
        foreach ($findings as $finding) {
            $score = self::map($finding['score'] ?? null);
            $accepted = self::list($score['accepted'] ?? null);
            if ($accepted !== []) {
                ++$used['counted'];
            }
            $rerunHalves = static function (string $key, string $value) use ($accepted): bool {
                foreach ($accepted as $row) {
                    foreach (self::list(self::map($row['if_counted'] ?? null)['modifiers'] ?? null) as $modifier) {
                        if (($modifier[$key] ?? null) === $value && ($modifier['before'] ?? null) !== ($modifier['after'] ?? null)) {
                            return true;
                        }
                    }
                }

                return false;
            };
            if (!isset($score['terms'])) {
                if ($rerunHalves('applies_to', 'maintenance')) {
                    ++$used['divide-reach'];
                }
                if ($rerunHalves('reason', 'dev')) {
                    ++$used['divide-dev'];
                }
                ++$used['zero-verdicts'];
                continue;
            }
            $terms = self::list($score['terms']);
            $modifiers = self::list($score['modifiers'] ?? null);
            $parts = self::map($score['parts'] ?? null);
            $security = self::any($terms, 'part', 'security');
            ++$used['band-floors'];
            if (self::any($terms, 'role', 'lead')) {
                ++$used['lead-first'];
            }
            if (self::any($terms, 'role', 'corroborating')) {
                ++$used['corroborating-share'];
            }
            if ($security) {
                ++$used['advisory-points'];
            }
            if (self::any($terms, 'multiplier', 2)) {
                ++$used['no-reachable-fix-multiplier'];
            }
            $advisories = self::map($parts['security'] ?? null)['of'] ?? null;
            if (\is_int($advisories) && $advisories >= 2) {
                ++$used['security-max'];
            }
            if (self::halves($modifiers, 'applies_to', 'maintenance') || $rerunHalves('applies_to', 'maintenance')) {
                ++$used['divide-reach'];
            }
            if ($security && ($finding['reach'] ?? null) !== 'direct') {
                ++$used['security-exempt-from-reach'];
            }
            if ((self::map($parts['maintenance'] ?? null)['status'] ?? null) === 'counted' && (self::map($parts['security'] ?? null)['status'] ?? null) === 'counted') {
                ++$used['sum'];
            }
            if (self::any($modifiers, 'reason', 'dev') || $rerunHalves('reason', 'dev')) {
                ++$used['divide-dev'];
            }
            if (($score['rounded_down'] ?? false) === true) {
                ++$used['floor-once'];
            }
        }

        return $used;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param mixed                      $value
     */
    private static function any(array $rows, string $key, $value): bool
    {
        foreach ($rows as $row) {
            if (($row[$key] ?? null) === $value) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $modifiers */
    private static function halves(array $modifiers, string $key, string $value): bool
    {
        foreach ($modifiers as $modifier) {
            if (($modifier[$key] ?? null) === $value && ($modifier['before'] ?? null) !== ($modifier['after'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     *
     * @return array<string, mixed>
     */
    private static function map($value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        /** @var array<string, mixed> $value report-2 writes objects with string keys only */
        return $value;
    }

    /**
     * @param mixed $value
     *
     * @return list<array<string, mixed>>
     */
    private static function list($value): array
    {
        $rows = [];
        foreach (\is_array($value) ? $value : [] as $item) {
            if (\is_array($item)) {
                $rows[] = self::map($item);
            }
        }

        return $rows;
    }
}
