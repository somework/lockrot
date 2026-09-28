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
    /** What a report quotes in place of a message PCRE could not finish reading. */
    public const WITHHELD = '(withheld: lockrot could not redact this message)';

    /** Where a URL starts in a text: a scheme and `://`, or `:\/\/` as JSON escapes it. */
    private const SCHEME = '{(?<![A-Za-z0-9+.\-])[A-Za-z][A-Za-z0-9+.\-]*:(?://|\\\\/\\\\/)}';

    /** Where a path on the machine starts in a text: at a word, a quote or a bracket; Unix, home, a drive or a share. */
    private const PATH = '{(?<=^|[\s"\'`(\[\{<=,|:])(?:/(?=[^\s/])|~/|[A-Za-z]:[\\\\/]|\\\\\\\\(?=[^\s\\\\]))}';

    /** The user of an scp-style remote in a text, `user@host:path`, which may be a token. */
    private const REMOTE_USER = '{(?<=^|[\s"\'`(\[\{<=,|])[^\s@/\\\\"\'<>`()\[\]\{\}:=,|]+@(?=[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?:(?!//)\S)}';

    /** What closes what an opening character delimits; PHP quotes a path as `` `path' ``. */
    private const CLOSERS = ['"' => '"', "'" => "'", '`' => "'", '(' => ')', '[' => ']', '{' => '}', '<' => '>'];

    /**
     * A closer that ends what it closes: one followed by whitespace, punctuation or the end, so a `'`
     * or a `)` inside a path does not. A line break ends every delimited run.
     */
    private const CLOSING = '{["\')\]\}>](?=[\s:;,.!)\]\}>"\']|$)|[\r\n]}';

    /** What ends a URL's authority: its path, or a JSON-escaped one, whitespace, a quote, an angle bracket. */
    private const AUTHORITY_END = "/\\\"<> \t\r\n\v\f";

    /** What ends a URL, and a word of a local URL no quote delimits. */
    private const URL_END = "\"'<>`) \t\r\n\v\f";
    private const WHITESPACE = " \t\r\n\v\f";

    /** What ends a word of a path outside quotes. */
    private const PATH_END = "\"'<>` \t\r\n\v\f";

    /** Sentence punctuation after a URL or a path, which stays text. */
    private const TRAILING = '.,:;!';

    /** A remote without a scheme: an scp-style `[user@]host:path`, or a Perforce `[ssl:]host:port`. */
    private const REMOTE = '{^(?:(?<user>[^@\s/\\\\:]+)@)?(?<remote>(?:(?:ssl|tcp)[46]?:)?(?<host>[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?):(?!//)[^\s\\\\].*)$}';

    /**
     * The repository as a report may print it: a URL without its userinfo, query and fragment, so
     * `https://user:token@host/path?private_token=…` reads `https://host/path`, and a remote without
     * a scheme without its user, so `igor@git.acme.test:lib.git` reads `git.acme.test:lib.git`.
     * Null for what locates the machine rather than a server: a path, or a `file://` URL.
     *
     * The userinfo runs to the last `@` before the path, `?` and `#` included, since a password
     * written by hand is not always encoded. Redacting rather than dropping a URL keeps the host,
     * which is the part a reader is actually checking.
     */
    public static function shown(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }
        if (preg_match('{^(?<scheme>[A-Za-z][A-Za-z0-9+.-]*://)(?:[^/]*@)?(?<rest>[^?#]*)}', $url, $m) === 1) {
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
     * A message as a report may quote it: every URL and scp-style remote in it as {@see shown()}
     * leaves one, a `file://` URL and every other path on the machine down to its last segment
     * (`.../ca.pem`), and every other word as it was. Composer masks only a password and an `access_token`; a token in the user
     * slot, a login, a `?token=` and the path of an unreadable certificate all reach its messages.
     *
     * The text is scanned, not matched as a whole, so its length costs time and never the redaction;
     * a message PCRE still cannot read is {@see WITHHELD} rather than passed on as it came.
     */
    public static function inText(string $text): string
    {
        $urls = self::urlsIn($text);
        $remotes = $urls === null ? null : preg_replace(self::REMOTE_USER, '', $urls);
        $paths = $remotes === null ? null : self::pathsIn($remotes);

        return $paths ?? self::WITHHELD;
    }

    private static function urlsIn(string $text): ?string
    {
        $closers = self::closers($text);
        if ($closers === null || preg_match_all(self::SCHEME, $text, $m, \PREG_OFFSET_CAPTURE) === false) {
            return null;
        }
        $starts = array_column($m[0], 1);
        $out = '';
        $at = 0;
        foreach ($m[0] as $i => [$scheme, $offset]) {
            if ($offset < $at) {
                continue;
            }
            $out .= substr($text, $at, $offset - $at);
            [$url, $at] = self::url($text, $offset, $scheme, $starts[$i + 1] ?? \strlen($text), $closers);
            $out .= $url;
        }

        return $out.substr($text, $at);
    }

    /**
     * One URL, which ends where the next one starts at the latest: the userinfo runs to the last `@`
     * before the path, so a raw `'`, `)`, `?` or `@` in a password goes with it.
     *
     * @param array<string, list<int>> $closers {@see closers()}
     *
     * @return array{0: string, 1: int} the URL as a report may quote it, and where it ends in $text
     */
    private static function url(string $text, int $offset, string $scheme, int $limit, array $closers): array
    {
        $start = $offset + \strlen($scheme);
        if (strncasecmp($scheme, 'file:', 5) === 0) {
            $end = self::closer($text, $offset, $start, $closers) ?? self::pathEnd($text, $start, self::WHITESPACE, $limit);

            $path = substr($text, $start, $end - $start);

            return [$scheme.self::lastSegment(substr($path, 0, strcspn($path, '?#'))), $end];
        }
        // The next URL's scheme ends in `:/` or `:\\`, so the authority never runs into it.
        $authority = substr($text, $start, strcspn($text, self::AUTHORITY_END, $start));
        $userinfo = strrpos($authority, '@');
        $host = $userinfo === false ? $start : $start + $userinfo + 1;
        $end = self::trimmed($text, $host, $host + strcspn($text, self::URL_END, $host, $limit - $host));
        $kept = substr($text, $host, $end - $host);

        return [$scheme.substr($kept, 0, strcspn($kept, '?#')), $end];
    }

    private static function pathsIn(string $text): ?string
    {
        $closers = self::closers($text);
        if ($closers === null || preg_match_all(self::PATH, $text, $m, \PREG_OFFSET_CAPTURE) === false) {
            return null;
        }
        $out = '';
        $at = 0;
        foreach ($m[0] as [, $offset]) {
            if ($offset < $at) {
                continue;
            }
            $end = self::closer($text, $offset, $offset, $closers) ?? self::pathEnd($text, $offset, self::PATH_END, \strlen($text));
            $path = substr($text, $offset, $end - $offset);
            // `/downloads` is a URL's path more often than a directory, and locates nothing on its own.
            if ($path[0] === '/' && substr_count(rtrim($path, '/'), '/') < 2) {
                continue;
            }
            $out .= substr($text, $at, $offset - $at).self::lastSegment($path);
            $at = $end;
        }

        return $out.substr($text, $at);
    }

    /**
     * Where each closer that ends a run is, and each line break, found once per text so that a text
     * full of quotes costs no more than one without.
     *
     * @return null|array<string, list<int>> offsets by character, a line break under "\n"
     */
    private static function closers(string $text): ?array
    {
        if (preg_match_all(self::CLOSING, $text, $m, \PREG_OFFSET_CAPTURE) === false) {
            return null;
        }
        $closers = ['"' => [], "'" => [], ')' => [], ']' => [], '}' => [], '>' => [], "\n" => []];
        foreach ($m[0] as [$char, $offset]) {
            $closers[$char === "\r" ? "\n" : $char][] = $offset;
        }

        return $closers;
    }

    /**
     * Where what the character before $offset opened closes, on the same line. Null when nothing
     * opened it, or it does not close.
     *
     * @param array<string, list<int>> $closers {@see closers()}
     */
    private static function closer(string $text, int $offset, int $from, array $closers): ?int
    {
        $closer = self::CLOSERS[$offset === 0 ? '' : $text[$offset - 1]] ?? null;
        if ($closer === null) {
            return null;
        }
        $at = self::first($closers[$closer], $from);
        $line = self::first($closers["\n"], $from);

        return $at !== null && ($line === null || $at < $line) ? $at : null;
    }

    /**
     * The first offset in a sorted list at or after $from.
     *
     * @param list<int> $offsets
     */
    private static function first(array $offsets, int $from): ?int
    {
        $low = 0;
        $high = \count($offsets);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($offsets[$middle] < $from) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $offsets[$low] ?? null;
    }

    /**
     * The end of a path outside quotes, before $limit: its word, and each next word with a separator
     * in it, as a path with spaces has. A word ends at a character in $stops.
     */
    private static function pathEnd(string $text, int $from, string $stops, int $limit): int
    {
        $end = $from + strcspn($text, $stops, $from, $limit - $from);
        while ($end < $limit && $text[$end] === ' ') {
            $word = substr($text, $end + 1, strcspn($text, $stops, $end + 1, $limit - $end - 1));
            if (strpbrk($word, '/\\') === false || strpos($word, '://') !== false) {
                break;
            }
            $end += 1 + \strlen($word);
        }

        return self::trimmed($text, $from, $end);
    }

    /** $end moved back over sentence punctuation, which stays text. */
    private static function trimmed(string $text, int $from, int $end): int
    {
        return $from + \strlen(rtrim(substr($text, $from, $end - $from), self::TRAILING));
    }

    /**
     * `.../name`, the last segment of a path split on either separator, and the separator after it,
     * so what follows reads as it did; nothing for a path of separators alone.
     */
    private static function lastSegment(string $path): string
    {
        $name = rtrim($path, '/\\');

        return $name === '' ? '' : '.../'.preg_replace('{^.*[\\\\/]}s', '', $name).substr($path, \strlen($name));
    }
}
