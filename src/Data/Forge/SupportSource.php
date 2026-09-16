<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

/**
 * The repository named by a package's `support.source`, reduced to something {@see RepoLocator}
 * reads.
 *
 * `support.source` is a web address, not a clone URL. Packagist fills it in for every package
 * whose composer.json leaves it out, as `<repository>/tree/<version>` — phpunit, php-cs-fixer,
 * rector all carry that shape — and GitLab's own pages read `<repository>/-/tree/<ref>`. Those
 * tails are cut here, once, so the locator only ever sees the repository. A maintainer-set value
 * such as phpstan's `https://github.com/phpstan/phpstan-src` passes through unchanged.
 */
final class SupportSource
{
    /** @param array<mixed> $support a package's `support` map, as Composer exposes it */
    public static function url(array $support): ?string
    {
        $url = $support['source'] ?? null;
        if (!\is_string($url) || $url === '') {
            return null;
        }
        $url = rtrim($url, '/');
        $url = (string) preg_replace('{/(?:-/)?tree/[^/]+$}', '', $url);

        return $url === '' ? null : $url;
    }
}
