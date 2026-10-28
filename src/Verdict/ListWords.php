<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

/**
 * The list join of every sentence that lockrot prints: "a", "a and b", "a, b and c".
 *
 * @internal
 */
final class ListWords
{
    /** @param list<string> $items an empty list gives '' */
    public static function of(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' and '.$last;
    }
}
