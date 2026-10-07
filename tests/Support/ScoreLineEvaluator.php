<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

/**
 * Evaluates a score line as arithmetic: `÷` binds to the operand before it, and `+` binds last. It
 * skips the bracketed notes and the words, and it checks the rounded-down tail against the body. It
 * shares no code with ScoreText, so a misplaced parenthesis gives another number.
 */
final class ScoreLineEvaluator
{
    /** @return array{int, int} the head and the body in integer half points */
    public static function evaluate(string $line): array
    {
        if (preg_match('/^(\d+)(?: = (.*?))?(?: \((\d+(?:\.5)?), rounded down\))?(?: \([a-z, -]+ accepted\))?$/u', $line, $m) !== 1) {
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
        if (isset($m[3]) && (int) round(2 * (float) $m[3]) !== $value) {
            throw new \UnexpectedValueException('the rounded-down tail is not the body: '.$line);
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
        if (!isset($tokens[$at])) {
            throw new \UnexpectedValueException('an operand is missing');
        }
        if ($tokens[$at] === '(') {
            ++$at;
            $value = self::sum($tokens, $at);
            if (($tokens[$at++] ?? null) !== ')') {
                throw new \UnexpectedValueException('a parenthesis is not closed');
            }
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
