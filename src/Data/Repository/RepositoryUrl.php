<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

/**
 * A package's repository URL, on its way out of lockrot and into something a person reads.
 *
 * The value is whatever the package's `source.url` or `support.source` says, and for a private
 * repository that is routinely a URL with credentials in it: `https://gitlab-ci-token:$CI_JOB_TOKEN@…`
 * is how GitLab CI hands a job access to a private Composer source, and Bitbucket's app passwords
 * take the same shape. Composer keeps them because it has to fetch with them. lockrot only ever
 * shows them, and a report — a terminal buffer pasted into a ticket, a JSON file uploaded as a CI
 * artifact, an HTML page published in a blog post — is exactly where a token should never appear.
 *
 * @internal
 */
final class RepositoryUrl
{
    /**
     * The same URL without its userinfo, so `https://user:token@host/path` reads `https://host/path`.
     *
     * Everything else is left alone: a path, an `ssh://` URL, an scp-style remote and an empty
     * string all come back as they went in, minus any credentials they carried. Redacting rather
     * than dropping the URL keeps the host, which is the part a reader is actually checking.
     */
    public static function withoutCredentials(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        return preg_replace('{^([A-Za-z][A-Za-z0-9+.-]*://)[^/@]*@}', '$1', $url) ?? $url;
    }

    /**
     * The URL as something a page may put in an `href`, or null when it is not one.
     *
     * `.git` and Composer's `git+` prefix are dropped, an scp-style `git@host:vendor/name` is
     * rewritten to the https form it means, credentials are removed, and what is left has to be an
     * http(s) URL with nothing in it that could break out of an attribute. A `javascript:` or
     * `data:` URL from a package's own metadata never becomes a link.
     */
    public static function linkable(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = preg_replace('{^git\+}', '', trim($url)) ?? '';
        $url = preg_replace('{\.git$}', '', $url) ?? '';
        if (preg_match('{^[A-Za-z0-9._~-]+@([A-Za-z0-9.-]+):(?!//)(.+)$}', $url, $m) === 1) {
            $url = 'https://'.$m[1].'/'.$m[2];
        }
        $url = self::withoutCredentials($url) ?? '';

        return preg_match('{^https?://[^\s<>"\']+$}i', $url) === 1 ? $url : null;
    }
}
