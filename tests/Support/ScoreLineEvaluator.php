<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

/**
 * Reads a score line back as arithmetic: `÷` binds to the operand before it, `+` is lowest, the
 * bracketed notes, the words and the trailing parentheses are ignored. It shares no code with
 * ScoreText, so a misplaced parenthesis gives another number.
 */
final class ScoreLineEvaluator
{
    /** @return array{int, int} the head and the body in integer half points */
    public static function evaluate(string $line): array
    {
        if (preg_match('/^(\d+)(?: = (.*?))?(?: \(\d+(?:\.5)?, rounded down\))?(?: \([a-z, -]+ accepted\))?$/u', $line, $m) !== 1) {
            throw new \UnexpectedValueException('not a score line: '.$line);
        }
        if (!isset($m[2])) {
            return [(int) $m[1], 0];
        }
        preg_match_all('/\d+(?:\.5)?|[+÷()]/u', (string) preg_replace('/\[[^]]*\]/u', '', $m[2]), $tokens);
        $at = 0;
        $value = self::sum($tokens[0], $at);
        if ($at !== \count($tokens[0])) {
            throw new \UnexpectedValueException('unbalanced: '.$line);
        }

        return [(int) $m[1], $value];
    }

    /** @param list<string> $tokens */
    private static function sum(array $tokens, int &$at): int
    {
        $value = self::quotient($tokens, $at);
        while (($tokens[$at] ?? null) === '+') {
            ++$at;
            $value += self::quotient($tokens, $at);
        }

        return $value;
    }

    /** @param list<string> $tokens */
    private static function quotient(array $tokens, int &$at): int
    {
        if ($tokens[$at] === '(') {
            ++$at;
            $value = self::sum($tokens, $at);
            ++$at;
        } else {
            $value = (int) round(2 * (float) $tokens[$at++]);
        }
        while (($tokens[$at] ?? null) === '÷') {
            $divisor = (int) $tokens[$at + 1];
            $at += 2;
            if ($value % $divisor !== 0) {
                throw new \UnexpectedValueException('leaves the half-point grid');
            }
            $value = intdiv($value, $divisor);
        }

        return $value;
    }
}
