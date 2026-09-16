<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Forge;

use Composer\Config;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\RepoRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepoLocatorTest extends TestCase
{
    /**
     * @param array{string, string, string}|null $expected forge, host, path
     *
     * @dataProvider urls
     */
    #[DataProvider('urls')]
    public function testLocate(?string $url, ?array $expected): void
    {
        $ref = (new RepoLocator())->locate($url);
        self::assertSame($expected, $ref === null ? null : [$ref->forge(), $ref->host(), $ref->path()]);
    }

    /** @return iterable<string, array{?string, array{string, string, string}|null}> */
    public static function urls(): iterable
    {
        yield 'github https .git' => ['https://github.com/Grandt/PHPZip.git', [RepoRef::GITHUB, 'github.com', 'Grandt/PHPZip']];
        yield 'github https bare' => ['https://github.com/ralouphie/getallheaders', [RepoRef::GITHUB, 'github.com', 'ralouphie/getallheaders']];
        yield 'github scp' => ['git@github.com:php-fig/cache.git', [RepoRef::GITHUB, 'github.com', 'php-fig/cache']];
        yield 'github git://' => ['git://github.com/lox/xhprof.git', [RepoRef::GITHUB, 'github.com', 'lox/xhprof']];
        yield 'github dots' => ['https://github.com/owner/repo.with.dots.git', [RepoRef::GITHUB, 'github.com', 'owner/repo.with.dots']];
        yield 'github ssh://' => ['ssh://git@github.com/o/r.git', [RepoRef::GITHUB, 'github.com', 'o/r']];
        yield 'github scp with a slash' => ['git@github.com/o/r.git', [RepoRef::GITHUB, 'github.com', 'o/r']];
        yield 'bare host and path is not a clone url' => ['github.com/o/r.git', null];
        yield 'github owner with dot' => ['https://github.com/owner.name/repo', [RepoRef::GITHUB, 'github.com', 'owner.name/repo']];
        yield 'github trailing slash' => ['https://github.com/o/r/', [RepoRef::GITHUB, 'github.com', 'o/r']];
        yield 'github uppercase host' => ['https://GitHub.com/o/r.git', [RepoRef::GITHUB, 'github.com', 'o/r']];
        yield 'github www' => ['https://www.github.com/o/r.git', [RepoRef::GITHUB, 'github.com', 'o/r']];
        yield 'github web page' => ['https://github.com/o/r/tree/main', null];
        yield 'github one segment' => ['https://github.com/o', null];
        yield 'github host only' => ['https://github.com', null];
        yield 'path repository url' => ['file:///srv/mirrors/repo.git', null];
        yield 'gitlab.com' => ['https://gitlab.com/owner/repo.git', [RepoRef::GITLAB, 'gitlab.com', 'owner/repo']];
        yield 'gitlab.com subgroups' => ['https://gitlab.com/group/sub/deeper/project.git', [RepoRef::GITLAB, 'gitlab.com', 'group/sub/deeper/project']];
        yield 'gitlab.com scp' => ['git@gitlab.com:group/sub/project.git', [RepoRef::GITLAB, 'gitlab.com', 'group/sub/project']];
        yield 'gitlab.com scp with a slash' => ['git@gitlab.com/group/project.git', [RepoRef::GITLAB, 'gitlab.com', 'group/project']];
        yield 'gitlab.com web page' => ['https://gitlab.com/group/project/-/tree/main', null];
        yield 'gitlab.com one segment' => ['https://gitlab.com/group', null];
        yield 'bitbucket https' => ['https://bitbucket.org/workspace/repo.git', [RepoRef::BITBUCKET, 'bitbucket.org', 'workspace/repo']];
        yield 'bitbucket https with user' => ['https://user@bitbucket.org/workspace/repo.git', [RepoRef::BITBUCKET, 'bitbucket.org', 'workspace/repo']];
        yield 'bitbucket scp' => ['git@bitbucket.org:workspace/repo.git', [RepoRef::BITBUCKET, 'bitbucket.org', 'workspace/repo']];
        yield 'bitbucket three segments' => ['https://bitbucket.org/workspace/repo/src', null];
        yield 'unknown host' => ['https://git.example.com/private/thing.git', null];
        yield 'unknown scp host' => ['git@git.example.com:private/thing.git', null];
        yield 'null' => [null, null];
        yield 'empty' => ['', null];
        yield 'garbage' => ['not a url', null];
    }

    public function testASelfHostedGitlabIsRecognisedOnlyWhenItIsAConfiguredDomain(): void
    {
        $url = 'https://git.example.com/group/project.git';
        self::assertNull((new RepoLocator())->locate($url));

        $ref = (new RepoLocator(['gitlab.com', 'git.example.com']))->locate($url);
        self::assertNotNull($ref);
        self::assertSame([RepoRef::GITLAB, 'git.example.com', 'group/project'], [$ref->forge(), $ref->host(), $ref->path()]);
        self::assertSame('git.example.com/group/project', $ref->key());
    }

    /** `gitlab.example.com/gitlab`: the prefix is part of the host as Composer names it, not of the project path. */
    public function testADomainWithAPathPrefixKeepsThePrefixOutOfTheProjectPath(): void
    {
        $locator = new RepoLocator(['gitlab.example.com/gitlab']);

        $ref = $locator->locate('https://gitlab.example.com/gitlab/group/project.git');
        self::assertNotNull($ref);
        self::assertSame('gitlab.example.com/gitlab', $ref->host());
        self::assertSame('group/project', $ref->path());
        self::assertNull($locator->locate('https://gitlab.example.com/group/project.git'), 'outside the prefix is not the instance');
        self::assertNull($locator->locate('https://gitlab.example.com/gitlab/project.git'), 'the prefix alone leaves one segment');
        self::assertNull($locator->locate('https://gitlab.example.com/gitlabx/group/project.git'), 'the prefix is a segment, not a substring');
        self::assertNull($locator->locate('https://gitlab.example.com/other/group/sub/project.git'), 'outside the prefix, however deep the path');

        $trailing = (new RepoLocator(['gitlab.example.com/gitlab/']))->locate('https://gitlab.example.com/gitlab/group/project.git');
        self::assertNotNull($trailing, 'a trailing slash on the entry is tolerated');
        self::assertSame('gitlab.example.com/gitlab', $trailing->host(), 'and kept out of the API base');
        self::assertSame('group/project', $trailing->path());

        $deep = (new RepoLocator(['gitlab.example.com/git/lab']))->locate('https://gitlab.example.com/git/lab/group/project.git');
        self::assertNotNull($deep, 'a prefix may have more than one segment');
        self::assertSame('gitlab.example.com/git/lab', $deep->host());
        self::assertSame('group/project', $deep->path());

        $ported = $locator->locate('https://gitlab.example.com:8443/gitlab/group/project.git');
        self::assertNotNull($ported, 'a URL port the entry omits, with the prefix');
        self::assertSame('gitlab.example.com:8443/gitlab', $ported->host());
        self::assertSame('group/project', $ported->path());
    }

    /**
     * Composer's rule: an entry matches the URL's host with its port, or the bare host when the
     * entry names no port; a URL that omits a port the entry spells out is not that GitLab. The
     * ref's host is the URL's host, port included — Composer's origin — so the API request and the
     * credential lookup agree with Composer's own.
     */
    public function testPortsAreMatchedTheWayComposerMatchesThem(): void
    {
        $locator = new RepoLocator(['gitlab.example.com:8443']);
        $ref = $locator->locate('https://gitlab.example.com:8443/group/project.git');
        self::assertNotNull($ref);
        self::assertSame('gitlab.example.com:8443', $ref->host());
        self::assertNull($locator->locate('https://gitlab.example.com/group/project.git'), 'no port in the URL: not the configured GitLab');

        $bare = new RepoLocator(['gitlab.example.com']);
        $ref = $bare->locate('https://gitlab.example.com:8443/group/project.git');
        self::assertNotNull($ref, 'an entry without a port matches a URL that names one');
        self::assertSame('gitlab.example.com:8443', $ref->host(), 'and the origin carries the URL\'s port');
        self::assertNull($bare->locate('https://other.example.com:8443/group/project.git'));
    }

    public function testAConfiguredDomainIsMatchedCaseInsensitively(): void
    {
        $ref = (new RepoLocator(['GitLab.Example.com']))->locate('https://gitlab.example.com/group/project.git');
        self::assertNotNull($ref);
        self::assertSame('gitlab.example.com', $ref->host(), 'lowercased, as Composer lowercases its origin');
    }

    public function testFromConfigReadsGitlabDomainsAndIgnoresWhatIsNotAHost(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['gitlab-domains' => ['gitlab.com', '', 42, 'git.example.com']]]);
        $locator = RepoLocator::fromConfig($config);

        self::assertNotNull($locator->locate('https://git.example.com/g/p.git'));
        self::assertNotNull($locator->locate('https://gitlab.com/g/p.git'));
        self::assertNull(RepoLocator::fromConfig(new Config(false))->locate('https://git.example.com/g/p.git'), 'Composer defaults to gitlab.com alone');
    }
}
