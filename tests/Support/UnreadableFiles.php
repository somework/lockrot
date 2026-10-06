<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

/**
 * Paths to files that exist and cannot be read, on every user. A mode-0000 file does not serve:
 * root reads it, and Infection's include interceptor reports it as missing.
 */
final class UnreadableFiles
{
    private const SCHEME = 'lockrot-unreadable';

    /** @var resource|null */
    public $context;

    public static function path(string $name): string
    {
        if (!\in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }

        return self::SCHEME.'://'.$name;
    }

    /** @return array{mode: int} a regular file with no permission bits */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0100000];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }
}
