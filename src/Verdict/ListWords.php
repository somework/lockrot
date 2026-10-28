<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

/**
 * Joins the items of a sentence list: "a", "a and b", "a, b and c".
 *
 * @internal
 */
final class ListWords
{
    /** @param array<string> $items in order, an empty array gives '' */
    public static function of(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' and '.$last;
    }
}
