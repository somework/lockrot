<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

/**
 * A package's repository URL, and any text that quotes one, on its way out of lockrot and into
 * something a person reads.
 *
 * The value is whatever the package's `source.url` or `support.source` says, and for a private
 * repository that is routinely a URL with credentials in it: `https://gitlab-ci-token:$CI_JOB_TOKEN@…`
 * is how GitLab CI hands a job access to a private Composer source, and Bitbucket's app passwords
 * take the same shape. Composer keeps them because it has to fetch with them. lockrot only ever
 * shows them, and a report — a terminal buffer pasted into a ticket, a JSON file uploaded as a CI
 * artifact, an HTML page published in a blog post — is exactly where a token should never appear.
 * Nor should a path on the machine that ran: it carries the account and often the client's
 * directory name, which is why a report names its lock and never locates it.
 *
 * @internal
 */
final class RepositoryUrl
{
    /**
     * A URL anywhere in a text: its userinfo runs to the last `@` of the authority, which may hold a
     * raw `'` or `)`; the rest ends before sentence punctuation. A `file://` URL runs to the quote or
     * parenthesis that opened it, since realpath() spells it with spaces and backslashes.
     */
    private const URL_IN_TEXT = '{
        (?<![A-Za-z0-9+.-])(?<scheme>[A-Za-z][A-Za-z0-9+.-]*://)
        (?:
            (?<=\x22file://)(?<double>[^\x22\r\n]*)
          | (?<=\x27file://)(?<single>[^\x27\r\n]*)
          | (?<=\(file://)(?<parenthesised>[^\r\n]*?)(?=\)(?:[\s:;,.]|$))
          | (?:[^\s/?\x23\x22<>]*@)?(?<rest>[^\s\x22\x27<>)?\x23]*?)(?:[?\x23][^\s\x22\x27<>)]*?)?(?=[.,:;!]*(?:[\s\x22\x27<>)]|$))
        )
    }xi';

    /**
     * A path on the machine outside a URL, where a word starts: Unix with at least two segments, the
     * home directory, a drive or a network share. Quoted, it runs to the closing quote.
     */
    private const PATH_IN_TEXT = '{
        (?<=\x22)(?<double>(?:/|~/|[A-Za-z]:[\\\\/]|\\\\\\\\)[^\x22\r\n]*)(?=\x22)
      | (?<=\x27)(?<single>(?:/|~/|[A-Za-z]:[\\\\/]|\\\\\\\\)[^\x27\r\n]*)(?=\x27)
      | (?<=^|[\s(=])(?<bare>(?:/[^\s/\x22\x27<>()]+(?:/[^\s/\x22\x27<>()]+)+|~/|[A-Za-z]:[\\\\/]|\\\\\\\\[^\s\\\\\x22\x27<>()]+\\\\)[^\s\x22\x27<>()]*?)(?=[.,:;!]*(?:[\s\x22\x27<>()]|$))
    }x';

    /** A remote without a scheme: an scp-style `[user@]host:path`, or a Perforce `[ssl:]host:port`. */
    private const REMOTE = '{^(?:(?<user>[^@\s/\\\\:]+)@)?(?<remote>(?:(?:ssl|tcp)[46]?:)?(?<host>[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?):(?!//)[^\s\\\\].*)$}';

    /**
     * The repository as a report may print it: a URL without its userinfo, query and fragment, so
     * `https://user:token@host/path?private_token=…` reads `https://host/path`, and a remote without
     * a scheme without its user, so `igor@git.acme.test:lib.git` reads `git.acme.test:lib.git`.
     * Null for what locates the machine rather than a server: a path, or a `file://` URL.
     *
     * Redacting rather than dropping a URL keeps the host, which is the part a reader is actually
     * checking.
     */
    public static function shown(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }
        if (preg_match('{^(?<scheme>[A-Za-z][A-Za-z0-9+.-]*://)(?:[^/?#]*@)?(?<rest>[^?#]*)}', $url, $m) === 1) {
            return strcasecmp($m['scheme'], 'file://') === 0 ? null : $m['scheme'].$m['rest'];
        }
        if (preg_match(self::REMOTE, $url, $m) === 1 && ($m['user'] !== '' || strpos($m['host'], '.') !== false)) {
            return $m['remote'];
        }

        return null;
    }

    /**
     * The URL as something a page may put in an `href`, or null when it is not one.
     *
     * `.git` and Composer's `git+` prefix are dropped, an scp-style `git@host:vendor/name` is
     * rewritten to the https form it means, the rest goes as {@see shown()} leaves it, and what is
     * left has to be an http(s) URL with nothing in it that could break out of an attribute. A
     * `javascript:` or `data:` URL from a package's own metadata never becomes a link.
     */
    public static function linkable(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = preg_replace('{^git\+}', '', trim($url)) ?? '';
        if (preg_match('{^[A-Za-z0-9._~-]+@([A-Za-z0-9.-]+):(?!//)(.+)$}', $url, $m) === 1) {
            $url = 'https://'.$m[1].'/'.$m[2];
        }
        $url = preg_replace('{\.git$}', '', self::shown($url) ?? '') ?? '';

        return preg_match('{^https?://[^\s<>"\']+$}i', $url) === 1 ? $url : null;
    }

    /**
     * A message as a report may quote it: every URL in it as {@see shown()} leaves one, a `file://`
     * URL and every other path on the machine down to its last segment (`.../ca.pem`), and every
     * other word as it was. Composer masks only a password and an `access_token`; a token in the user
     * slot, a login, a `?token=` and the path of an unreadable certificate all reach its messages.
     */
    public static function inText(string $text): string
    {
        $text = preg_replace_callback(self::URL_IN_TEXT, static function (array $m): string {
            if (strcasecmp($m['scheme'], 'file://') !== 0) {
                return $m['scheme'].($m['rest'] ?? '');
            }
            $delimited = ($m['double'] ?? '').($m['single'] ?? '').($m['parenthesised'] ?? '');

            return $m['scheme'].self::lastSegment($delimited === '' ? ($m['rest'] ?? '') : $delimited);
        }, $text) ?? $text;

        return preg_replace_callback(self::PATH_IN_TEXT, static function (array $m): string {
            return self::lastSegment($m['double'].($m['single'] ?? '').($m['bare'] ?? ''));
        }, $text) ?? $text;
    }

    /** `.../name`, the last segment of a path split on either separator. */
    private static function lastSegment(string $path): string
    {
        return '.../'.preg_replace('{^.*[\\\\/]}s', '', rtrim($path, '/\\'));
    }
}
