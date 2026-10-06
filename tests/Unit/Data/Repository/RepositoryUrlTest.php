<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Repository;

use Lockrot\Data\Repository\RepositoryUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepositoryUrlTest extends TestCase
{
    /**
     * A private Composer source is routinely configured with a token in the URL, and Composer keeps
     * it in the lock because it must fetch with it. A VCS repository can be a directory on the
     * machine that wrote the lock. Every one of these reaches a report.
     *
     * @dataProvider repositories
     */
    #[DataProvider('repositories')]
    public function testAReportShowsARepositoryByItsHostNeverByWhatLocatesTheMachine(?string $url, ?string $expected): void
    {
        self::assertSame($expected, RepositoryUrl::shown($url));
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function repositories(): iterable
    {
        yield 'a GitLab CI job token' => [
            'https://gitlab-ci-token:glpat-abcdef123456@gitlab.internal.acme.com/team/service.git',
            'https://gitlab.internal.acme.com/team/service.git',
        ];
        yield 'a Bitbucket app password' => [
            'https://x-token-auth:s3cr3t@bitbucket.org/acme/private-repo.git',
            'https://bitbucket.org/acme/private-repo.git',
        ];
        yield 'a bare user name' => ['https://user@github.com/vendor/pkg.git', 'https://github.com/vendor/pkg.git'];
        yield 'a password with an @ in it' => ['https://ci:p@ss@git.acme.test/lib.git', 'https://git.acme.test/lib.git'];
        yield 'a token in the query' => ['https://git.acme.test/lib.git?private_token=t0k3n#readme', 'https://git.acme.test/lib.git'];
        yield 'a question mark in the password' => ['https://igor:pw?x@git.acme.test/lib.git', 'https://git.acme.test/lib.git'];
        yield 'ssh keeps its host, loses its user' => ['ssh://git@gitlab.internal/team/svc.git', 'ssh://gitlab.internal/team/svc.git'];
        yield 'svn over ssh' => ['svn+ssh://igor@svn.acme.test/repo/trunk', 'svn+ssh://svn.acme.test/repo/trunk'];
        yield 'nothing to strip' => ['https://github.com/vendor/pkg.git', 'https://github.com/vendor/pkg.git'];
        yield 'an @ in the path is not userinfo' => ['https://example.test/~user@home/pkg', 'https://example.test/~user@home/pkg'];
        yield 'an scp-style remote loses its user' => ['git@github.com:vendor/pkg.git', 'github.com:vendor/pkg.git'];
        yield 'an scp-style remote with a login' => ['igor@git.client-x.lan:libs/core.git', 'git.client-x.lan:libs/core.git'];
        yield 'an scp-style remote on a host without a dot' => ['git@buildbox:libs/core.git', 'buildbox:libs/core.git'];
        yield 'an scp-style remote without a user' => ['github.com:vendor/pkg.git', 'github.com:vendor/pkg.git'];
        yield 'a Perforce server' => ['perforce.acme.internal:1666', 'perforce.acme.internal:1666'];
        yield 'a Perforce server over ssl' => ['ssl:perforce.acme.internal:1666', 'ssl:perforce.acme.internal:1666'];
        yield 'an absolute path' => ['/home/someone/vendor/pkg', '.../pkg'];
        yield 'a path in the home directory' => ['~/src/pkg', '.../pkg'];
        yield 'a relative path' => ['../pkg', '.../pkg'];
        yield 'a Windows path' => ['C:\\src\\pkg', '.../pkg'];
        yield 'a Windows path with slashes' => ['C:/src/pkg', '.../pkg'];
        yield 'a network share' => ['\\\\server\\share\\pkg', '.../pkg'];
        yield 'a file url' => ['file:///srv/git/pkg.git', '.../pkg.git'];
        yield 'a file url in capitals' => ['FILE:///srv/git/pkg.git', '.../pkg.git'];
        yield 'a file url with a query' => ['file:///srv/git/pkg.git?token=t', '.../pkg.git'];
        yield 'a file url on Windows' => ['file:///C:/Users/igor/pkg', '.../pkg'];
        yield 'a file url to the home directory' => ['file://~', '...'];
        yield 'a file url to the root' => ['file:///', '...'];
        yield 'a file url of one segment' => ['file://pkg', '.../pkg'];
        yield 'a file: url without the two slashes, which is neither' => ['file:/srv/pkg', null];
        yield 'a home directory' => ['/Users/Alice Smith', '...'];
        yield 'a home directory with a separator after it' => ['/home/alice/', '.../'];
        yield 'a home directory on Windows' => ['C:\\Users\\alice', '...'];
        yield 'the home directory' => ['~', '...'];
        yield 'the root' => ['/', '...'];
        yield 'a directory in the home directory' => ['/Users/alice/lib', '.../lib'];
        yield 'a word, which is neither' => ['lib', null];
        yield 'a host without a dot and no user' => ['buildbox:libs/core.git', null];
        yield 'empty' => ['', ''];
        yield 'nothing' => [null, null];
    }

    /**
     * @dataProvider links
     */
    #[DataProvider('links')]
    public function testOnlyAnHttpUrlWithoutCredentialsBecomesALink(?string $url, ?string $expected): void
    {
        self::assertSame($expected, RepositoryUrl::linkable($url));
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function links(): iterable
    {
        yield 'the usual one' => ['https://github.com/vendor/pkg.git', 'https://github.com/vendor/pkg'];
        yield 'a token never reaches the href' => [
            'https://gitlab-ci-token:glpat-abcdef123456@gitlab.internal.acme.com/team/service.git',
            'https://gitlab.internal.acme.com/team/service',
        ];
        yield 'nor one in the query' => ['https://gitlab.internal.acme.com/team/service.git?private_token=t0k3n', 'https://gitlab.internal.acme.com/team/service'];
        yield 'composer prefixes it' => ['git+https://github.com/vendor/pkg.git', 'https://github.com/vendor/pkg'];
        yield 'an scp-style remote means https' => ['git@github.com:vendor/pkg.git', 'https://github.com/vendor/pkg'];
        yield 'whitespace around it' => ["  https://github.com/vendor/pkg.git\n", 'https://github.com/vendor/pkg'];
        yield 'script in an attribute' => ['javascript:alert(1)', null];
        yield 'a data url' => ['data:text/html;base64,PHNjcmlwdD4=', null];
        yield 'ssh, which a browser cannot open' => ['ssh://git@github.com/vendor/pkg.git', null];
        yield 'a local checkout' => ['/home/someone/vendor/pkg', null];
        yield 'a file url' => ['file:///home/someone/vendor/pkg', null];
        yield 'a quote that would break out of the attribute' => ['https://github.com/vendor/pkg"onmouseover="x', null];
        yield 'nothing' => [null, null];
    }

    /**
     * What Composer, curl and lockrot's own clients say when a repository fails, as the notes and
     * findings quote it: every URL loses its userinfo, query and fragment, and every path on the
     * machine keeps only its last segment. Each input is taken from the message a real failure gives.
     *
     * @dataProvider messages
     */
    #[DataProvider('messages')]
    public function testAMessageKeepsItsWordsAndLosesWhatLocatesOrOpensAnything(string $message, string $expected): void
    {
        self::assertSame($expected, RepositoryUrl::inText($message));
        self::assertSame($expected, RepositoryUrl::inText($expected), 'redacting twice changes nothing');
    }

    /** @return iterable<string, array{string, string}> */
    public static function messages(): iterable
    {
        yield 'a login and a password' => ['composer repo (https://ci-user:s3cr3t@repo.example.com)', 'composer repo (https://repo.example.com)'];
        yield 'a bare token, as Composer 2.4 to 2.9 print it' => ['composer repo (https://glpat-abcdefghij@repo.example.com)', 'composer repo (https://repo.example.com)'];
        yield 'what Composer 2.10 leaves of a token' => ['composer repo (https://glp***@repo.example.com)', 'composer repo (https://repo.example.com)'];
        yield 'a password with an @' => ['composer repo (https://ci:p@ss@repo.example.com)', 'composer repo (https://repo.example.com)'];
        yield 'an encoded login' => ['composer repo (https://ci%40corp:p%40ss@repo.example.com/sub)', 'composer repo (https://repo.example.com/sub)'];
        yield 'a token in the query' => ['composer repo (https://repo.example.com/?token=t0k3n)', 'composer repo (https://repo.example.com/)'];
        yield 'a quoted url' => [
            'The "http://ci-user:s3cr3t@127.0.0.1:8765/p2/acme/boom.json" file could not be downloaded (HTTP/1.1 500 Internal Server Error)',
            'The "http://127.0.0.1:8765/p2/acme/boom.json" file could not be downloaded (HTTP/1.1 500 Internal Server Error)',
        ];
        yield 'a password with a parenthesis' => [
            'The "http://ci:pa)ss@127.0.0.1:8765/p2/acme/boom.json" file could not be downloaded (HTTP/1.1 500 Internal Server Error)',
            'The "http://127.0.0.1:8765/p2/acme/boom.json" file could not be downloaded (HTTP/1.1 500 Internal Server Error)',
        ];
        yield 'a password with an apostrophe' => [
            "The \"http://ci:it's@127.0.0.1:8765/p2/acme/boom.json\" file could not be downloaded (HTTP/1.1 500 Internal Server Error)",
            'The "http://127.0.0.1:8765/p2/acme/boom.json" file could not be downloaded (HTTP/1.1 500 Internal Server Error)',
        ];
        yield 'a url in single quotes, and a second line' => [
            "The 'http://ci-user:***@127.0.0.1:8765/p2/acme/auth.json' URL required authentication (HTTP 401).\nYou must be using the interactive console to authenticate",
            "The 'http://127.0.0.1:8765/p2/acme/auth.json' URL required authentication (HTTP 401).\nYou must be using the interactive console to authenticate",
        ];
        yield 'a curl error' => [
            'curl error 6 while downloading https://ci-user:***@nonexistent.invalid/packages.json: Could not resolve host: nonexistent.invalid',
            'curl error 6 while downloading https://nonexistent.invalid/packages.json: Could not resolve host: nonexistent.invalid',
        ];
        yield 'the colon after a query' => [
            'curl error 28 while downloading https://repo.example.com/p2/a/b.json?token=t0k3n: Operation timed out',
            'curl error 28 while downloading https://repo.example.com/p2/a/b.json: Operation timed out',
        ];
        yield 'the full stop after a query' => ['Network disabled, request canceled: https://repo.example.com/packages.json?key=abc.', 'Network disabled, request canceled: https://repo.example.com/packages.json.'];
        yield 'what Composer masks itself' => [
            'The "https://api.github.com/repositories/1?access_token=***" file could not be downloaded (HTTP/2 401 )',
            'The "https://api.github.com/repositories/1" file could not be downloaded (HTTP/2 401 )',
        ];
        yield 'a local repository' => ['composer repo (file:///Users/igor/client-x/repo)', 'composer repo (file://.../repo)'];
        yield 'a local repository with a space' => ['composer repo (file:///Users/Igor Pinchuk/client-x/repo)', 'composer repo (file://.../repo)'];
        yield 'a local repository on Windows' => ['composer repo (file://C:\\Users\\Igor Pinchuk\\repo)', 'composer repo (file://.../repo)'];
        yield 'a local repository on a share' => ['composer repo (file://\\\\server\\share\\repo)', 'composer repo (file://.../repo)'];
        yield 'a quoted local file' => [
            'The "file:///Users/Igor Pinchuk/repo/p2/acme/missing.json" file could not be downloaded: Failed to open stream: No such file or directory',
            'The "file://.../missing.json" file could not be downloaded: Failed to open stream: No such file or directory',
        ];
        yield 'a local file in single quotes' => ["The 'file:///Users/Igor Pinchuk/repo/p2/acme/a.json' URL could not be read", "The 'file://.../a.json' URL could not be read"];
        yield 'a local repository with a trailing separator' => ['composer repo (file:///srv/satis/)', 'composer repo (file://.../satis/)'];
        yield 'a quoted local file on Windows' => ['"file:///C:/Users/igor/repo/p2/acme/bad.json" does not contain valid JSON', '"file://.../bad.json" does not contain valid JSON'];
        yield 'two local urls and parentheses in the words' => [
            'Could not load packages in composer repo (file:///srv/r) from file:///srv/r/p2/a/b.json: [UnexpectedValueException] Invalid version string "x (y)"',
            'Could not load packages in composer repo (file://.../r) from file://.../b.json: [UnexpectedValueException] Invalid version string "x (y)"',
        ];
        yield "lockrot's own query" => ['invalid JSON from https://api.bitbucket.org/2.0/repositories/acme/lib/commits?pagelen=1', 'invalid JSON from https://api.bitbucket.org/2.0/repositories/acme/lib/commits'];
        yield 'an e-mail beside a url' => ['{"url":"https://host.example","email":"a@b.example"}', '{"url":"https://host.example","email":"a@b.example"}'];
        yield 'an @ in a path, and mailto' => ['see https://host.example/path@v2/x and mailto:me@x.example', 'see https://host.example/path@v2/x and mailto:me@x.example'];
        yield 'an @ in a query with no path before it takes the host with it, and leaks nothing' => ['https://host.example?next=a@b', 'https://b'];
        yield 'a second url after a comma' => ['mirrors: https://u:p@h.example/p,https://q:r@h2.example/p', 'mirrors: https://h.example/p,https://h2.example/p'];
        yield 'a second url after a semicolon' => ['https://u:p@h.example/p;https://q:r@h2.example/p', 'https://h.example/p;https://h2.example/p'];
        yield 'a second url with nothing between' => ['https://a:b@h.example/phttps://c:d@h2.example/q', 'https://h.example/phttps://h2.example/q'];
        yield 'a question mark in the password' => ['from https://igor:pw?x@repo.acme.test/p', 'from https://repo.acme.test/p'];
        yield 'a hash in the password' => ['from https://igor:pw#x@repo.acme.test/p', 'from https://repo.acme.test/p'];
        yield 'a url escaped for json' => ['{"url":"https:\\/\\/user:pw@h.example\\/p?token=t"}', '{"url":"https:\\/\\/h.example\\/p"}'];
        yield 'a scheme that starts like file' => ['from files://u:p@h.example/x', 'from files://h.example/x'];
        yield 'a url right after a local one' => ['file:///srv/a/b,https://u:p@h.example/x', 'file://.../b,https://h.example/x'];
        yield 'an empty local url' => ['see "file://" and "/Users/igor/x/y"', 'see "file://" and ".../y"'];
        yield 'a url, then a word with an @' => ['see https://h.example and write to a@b.example', 'see https://h.example and write to a@b.example'];
        yield 'an scp-style remote with a login' => ['failed to clone ci-user@git.acme.test:team/private.git now', 'failed to clone git.acme.test:team/private.git now'];
        yield 'an scp-style remote with a token for a user' => ["cannot read 'glpat-x9secretsecret@gitlab.acme.test:team/p.git'", "cannot read 'gitlab.acme.test:team/p.git'"];
        yield 'an e-mail address is not a remote' => ['write to a@b.example: soon, or a@b.example', 'write to a@b.example: soon, or a@b.example'];
        yield 'an scp-style remote on a host without a dot' => ["cannot read 'glpat-x9secretsecret@buildbox:team/private.git'", "cannot read 'buildbox:team/private.git'"];
        yield 'an address on a host without a dot is not a remote' => ['mail root@localhost: now', 'mail root@localhost: now'];
        yield 'a local file with a space and no quotes' => ['trust anchors from file:///Users/Alice Smith/client-x9/ca.pem now', 'trust anchors from file://.../ca.pem now'];
        yield 'a local file with a space, then a url' => ['file:///Users/Alice Smith/x https://u:p@h.example/y', 'file://.../x https://h.example/y'];
        yield 'a local file with a space, up to a url glued to it' => ['file:///Users/Alice Smith/x/https://u:p@h.example/y', 'file://.../x/https://h.example/y'];
        yield 'a local file with a space, at the end' => ['from file:///Users/Alice Smith/ca.pem', 'from file://.../ca.pem'];
        yield 'a local file with a space and a query, up to a url glued to it' => ['file:///Users/Alice Smith/x/?https://u:p@h.example/y', 'file://.../x/https://h.example/y'];
        yield 'an apostrophe in a local file with no quotes' => ["from file:///Users/O'Brien/ca.pem now", 'from file://.../ca.pem now'];
        yield 'a local file with a query' => ['"file:///home/alice/ca.pem?token=secret" x', '"file://.../ca.pem" x'];
        yield 'a local file with a fragment' => ['(file:///home/alice/ca.pem#frag) y', '(file://.../ca.pem) y'];
        yield 'a port stays' => ['from https://repo.acme.test:8443/p2/a.json', 'from https://repo.acme.test:8443/p2/a.json'];
        yield 'ssh, and an scp-style remote' => ['ssh://git@github.com/acme/lib.git; git@github.com:acme/lib.git', 'ssh://github.com/acme/lib.git; github.com:acme/lib.git'];
        yield 'a certificate file curl could not read' => [
            'curl error 77 while downloading https://repo.example.com/packages.json: error adding trust anchors from file: /Users/igor/client-x/certs/ca.pem',
            'curl error 77 while downloading https://repo.example.com/packages.json: error adding trust anchors from file: .../ca.pem',
        ];
        yield "a corrupt file in Composer's cache" => [
            '"/home/runner/.cache/composer/repo/https---repo.example.com-t0k3n/packages.json" does not contain valid JSON',
            '".../packages.json" does not contain valid JSON',
        ];
        yield "a corrupt file in Composer's cache on Windows" => [
            '"C:\\Users\\Igor Pinchuk\\AppData\\Local\\Composer\\repo\\x\\packages.json" does not contain valid JSON',
            '".../packages.json" does not contain valid JSON',
        ];
        yield 'a path in the home directory' => ['failed to clone ~/src/lib.', 'failed to clone .../lib.'];
        yield 'a home directory in a message' => ['cannot write to /home/alice: denied', 'cannot write to ...: denied'];
        yield 'a home directory in quotes' => ['"C:\\Users\\Alice Smith" is not writable', '"..." is not writable'];
        yield 'a path in single quotes' => ["cannot read '/Users/Igor Pinchuk/certs/ca.pem'", "cannot read '.../ca.pem'"];
        yield 'a path as PHP quotes it' => ["failed loading cafile stream: `/home/igor/acme-client/ca.pem'", "failed loading cafile stream: `.../ca.pem'"];
        yield 'a Windows path as PHP quotes it' => ["failed loading cafile stream: `C:\\Users\\igor\\ca.pem'", "failed loading cafile stream: `.../ca.pem'"];
        yield 'a path after a colon' => ['path:/home/igor/x', 'path:.../x'];
        yield 'a path after a quote that does not close' => ['"/home/igor/x', '".../x'];
        yield 'a Windows path with a space' => ['cannot open C:\\Users\\Igor Pinchuk\\repo', 'cannot open .../repo'];
        yield 'a Windows path with a space in parentheses' => ['file_put_contents(C:\\Users\\Igor Pinchuk\\AppData\\Local\\Composer\\repo\\x.json): Failed to open stream', 'file_put_contents(.../x.json): Failed to open stream'];
        yield 'a parenthesis in a path in parentheses' => ['file_put_contents(C:\\Program Files (x86)\\Composer\\x.json): Failed', 'file_put_contents(.../x.json): Failed'];
        yield 'two segments' => ['cafile=/etc/ca.pem', 'cafile=.../ca.pem'];
        yield 'a directory of one segment' => ['404 for /downloads/', '404 for /downloads/'];
        yield 'a path after one of one segment' => ['404 for /downloads, see /Users/igor/x/y', '404 for /downloads, see .../y'];
        yield 'several quoted paths' => ['"/a/b/c" and "/d/e/f" and "/g/h/i" and "/j/k/l"', '".../c" and ".../f" and ".../i" and ".../l"'];
        yield 'a quoted path whose quote closes on the next line' => ["\"/Users/igor/x\n/srv/a/b\"", "\".../x\n.../b\""];
        yield 'a path that opens a text ending in a quote' => ['/Users/igor/x "/a/b" "', '.../x ".../b" "'];
        yield 'a path after a quoted one with a path inside it' => ['"/Users/igor/a /x/y" and /Users/igor/z/w', '".../y" and .../w'];
        yield 'a path with spaces in two places' => ['fopen /Users/igor/Acme Corp/New Client/x failed', 'fopen .../x failed'];
        yield 'a next word whose separator ends it' => ['cannot open C:\\Program Files\\Acme Corp\\ now', 'cannot open .../Acme Corp\\ now'];
        yield 'a next word whose separator starts it' => ['cannot read /Users/igor/a /b now', 'cannot read .../b now'];
        yield 'a directory whose name starts with a space' => ['fopen /Users/igor/Acme Corp/ Client/x failed', 'fopen .../x failed'];
        yield 'a path with a space' => ['fopen /Users/igor/Clients/Acme Corp/app/vendor/x failed', 'fopen .../x failed'];
        yield 'a path after an equals sign' => ['cafile=/etc/ssl/private/acme.pem', 'cafile=.../acme.pem'];
        yield 'a path in parentheses' => ['cannot read (/Users/igor/x/y): denied', 'cannot read (.../y): denied'];
        yield 'a network share' => ['cannot open \\\\server\\share\\repo', 'cannot open .../repo'];
        yield 'a Windows path with slashes' => ['cannot open C:/Users/igor/repo', 'cannot open .../repo'];
        yield 'a url path is not a machine path' => ['from https://repo.example.com/p2/acme/lib.json', 'from https://repo.example.com/p2/acme/lib.json'];
        yield 'one segment is no machine path' => ['404 for /downloads', '404 for /downloads'];
        yield 'a protocol version is not one' => ['HTTP/1.1 500 Internal Server Error', 'HTTP/1.1 500 Internal Server Error'];
        yield 'a package name is not one' => ['acme/lib could not be found', 'acme/lib could not be found'];
        yield "lockrot's own reasons" => ["offline: not present in Composer's cache", "offline: not present in Composer's cache"];
        yield 'the budget' => ['not checked: install-time budget exhausted', 'not checked: install-time budget exhausted'];
        yield 'a status' => ['HTTP 502', 'HTTP 502'];
        yield 'nothing' => ['', ''];
    }

    /** A server writes part of what a failure says, so no length or shape of it can switch the redaction off. */
    public function testALongMessageIsRedactedWhole(): void
    {
        $noise = 'see http://status.acme.test/'.str_repeat('.', 5000).'x at /Users/igor/'.str_repeat('a/', 10000);

        self::assertSame(
            'see http://status.acme.test/'.str_repeat('.', 5000).'x at .../a/ and https://repo.acme.test/p',
            RepositoryUrl::inText($noise.' and https://ci:s3cr3t@repo.acme.test/p')
        );
    }

    /**
     * What PCRE cannot finish is withheld, never passed on as it came. The limits are set when a
     * PHP starts: at run time, whether a pattern compiled earlier honours them varies with the build.
     */
    public function testAMessageThatCannotBeRedactedIsWithheld(): void
    {
        $script = 'require '.var_export(\dirname(__DIR__, 4).'/vendor/autoload.php', true).'; echo '.RepositoryUrl::class.'::inText("from https://ci:s3cr3t@repo.acme.test/p");';
        $output = shell_exec(escapeshellarg(\PHP_BINARY).' -d pcre.jit=0 -d pcre.backtrack_limit=1 -r '.escapeshellarg($script));

        self::assertSame(RepositoryUrl::WITHHELD, $output);
    }
}
