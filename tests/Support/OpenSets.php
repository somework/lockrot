<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

/**
 * Every place a published schema carries `x-known-values`, by document and JSON pointer, with the
 * words docs/compatibility.md and docs/schema.md name it by. An open set the pages do not name is
 * one a consumer is never told may grow; OpenSetsRegisteredTest holds the two lists to each other.
 */
final class OpenSets
{
    /** @var array<string, string> `<document> <pointer>` => the phrase each page names it by */
    public const PHRASES = [
        'report #/definitions/signalId' => 'signal ids',
        'report #/definitions/s10/properties/unchecked/items/properties/check' => 'S10',
        'report #/definitions/s10/properties/unchecked/items/properties/reason' => 'S10',
        'report #/definitions/s6/properties/reason' => "S6's `reason`",
        'report #/definitions/s8/properties/floor_source/oneOf/0' => '`floor_source`',
        'report #/definitions/finding/properties/libyears_unmeasured/oneOf/0' => '`libyears_unmeasured`',
        'explain #/definitions/signalId' => 'signal ids',
        'explain #/definitions/finding/properties/libyears_unmeasured/oneOf/0' => '`libyears_unmeasured`',
        'explain #/definitions/metadata/properties/branches/items/properties/php_blocked_by/oneOf/0' => '`php_blocked_by`',
        'explain #/definitions/metadata/properties/branches/items/properties/misses_target_php/oneOf/0' => '`misses_target_php`',
        'explain #/definitions/metadata/properties/branches/items/properties/misses_project_php/oneOf/0' => '`misses_project_php`',
        'config #/properties/format' => 'format',
    ];
}
