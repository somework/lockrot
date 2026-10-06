<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

/**
 * A package's repository URL, and any text that quotes one, as a report prints it. The URL of a
 * private repository can carry a token, as `https://gitlab-ci-token:$CI_JOB_TOKEN@…` does, and a
 * path on the machine carries the account name. A report must show neither. See
 * docs/schema.md#what-a-report-says-about-your-repositories.
 *
 * @internal
 */
final class RepositoryUrl
{
    /** What a report quotes in place of a message PCRE could not finish reading. */
    public const WITHHELD = '(withheld: lockrot could not redact this message)';

    /** Where a URL starts in a text: a scheme and `://`, or `:\/\/` as JSON escapes it. */
    private const SCHEME = '{(?<![A-Za-z0-9+.\-])[A-Za-z][A-Za-z0-9+.\-]*:(?://|\\\\/\\\\/)}';

    /** Where a path on the machine starts in a text, at a word, a quote or a bracket: Unix, home, drive or share. */
    private const PATH = '{(?<=^|[\s"\'`(\[\{<=,|:])(?:/(?=[^\s/])|~/|[A-Za-z]:[\\\\/]|\\\\\\\\(?=[^\s\\\\]))}';

    /** The user of an scp-style remote in a text, `user@host:path`, which can be a token. */
    private const REMOTE_USER = '{(?<=^|[\s"\'`(\[\{<=,|])[^\s@/\\\\"\'<>`()\[\]\{\}:=,|]+@(?=[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?:(?!//)\S)}';

    /** What closes what an opening character delimits. PHP quotes a path as `` `path' ``. */
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

    /** A path on the machine: absolute, in the home directory, relative, on a drive, or on a share. */
    private const LOCAL = '{^(?:/|~(?:[\\\\/]|$)|\.\.?(?:[\\\\/]|$)|[A-Za-z]:[\\\\/]|\\\\\\\\)}';

    /** A home directory itself, whose name is the account's: `/Users/alice`, `/home/alice`, `C:\Users\alice`, `~`. */
    private const HOME = '{(?:^|[\\\\/])(?:Users|home)[\\\\/][^\\\\/]+$|^~$}i';

    /** A remote without a scheme: an scp-style `[user@]host:path`, or a Perforce `[ssl:]host:port`. */
    private const REMOTE = '{^(?:(?<user>[^@\s/\\\\:]+)@)?(?<remote>(?:(?:ssl|tcp)[46]?:)?(?<host>[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?):(?!//)[^\s\\\\].*)$}';

    /**
     * The repository as a report can print it. A URL loses its userinfo, query and fragment
     * (`https://user:token@host/path?x=1` reads `https://host/path`), and a remote loses its user.
     * A path or a `file://` URL reads by its last segment (`.../lib`). Null for anything else.
     * Redact the URL, do not drop it: the host is what a reader checks.
     *
     * The userinfo runs to the last `@` before the path, since a hand-written password can hold `?`
     * and `#`.
     */
    public static function shown(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }
        if (preg_match('{^(?<scheme>[A-Za-z][A-Za-z0-9+.-]*://)(?:[^/]*@)?(?<rest>[^?#]*)}', $url, $m) === 1) {
            return strcasecmp($m['scheme'], 'file://') === 0 ? self::local(substr($url, 7)) : $m['scheme'].$m['rest'];
        }
        if (preg_match(self::REMOTE, $url, $m) === 1 && ($m['user'] !== '' || strpos($m['host'], '.') !== false)) {
            return $m['remote'];
        }

        return self::isLocalPath($url) ? self::local($url) : null;
    }

    /** Whether a repository or dist URL is a path on the machine, or a `file://` URL. */
    public static function isLocalPath(?string $url): bool
    {
        return $url !== null && (strncasecmp($url, 'file://', 7) === 0 || preg_match(self::LOCAL, $url) === 1);
    }

    /** Whether {@see shown()} gave a path on the machine: every one starts `...`, and no URL or remote does. */
    public static function isLocal(string $shown): bool
    {
        return strncmp($shown, '...', 3) === 0;
    }

    /**
     * The URL as something a page can put in an `href`, or null when it is not one. It drops `.git`
     * and Composer's `git+` prefix, rewrites an scp-style `git@host:vendor/name` to https, and then
     * reads the URL as {@see shown()} leaves it. The result must be an http(s) URL that cannot
     * break out of an attribute, so a `javascript:` or `data:` URL never becomes a link.
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
     * A message as a report can quote it. Each URL and scp-style remote reads as {@see shown()}
     * leaves it. Each path on the machine, `file://` URLs included, reads by its last segment
     * (`.../ca.pem`). Composer masks only a password and an `access_token`, so a token in the user
     * slot or a `?token=` reaches its messages.
     *
     * The text is scanned, not matched as a whole, so its length costs time and never the redaction.
     * A message that PCRE cannot read is {@see WITHHELD}.
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
     * One URL. It ends where the next one starts at the latest. The userinfo runs to the last `@`
     * before the path, so a raw `'`, `)`, `?` or `@` in a password goes with it.
     *
     * @param array<string, list<int>> $closers {@see closers()}
     *
     * @return array{0: string, 1: int} the URL as a report can quote it, and where it ends in $text
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
     * Where each closer that ends a run is, and each line break. Found once per text, so a text
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
     * The end of a path outside quotes, before $limit. It takes the first word, and each next word
     * with a separator in it, as a path with spaces has. A word ends at a character in $stops.
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

    private static function trimmed(string $text, int $from, int $end): int
    {
        return $from + \strlen(rtrim(substr($text, $from, $end - $from), self::TRAILING));
    }

    /** A local repository as a report shows it: its last segment, `...` for the machine's root. */
    private static function local(string $path): string
    {
        $shown = self::lastSegment(substr($path, 0, strcspn($path, '?#')));

        return $shown === '' ? '...' : $shown;
    }

    /**
     * The last segment of a path as `.../name`, split on either separator. The separator after it
     * stays, so what follows reads as it did. Empty for a path of separators alone. A home directory
     * is `...` alone, since its name is the account's.
     */
    private static function lastSegment(string $path): string
    {
        $name = rtrim($path, '/\\');
        if ($name === '') {
            return '';
        }
        $segment = $name === '...' || preg_match(self::HOME, $name) !== 0 ? '...' : '.../'.preg_replace('{^.*[\\\\/]}s', '', $name);

        return $segment.substr($path, \strlen($name));
    }
}
