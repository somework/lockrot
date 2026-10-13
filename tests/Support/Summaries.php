<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Verdict\Finding;

/** The summaries of a finding's signals in signal order, joined by `; `, or its note when no signal fired. */
final class Summaries
{
    public static function of(Finding $finding): string
    {
        $summaries = array_map(static fn ($signal): string => $signal->summary(), $finding->signals());

        return $summaries === [] ? (string) $finding->note() : implode('; ', $summaries);
    }
}
