<?php

declare(strict_types=1);

namespace Lockrot;

/**
 * Not strtolower(): before PHP 8.2 it follows LC_CTYPE, and under a Turkish locale `I` does not
 * become `i`. The PHAR never calls setlocale(), but a plugin's host process can call it.
 *
 * @internal
 */
final class AsciiCase
{
    public static function lower(string $value): string
    {
        return strtr($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }
}
