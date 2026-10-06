<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

/**
 * The repository named by a package's `support.source`, reduced for {@see RepoLocator}.
 *
 * The value is a web address, not a clone URL: Packagist fills in `<repository>/tree/<version>`,
 * GitLab pages read `<repository>/-/tree/<ref>` and Bitbucket pages `<repository>/src/<ref>`.
 * The tail is cut here, once, so that the locator sees only the repository.
 * docs/internals.md, "Which host is asked".
 *
 * @internal
 */
final class SupportSource
{
    /** @param array<mixed> $support */
    public static function url(array $support): ?string
    {
        $url = $support['source'] ?? null;
        if (!\is_string($url) || $url === '') {
            return null;
        }
        $url = rtrim($url, '/');
        $url = (string) preg_replace('{/(?:-/)?(?:tree|src)/[^/]+$}', '', $url);

        return $url === '' ? null : $url;
    }
}
