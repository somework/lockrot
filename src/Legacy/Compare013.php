<?php

declare(strict_types=1);

namespace Lockrot\Legacy;

use Lockrot\Verdict\Finding;

/** @internal */
final class Compare013
{
    /**
     * The package name is the last key, so the order is total and the report reads the same on every
     * run. Descending keys take the other finding's value, ascending keys their own.
     */
    public static function compare(Finding $a, Finding $b): int
    {
        return [Priority013::rank($b->priority()), Verdict013::severity($b->verdict()), $b->isDirect(), $a->package()]
            <=> [Priority013::rank($a->priority()), Verdict013::severity($a->verdict()), $a->isDirect(), $b->package()];
    }
}
