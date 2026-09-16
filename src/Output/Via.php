<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Verdict\Finding;

/**
 * How a finding says where the project requires it from, spelled once for every format.
 *
 * Two facts go into the phrase: the shortest chain (`direct`, `via a › b`, or `?` when nothing in
 * the project reaches the package), and — since the chain names only one root — for a transitive
 * package the other direct requirements it is also reachable from
 * ({@see Finding::otherDirectDependents()}), as `also via c, d`. The second part is what tells a
 * reader that dropping the chain's root would not remove the package. Formats differ only in the
 * separator between chain links and in whether the phrase is inline, a table cell or a
 * parenthesised suffix.
 */
final class Via
{
    /** Other roots named before the rest is counted, so a leaf every root reaches stays one line. */
    public const MAX_OTHERS = 3;

    /** The chain alone: `direct`, `?`, or the links up to (not including) the package, joined with $separator. */
    public static function chain(Finding $finding, string $separator): string
    {
        $chain = $finding->chain();
        if ($chain === []) {
            return '?';
        }
        array_pop($chain);

        return $chain === [] ? 'direct' : implode($separator, $chain);
    }

    /**
     * `also via c, d and 2 more`, or the empty string when the chain's root is the only one — or
     * when the package is direct: a direct requirement is where the project wants it, and in a
     * framework application every other root reaches its core packages too, so naming them would
     * put the same twenty bundles after every `direct`. {@see Finding::directDependents()} still
     * carries them for the machine formats.
     */
    public static function also(Finding $finding): string
    {
        $others = $finding->isDirect() ? [] : $finding->otherDirectDependents();
        if ($others === []) {
            return '';
        }
        $phrase = 'also via '.implode(', ', \array_slice($others, 0, self::MAX_OTHERS));
        if (\count($others) > self::MAX_OTHERS) {
            $phrase .= \sprintf(' and %d more', \count($others) - self::MAX_OTHERS);
        }

        return $phrase;
    }

    /** For a row: `direct`, `?`, `via a › b`, each followed by `, also via …` when there is more. */
    public static function inline(Finding $finding, string $separator): string
    {
        $chain = self::chain($finding, $separator);
        $phrase = $chain === 'direct' || $chain === '?' ? $chain : 'via '.$chain;

        return self::withAlso($phrase, $finding);
    }

    /** For a table cell headed "Via": the chain without the word, `, also via …` appended when there is more. */
    public static function cell(Finding $finding, string $separator): string
    {
        return self::withAlso(self::chain($finding, $separator), $finding);
    }

    /**
     * For a one-line message: ` (via a > b)` or ` (via a > b, also via c)`; nothing for a direct or
     * unplaceable package. With $withOthers false the other roots are left out — what the
     * install-time block wants, whose lines are read in passing and already long.
     */
    public static function suffix(Finding $finding, string $separator, bool $withOthers = true): string
    {
        $chain = self::chain($finding, $separator);
        $parts = [];
        if ($chain !== 'direct' && $chain !== '?') {
            $parts[] = 'via '.$chain;
        }
        $also = $withOthers ? self::also($finding) : '';
        if ($also !== '') {
            $parts[] = $also;
        }

        return $parts === [] ? '' : ' ('.implode(', ', $parts).')';
    }

    private static function withAlso(string $phrase, Finding $finding): string
    {
        $also = self::also($finding);

        return $also === '' ? $phrase : $phrase.', '.$also;
    }
}
