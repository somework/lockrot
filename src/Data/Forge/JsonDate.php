<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

/** A date out of a JSON value: null unless it is a string PHP can parse. */
final class JsonDate
{
    /** @param mixed $value */
    public static function parse($value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            return null;
        }
    }
}
