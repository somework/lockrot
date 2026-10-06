<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Verdict\Finding;

/**
 * How a finding says where the project requires it from, spelled once for every format
 * (docs/verdicts.md#every-direct-requirement-that-reaches-a-package). Formats differ only in the
 * separator between chain links and in whether the phrase is inline, a table cell or a suffix.
 *
 * @internal
 */
final class Via
{
    /** Other roots named before the rest is counted, so a leaf every root reaches stays one line. */
    public const MAX_OTHERS = 3;

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
     * Empty for a direct package: in a framework application every other root reaches its core
     * packages too, so naming them puts the same bundles after every `direct`.
     * {@see Finding::directDependents()} still carries them for the machine formats.
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

    public static function inline(Finding $finding, string $separator): string
    {
        $chain = self::chain($finding, $separator);
        $phrase = $chain === 'direct' || $chain === '?' ? $chain : 'via '.$chain;

        return self::withAlso($phrase, $finding);
    }

    public static function cell(Finding $finding, string $separator): string
    {
        return self::withAlso(self::chain($finding, $separator), $finding);
    }

    /**
     * Empty for a direct package or one that nothing reaches. Without $withOthers, the other roots
     * are omitted, because install-time lines are read in passing and already long.
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
