<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Clock;
use Lockrot\Lock\LockFile;
use Lockrot\Tests\Support\RecorderHttp;
use Lockrot\Tests\Support\RecorderNames;
use PHPUnit\Framework\TestCase;

/**
 * The decisions of `bin/record-fixtures` that need no network: where a credential goes, and which
 * locked names a recording asks for.
 */
final class RecordFixturesTest extends TestCase
{
    private const GITHUB = 'https://api.github.com/repos/vendor/old';
    private const TOKEN = 'Authorization: token secret';

    public function testASameHostRedirectKeepsTheToken(): void
    {
        $seen = [];
        $result = RecorderHttp::fetch(self::GITHUB, [self::TOKEN], null, true, self::transport([
            self::GITHUB => [301, ['Location: https://api.github.com/repositories/1']],
            'https://api.github.com/repositories/1' => [200, [], '{"id":1}'],
        ], $seen), Clock::fixed('2026-01-01'));

        self::assertSame(200, $result->status());
        self::assertSame('{"id":1}', $result->body());
        self::assertSame(self::GITHUB, $result->url());
        self::assertSame([[self::TOKEN], [self::TOKEN]], $seen);
    }

    public function testAnOffHostRedirectSendsNoCredential(): void
    {
        $seen = [];
        $result = RecorderHttp::fetch(self::GITHUB, ['Accept: x', self::TOKEN, 'PRIVATE-TOKEN: secret'], null, true, self::transport([
            self::GITHUB => [302, ['location: https://elsewhere.example/repo']],
            'https://elsewhere.example/repo' => [200, [], 'moved'],
        ], $seen), Clock::fixed('2026-01-01'));

        self::assertSame('moved', $result->body());
        self::assertSame([['Accept: x', self::TOKEN, 'PRIVATE-TOKEN: secret'], ['Accept: x']], $seen);
    }

    public function testARedirectToAnotherSchemeOrPortSendsNoCredential(): void
    {
        $seen = [];
        RecorderHttp::fetch(self::GITHUB, [self::TOKEN], null, true, self::transport([
            self::GITHUB => [301, ['Location: http://api.github.com/repos/a/b']],
            'http://api.github.com/repos/a/b' => [301, ['Location: https://api.github.com:8443/repos/a/b']],
            'https://api.github.com:8443/repos/a/b' => [200, [], 'moved'],
        ], $seen), Clock::fixed('2026-01-01'));

        self::assertSame([[self::TOKEN], [], []], $seen);
    }

    public function testARelativeRedirectResolvesAgainstTheCurrentUrl(): void
    {
        $seen = [];
        $result = RecorderHttp::fetch('https://repo.example/p2/acme/a.json?x=1', [], null, true, self::transport([
            'https://repo.example/p2/acme/a.json?x=1' => [302, ['Location: b.json']],
            'https://repo.example/p2/acme/b.json' => [302, ['Location: ?y=2']],
            'https://repo.example/p2/acme/b.json?y=2' => [302, ['Location: //mirror.example/p2/c.json']],
            'https://mirror.example/p2/c.json' => [302, ['Location: /root.json']],
            'https://mirror.example/root.json' => [200, [], 'found'],
        ], $seen), Clock::fixed('2026-01-01'));

        self::assertSame('found', $result->body());
    }

    public function testASeeOtherRedirectOfAPostAsksWithGet(): void
    {
        $methods = [];
        $transport = static function (string $url, array $http) use (&$methods): array {
            $methods[] = [$http['method'], $http['content'] ?? null];

            return $url === self::GITHUB ? ['', ['HTTP/1.1 303 X', 'Location: /answer']] : ['done', ['HTTP/1.1 200 X']];
        };

        $result = RecorderHttp::fetch(self::GITHUB, [], 'packages=a', true, $transport, Clock::fixed('2026-01-01'));

        self::assertSame('done', $result->body());
        self::assertSame([['POST', 'packages=a'], ['GET', null]], $methods);
    }

    public function testNoFollowReturnsTheRedirectAnswer(): void
    {
        $seen = [];
        $result = RecorderHttp::fetch(self::GITHUB, [self::TOKEN], null, false, self::transport([
            self::GITHUB => [301, ['Location: https://elsewhere.example/repo']],
        ], $seen), Clock::fixed('2026-01-01'));

        self::assertSame(301, $result->status());
        self::assertCount(1, $seen);
    }

    public function testARedirectLoopStopsWithTheLastAnswer(): void
    {
        $seen = [];
        $result = RecorderHttp::fetch(self::GITHUB, [], null, true, self::transport([
            self::GITHUB => [301, ['Location: '.self::GITHUB]],
        ], $seen), Clock::fixed('2026-01-01'));

        self::assertSame(301, $result->status());
        self::assertLessThan(10, \count($seen));
    }

    public function testTheAdvisoryAnswerOmitsDevNamesUnderInto(): void
    {
        $lock = LockFile::fromArray([
            'packages' => [self::package('a/prod')],
            'packages-dev' => [self::package('b/dev')],
        ]);

        self::assertSame(['a/prod'], RecorderNames::advisory($lock, true));
        self::assertSame(['a/prod', 'b/dev'], RecorderNames::advisory($lock, false));
    }

    public function testARepositoryWithoutAMetadataUrlIsUnrecordedWhenItListsPackages(): void
    {
        self::assertTrue(RecorderNames::unrecordable(['available-packages' => ['acme/one']], ['acme/one']));
        self::assertTrue(RecorderNames::unrecordable(['packages' => ['acme/one' => []]], []));
        self::assertTrue(RecorderNames::unrecordable(['includes' => ['p/all.json' => []]], []));
        self::assertTrue(RecorderNames::unrecordable(['providers-url' => '/p/%package%.json'], []));
        self::assertTrue(RecorderNames::unrecordable(['provider-includes' => ['p/providers.json' => []]], []));
        self::assertFalse(RecorderNames::unrecordable(['packages' => []], []));
        self::assertFalse(RecorderNames::unrecordable(['metadata-url' => '/p2/%package%.json', 'packages' => ['acme/one' => []]], ['acme/one']));
    }

    public function testARepositoryListsLockedNamesByPatternAndByExactName(): void
    {
        $locked = ['acme/one', 'acme/two', 'other/three', 'other/four'];

        self::assertSame(['acme/one', 'acme/two'], RecorderNames::repository(['available-package-patterns' => ['acme/*']], $locked));
        self::assertSame(['other/four'], RecorderNames::repository(['available-packages' => ['other/four', 'x/unlocked']], $locked));
        self::assertSame(['acme/one', 'acme/two', 'other/three'], RecorderNames::repository(['available-package-patterns' => ['acme/*'], 'available-packages' => ['other/three']], $locked));
        self::assertSame(['acme/one'], RecorderNames::repository(['available-package-patterns' => ['ACME/ONE']], $locked));
        self::assertSame(['other/four'], RecorderNames::repository(['available-packages' => ['Other/Four']], $locked));
    }

    public function testARepositoryWithoutAnyNameListIsAskedForEveryLockedName(): void
    {
        $locked = ['acme/one', 'other/three'];

        self::assertSame($locked, RecorderNames::repository(['metadata-url' => '/p2/%package%.json'], $locked));
        self::assertSame($locked, RecorderNames::repository(['metadata-url' => '/p2/%package%.json', 'available-packages' => []], $locked));
        self::assertSame($locked, RecorderNames::repository(['metadata-url' => '/p2/%package%.json', 'available-package-patterns' => []], $locked), 'Composer reads an empty list as no list');
        self::assertSame($locked, RecorderNames::repository(['metadata-url' => '/p2/%package%.json', 'available-packages' => [], 'available-package-patterns' => []], $locked));
        self::assertSame([], RecorderNames::repository([], $locked), 'no metadata-url means no p2 answer to record');
    }

    /**
     * @param array<string, array{int, list<string>}|array{int, list<string>, string}> $answers by URL
     * @param list<list<string>>                                                       $seen    the request headers of each call, in order
     *
     * @return callable(string, array<string, mixed>): array{string|false, list<string>}
     */
    private static function transport(array $answers, array &$seen): callable
    {
        return static function (string $url, array $http) use ($answers, &$seen): array {
            self::assertSame(0, $http['follow_location'], 'the recorder follows redirects itself');
            self::assertIsString($http['header']);
            $seen[] = array_values(array_filter(explode("\r\n", $http['header']), static fn (string $line): bool => strpos($line, 'User-Agent:') !== 0));
            $answer = $answers[$url] ?? [404, [], ''];

            return [$answer[2] ?? '', array_merge(['HTTP/1.1 '.$answer[0].' X'], $answer[1])];
        };
    }

    /** @return array<string, mixed> */
    private static function package(string $name): array
    {
        return ['name' => $name, 'version' => '1.0.0', 'type' => 'library'];
    }
}
