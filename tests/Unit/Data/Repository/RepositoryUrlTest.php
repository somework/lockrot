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
     * it in the lock because it has to fetch with it; a VCS repository can be a directory on the
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
        yield 'an absolute path' => ['/home/someone/vendor/pkg', null];
        yield 'a path in the home directory' => ['~/src/pkg', null];
        yield 'a relative path' => ['../pkg', null];
        yield 'a Windows path' => ['C:\\src\\pkg', null];
        yield 'a Windows path with slashes' => ['C:/src/pkg', null];
        yield 'a network share' => ['\\\\server\\share\\pkg', null];
        yield 'a file url' => ['file:///srv/git/pkg.git', null];
        yield 'a file url in capitals' => ['FILE:///srv/git/pkg.git', null];
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
        yield 'a local repository with a trailing separator' => ['composer repo (file:///srv/satis/)', 'composer repo (file://.../satis)'];
        yield 'a quoted local file on Windows' => ['"file:///C:/Users/igor/repo/p2/acme/bad.json" does not contain valid JSON', '"file://.../bad.json" does not contain valid JSON'];
        yield 'two local urls and parentheses in the words' => [
            'Could not load packages in composer repo (file:///srv/r) from file:///srv/r/p2/a/b.json: [UnexpectedValueException] Invalid version string "x (y)"',
            'Could not load packages in composer repo (file://.../r) from file://.../b.json: [UnexpectedValueException] Invalid version string "x (y)"',
        ];
        yield "lockrot's own query" => ['invalid JSON from https://api.bitbucket.org/2.0/repositories/acme/lib/commits?pagelen=1', 'invalid JSON from https://api.bitbucket.org/2.0/repositories/acme/lib/commits'];
        yield 'an e-mail beside a url' => ['{"url":"https://host.example","email":"a@b.example"}', '{"url":"https://host.example","email":"a@b.example"}'];
        yield 'an @ in a path, and mailto' => ['see https://host.example/path@v2/x and mailto:me@x.example', 'see https://host.example/path@v2/x and mailto:me@x.example'];
        yield 'an @ in a query' => ['https://host.example?next=a@b', 'https://host.example'];
        yield 'ssh, and an scp-style remote' => ['ssh://git@github.com/acme/lib.git; git@github.com:acme/lib.git', 'ssh://github.com/acme/lib.git; git@github.com:acme/lib.git'];
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
        yield 'a path in single quotes' => ["cannot read '/Users/Igor Pinchuk/certs/ca.pem'", "cannot read '.../ca.pem'"];
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
}
