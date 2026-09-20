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
     * it in the lock because it has to fetch with it. Every one of these reaches a report.
     *
     * @dataProvider credentialed
     */
    #[DataProvider('credentialed')]
    public function testCredentialsNeverLeaveTheTool(?string $url, ?string $expected): void
    {
        self::assertSame($expected, RepositoryUrl::withoutCredentials($url));
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function credentialed(): iterable
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
        yield 'ssh keeps its host, loses its user' => ['ssh://git@gitlab.internal/team/svc.git', 'ssh://gitlab.internal/team/svc.git'];
        yield 'nothing to strip' => ['https://github.com/vendor/pkg.git', 'https://github.com/vendor/pkg.git'];
        yield 'an @ in the path is not userinfo' => ['https://example.test/~user@home/pkg', 'https://example.test/~user@home/pkg'];
        yield 'an scp-style remote is left as it is' => ['git@github.com:vendor/pkg.git', 'git@github.com:vendor/pkg.git'];
        yield 'not a url at all' => ['/home/someone/vendor/pkg', '/home/someone/vendor/pkg'];
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
        yield 'composer prefixes it' => ['git+https://github.com/vendor/pkg.git', 'https://github.com/vendor/pkg'];
        yield 'an scp-style remote means https' => ['git@github.com:vendor/pkg.git', 'https://github.com/vendor/pkg'];
        yield 'whitespace around it' => ["  https://github.com/vendor/pkg.git\n", 'https://github.com/vendor/pkg'];
        yield 'script in an attribute' => ['javascript:alert(1)', null];
        yield 'a data url' => ['data:text/html;base64,PHNjcmlwdD4=', null];
        yield 'ssh, which a browser cannot open' => ['ssh://git@github.com/vendor/pkg.git', null];
        yield 'a local checkout' => ['/home/someone/vendor/pkg', null];
        yield 'a quote that would break out of the attribute' => ['https://github.com/vendor/pkg"onmouseover="x', null];
        yield 'nothing' => [null, null];
    }
}
