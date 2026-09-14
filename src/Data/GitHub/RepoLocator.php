<?php

declare(strict_types=1);

namespace Lockrot\Data\GitHub;

final class RepoLocator
{
    private const PATTERN = '{^(?:https?://|git://|git@|ssh://git@)github\.com[/:]([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$}';

    public static function github(?string $url): ?string
    {
        if ($url === null || $url === '' || preg_match(self::PATTERN, $url, $m) !== 1) {
            return null;
        }

        return $m[1].'/'.$m[2];
    }
}
