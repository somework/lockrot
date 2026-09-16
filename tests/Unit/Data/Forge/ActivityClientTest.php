<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Forge;

use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Http\HttpResult;
use PHPUnit\Framework\TestCase;

final class ActivityClientTest extends TestCase
{
    public const FETCHED = '2026-09-14T00:00:00+00:00';
    private const GH_URL = 'https://api.github.com/repos/Grandt/PHPZip';
    private const GL_COMMITS = 'https://gitlab.com/api/v4/projects/group%2Fsub%2Fproject/repository/commits?all=true&per_page=1';
    private const GL_PROJECT = 'https://gitlab.com/api/v4/projects/group%2Fsub%2Fproject';
    private const BB_COMMITS = 'https://api.bitbucket.org/2.0/repositories/workspace/repo/commits?pagelen=1';

    /**
     * A fake that answers from a map and records every batch it was given as [urls, headers].
     *
     * @param array<string, HttpResult|array{int, string}>       $map
     * @param \ArrayObject<int, array{list<string>, list<string>}> $batches
     */
    private function http(array $map, \ArrayObject $batches): HttpClientInterface
    {
        return new class ($map, $batches) implements HttpClientInterface {
            /** @var array<string, HttpResult|array{int, string}> */
            private array $map;
            /** @var \ArrayObject<int, array{list<string>, list<string>}> */
            private \ArrayObject $batches;

            /**
             * @param array<string, HttpResult|array{int, string}>       $map
             * @param \ArrayObject<int, array{list<string>, list<string>}> $batches
             */
            public function __construct(array $map, \ArrayObject $batches)
            {
                $this->map = $map;
                $this->batches = $batches;
            }

            public function fetchAll(array $urls, array $headers = []): array
            {
                $this->batches->append([$urls, $headers]);
                $out = [];
                foreach ($urls as $url) {
                    $answer = $this->map[$url] ?? [404, ''];
                    $out[$url] = $answer instanceof HttpResult ? $answer : new HttpResult($url, $answer[0], $answer[1], new \DateTimeImmutable(ActivityClientTest::FETCHED));
                }

                return $out;
            }
        };
    }

    private static function github(): RepoRef
    {
        return new RepoRef(RepoRef::GITHUB, 'github.com', 'Grandt/PHPZip');
    }

    private static function gitlab(): RepoRef
    {
        return new RepoRef(RepoRef::GITLAB, 'gitlab.com', 'group/sub/project');
    }

    private static function bitbucket(): RepoRef
    {
        return new RepoRef(RepoRef::BITBUCKET, 'bitbucket.org', 'workspace/repo');
    }

    private static function day(?\DateTimeImmutable $date): ?string
    {
        return $date === null ? null : $date->format('Y-m-d');
    }

    public function testGithubParsesArchivedAndPushedAtAndSendsTheTokenHeader(): void
    {
        $batches = new \ArrayObject();
        $client = new ActivityClient($this->http([self::GH_URL => [200, '{"full_name":"Grandt/PHPZip","archived":false,"pushed_at":"2015-11-16T16:31:37Z"}']], $batches), ForgeAuth::withTokens(new Tokens('ghp_x', null)));

        $batch = $client->fetch([self::github(), new RepoRef(RepoRef::GITHUB, 'github.com', 'gone/repo')]);

        $activity = $batch->activity()['github.com/Grandt/PHPZip'];
        self::assertFalse($activity->isArchived());
        self::assertSame('Grandt/PHPZip', $activity->repo());
        self::assertSame(self::github()->key(), $activity->ref()->key());
        self::assertSame('2015-11-16', self::day($activity->pushedAt()));
        self::assertSame(self::FETCHED, $activity->fetchedAt()->format(\DATE_ATOM));
        self::assertSame(['github.com/gone/repo'], $batch->notFound());
        self::assertSame(['github.com/gone/repo'], $batch->notFoundOn(RepoRef::GITHUB));
        self::assertSame([], $batch->failed());
        self::assertSame([[[self::GH_URL, 'https://api.github.com/repos/gone/repo'], ['Accept: application/vnd.github+json', 'User-Agent: lockrot', 'Authorization: token ghp_x']]], $batches->getArrayCopy());
    }

    public function testGithubRateLimitAndOtherFailuresAreToldApart(): void
    {
        $batches = new \ArrayObject();
        $client = new ActivityClient($this->http([self::GH_URL => [403, '{"message":"API rate limit exceeded"}']], $batches), ForgeAuth::anonymous());
        $batch = $client->fetch([self::github()]);
        self::assertTrue($batch->rateLimited(RepoRef::GITHUB));
        self::assertSame(['github.com/Grandt/PHPZip' => 'HTTP 403'], $batch->failedOn(RepoRef::GITHUB));
        self::assertSame(['github.com/Grandt/PHPZip' => 'HTTP 403'], $batch->failed());
        self::assertSame([[[self::GH_URL], ['Accept: application/vnd.github+json', 'User-Agent: lockrot']]], $batches->getArrayCopy(), 'no token, no Authorization header');

        $client = new ActivityClient($this->http([self::GH_URL => [500, '']], new \ArrayObject()), ForgeAuth::anonymous());
        $batch = $client->fetch([self::github()]);
        self::assertFalse($batch->rateLimited(RepoRef::GITHUB));
        self::assertSame(['github.com/Grandt/PHPZip' => 'HTTP 500'], $batch->failed());

        $client = new ActivityClient($this->http([self::GH_URL => HttpResult::failure(self::GH_URL, 'timed out', new \DateTimeImmutable(self::FETCHED))], new \ArrayObject()), ForgeAuth::anonymous());
        self::assertSame(['github.com/Grandt/PHPZip' => 'timed out'], $client->fetch([self::github()])->failed());
    }

    public function testAnAnswerFromTheCacheMarksTheActivity(): void
    {
        $cached = (new HttpResult(self::GH_URL, 200, '{"archived":false,"pushed_at":"2020-01-01T00:00:00Z"}', new \DateTimeImmutable('2026-09-13T20:00:00+00:00')))->asCached();
        $client = new ActivityClient($this->http([self::GH_URL => $cached], new \ArrayObject()), ForgeAuth::anonymous());

        $activity = $client->fetch([self::github()])->activity()['github.com/Grandt/PHPZip'];

        self::assertTrue($activity->fromCache());
        self::assertSame('2026-09-13T20:00:00+00:00', $activity->fetchedAt()->format(\DATE_ATOM));
        $fresh = (new ActivityClient($this->http([self::GH_URL => [200, '{"archived":false}']], new \ArrayObject()), ForgeAuth::anonymous()))->fetch([self::github()])->activity()['github.com/Grandt/PHPZip'];
        self::assertFalse($fresh->fromCache());
    }

    public function testGithubArchived(): void
    {
        $client = new ActivityClient($this->http([self::GH_URL => [200, '{"archived":true,"pushed_at":"2020-01-01T00:00:00Z"}']], new \ArrayObject()), ForgeAuth::anonymous());
        self::assertTrue($client->fetch([self::github()])->activity()['github.com/Grandt/PHPZip']->isArchived());
    }

    /** Anonymously only the commits call is made: the project document would not carry `archived` anyway. */
    public function testGitlabAnonymousAsksForTheNewestCommitOnly(): void
    {
        $batches = new \ArrayObject();
        $client = new ActivityClient($this->http([self::GL_COMMITS => [200, '[{"id":"abc","committed_date":"2012-01-11T17:34:28.000+01:00"}]']], $batches), ForgeAuth::anonymous());

        $activity = $client->fetch([self::gitlab()])->activity()['gitlab.com/group/sub/project'];

        self::assertFalse($activity->isArchived());
        $pushedAt = $activity->pushedAt();
        self::assertNotNull($pushedAt);
        self::assertSame('2012-01-11T16:34:28+00:00', $pushedAt->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
        self::assertSame('group/sub/project', $activity->repo());
        self::assertSame([[[self::GL_COMMITS], ['Accept: application/json', 'User-Agent: lockrot']]], $batches->getArrayCopy());
    }

    public function testGitlabAuthenticatedAlsoReadsTheProjectForItsArchivedFlag(): void
    {
        $batches = new \ArrayObject();
        $client = new ActivityClient($this->http([
            self::GL_COMMITS => [200, '[{"committed_date":"2012-01-11T17:34:28.000+01:00"}]'],
            self::GL_PROJECT => [200, '{"path_with_namespace":"group/sub/project","archived":true,"last_activity_at":"2022-11-17T16:18:53.865Z"}'],
        ], $batches), ForgeAuth::withTokens(new Tokens(null, 'glpat-x')));

        $activity = $client->fetch([self::gitlab()])->activity()['gitlab.com/group/sub/project'];

        self::assertTrue($activity->isArchived());
        self::assertSame('2012-01-11', self::day($activity->pushedAt()), 'last_activity_at is not what S4 reads');
        self::assertSame([[[self::GL_COMMITS, self::GL_PROJECT], ['Accept: application/json', 'User-Agent: lockrot', 'PRIVATE-TOKEN: glpat-x']]], $batches->getArrayCopy());
    }

    public function testGitlabAnEmptyRepositoryHasNoCommitDate(): void
    {
        $client = new ActivityClient($this->http([self::GL_COMMITS => [200, '[]']], new \ArrayObject()), ForgeAuth::anonymous());
        self::assertNull($client->fetch([self::gitlab()])->activity()['gitlab.com/group/sub/project']->pushedAt());
    }

    /** A private project answers 404 anonymously; a bad token 401; 429 is the rate limit. */
    public function testGitlabFailuresPerAnswer(): void
    {
        $client = new ActivityClient($this->http([], new \ArrayObject()), ForgeAuth::anonymous());
        self::assertSame(['gitlab.com/group/sub/project'], $client->fetch([self::gitlab()])->notFound());

        $client = new ActivityClient($this->http([self::GL_COMMITS => [429, '']], new \ArrayObject()), ForgeAuth::anonymous());
        $batch = $client->fetch([self::gitlab()]);
        self::assertTrue($batch->rateLimited(RepoRef::GITLAB));
        self::assertFalse($batch->rateLimited(RepoRef::GITHUB));
        self::assertSame(['gitlab.com/group/sub/project' => 'HTTP 429'], $batch->failedOn(RepoRef::GITLAB));

        // The commits call decides; the project call is enrichment. When it fails the commit date
        // stands and the archived flag simply stays unknown (false) — nothing is lost that the
        // anonymous run would have had.
        $client = new ActivityClient($this->http([self::GL_COMMITS => [200, '[{"committed_date":"2012-01-11T17:34:28.000+01:00"}]'], self::GL_PROJECT => [403, '{"message":"403 Forbidden"}']], new \ArrayObject()), ForgeAuth::withTokens(new Tokens(null, 'glpat-x')));
        $batch = $client->fetch([self::gitlab()]);
        $activity = $batch->activity()['gitlab.com/group/sub/project'];
        self::assertFalse($activity->isArchived());
        self::assertSame('2012-01-11', self::day($activity->pushedAt()));
        self::assertSame([], $batch->failed());

        // A bad token fails the deciding call itself: the repository is not answered.
        $client = new ActivityClient($this->http([self::GL_COMMITS => [401, '{"message":"401 Unauthorized"}'], self::GL_PROJECT => [401, '']], new \ArrayObject()), ForgeAuth::withTokens(new Tokens(null, 'bad')));
        $batch = $client->fetch([self::gitlab()]);
        self::assertSame([], $batch->activity());
        self::assertSame(['gitlab.com/group/sub/project' => 'HTTP 401'], $batch->failed());
        self::assertFalse($batch->rateLimited(RepoRef::GITLAB));

        // A 404 on the deciding call is "not found", whatever the enrichment said.
        $client = new ActivityClient($this->http([self::GL_PROJECT => [200, '{"archived":true}']], new \ArrayObject()), ForgeAuth::withTokens(new Tokens(null, 'glpat-x')));
        $batch = $client->fetch([self::gitlab()]);
        self::assertSame(['gitlab.com/group/sub/project'], $batch->notFound());
        self::assertSame(['gitlab.com/group/sub/project'], $batch->notFoundOn(RepoRef::GITLAB));
        self::assertSame([], $batch->notFoundOn(RepoRef::GITHUB));
        self::assertSame([], $batch->failed());
    }

    public function testASelfHostedGitlabIsAddressedOnItsOwnHost(): void
    {
        $batches = new \ArrayObject();
        $url = 'https://gitlab.example.com/gitlab/api/v4/projects/g%2Fp/repository/commits?all=true&per_page=1';
        $client = new ActivityClient($this->http([$url => [200, '[{"committed_date":"2020-01-01T00:00:00Z"}]']], $batches), ForgeAuth::withTokens(new Tokens(null, 'glpat-for-gitlab-com')));

        $batch = $client->fetch([new RepoRef(RepoRef::GITLAB, 'gitlab.example.com/gitlab', 'g/p')]);

        self::assertArrayHasKey('gitlab.example.com/gitlab/g/p', $batch->activity());
        self::assertSame([[[$url], ['Accept: application/json', 'User-Agent: lockrot']]], $batches->getArrayCopy(), 'the gitlab.com token stays home, so the run is anonymous there and asks for commits only');
    }

    public function testBitbucketReadsTheNewestCommitAndNeverAnArchivedFlag(): void
    {
        $batches = new \ArrayObject();
        $client = new ActivityClient($this->http([self::BB_COMMITS => [200, '{"values":[{"hash":"7cb6","date":"2026-09-16T08:40:06+00:00"}],"pagelen":1}']], $batches), ForgeAuth::anonymous());

        $activity = $client->fetch([self::bitbucket()])->activity()['bitbucket.org/workspace/repo'];

        self::assertFalse($activity->isArchived());
        self::assertSame('2026-09-16', self::day($activity->pushedAt()));
        self::assertSame([[[self::BB_COMMITS], ['Accept: application/json', 'User-Agent: lockrot']]], $batches->getArrayCopy());

        $client = new ActivityClient($this->http([self::BB_COMMITS => [200, '{"values":[]}']], new \ArrayObject()), ForgeAuth::anonymous());
        self::assertNull($client->fetch([self::bitbucket()])->activity()['bitbucket.org/workspace/repo']->pushedAt());

        $client = new ActivityClient($this->http([self::BB_COMMITS => [429, '']], new \ArrayObject()), ForgeAuth::anonymous());
        self::assertTrue($client->fetch([self::bitbucket()])->rateLimited(RepoRef::BITBUCKET));
    }

    public function testABodyThatIsNotJsonIsAFailure(): void
    {
        $client = new ActivityClient($this->http([self::GH_URL => [200, '<html>']], new \ArrayObject()), ForgeAuth::anonymous());
        $batch = $client->fetch([self::github()]);
        self::assertSame(['github.com/Grandt/PHPZip' => 'invalid JSON from '.self::GH_URL], $batch->failed());
        self::assertSame([], $batch->activity());
    }

    /** One batch per host, each with its own headers, and every repository back under its own key. */
    public function testAMixedListIsFetchedHostByHost(): void
    {
        $batches = new \ArrayObject();
        $client = new ActivityClient($this->http([
            self::GH_URL => [200, '{"archived":false,"pushed_at":"2015-11-16T16:31:37Z"}'],
            self::GL_COMMITS => [200, '[{"committed_date":"2012-01-11T17:34:28.000+01:00"}]'],
            self::BB_COMMITS => [200, '{"values":[{"date":"2026-09-16T08:40:06+00:00"}]}'],
        ], $batches), ForgeAuth::withTokens(new Tokens('ghp_x', null)));

        $batch = $client->fetch([self::gitlab(), self::github(), self::bitbucket()]);

        self::assertSame(['gitlab.com/group/sub/project', 'github.com/Grandt/PHPZip', 'bitbucket.org/workspace/repo'], array_keys($batch->activity()));
        self::assertSame([
            [[self::GL_COMMITS], ['Accept: application/json', 'User-Agent: lockrot']],
            [[self::GH_URL], ['Accept: application/vnd.github+json', 'User-Agent: lockrot', 'Authorization: token ghp_x']],
            [[self::BB_COMMITS], ['Accept: application/json', 'User-Agent: lockrot']],
        ], $batches->getArrayCopy());

        self::assertSame([], $client->fetch([])->activity());
        self::assertCount(3, $batches, 'an empty list makes no request');
    }
}
