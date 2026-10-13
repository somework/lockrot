<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Allowlist;

use Lockrot\Allowlist\UncheckedAcceptance;
use Lockrot\Exception\ConfigException;
use Lockrot\Signal\Data\Check;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** SPEC §6.7: what an `accept-unchecked` entry accepts, and which entries the reader refuses. */
final class UncheckedAcceptanceTest extends TestCase
{
    private const NOW = '2026-09-14T12:00:00+00:00';

    /** @return iterable<string, array{array<string, string>, ?string, ?string, ?string, ?string}> entry, activity host, activity repo, source URL, Composer repository URL */
    public static function scopes(): iterable
    {
        yield 'a host' => [['host' => 'codeberg.org'], 'codeberg.org', 'acme/x', null, null];
        yield 'the host part of a placed GitLab prefix' => [['host' => 'git.example.com'], 'git.example.com/group', 'sub/x', null, null];
        yield 'a repository by its source URL' => [['repository' => 'git@github.com:acme/fork.git'], null, null, 'https://github.com/acme/fork', null];
        yield 'a repository by activity host and repo' => [['repository' => 'https://codeberg.org/acme/x/'], 'codeberg.org', 'acme/x', null, null];
        yield 'a repository by the Composer repository that answered' => [['repository' => 'https://mirror.acme.internal'], null, null, null, 'https://mirror.acme.internal/'];
        yield 'a package by name' => [['package' => 'acme/x'], null, null, null, null];
        yield 'a package by pattern' => [['package' => 'acme/*'], null, null, null, null];
    }

    /**
     * @param array<string, string> $scope
     *
     * @dataProvider scopes
     */
    #[DataProvider('scopes')]
    public function testEachScopeNamesThePackage(array $scope, ?string $host, ?string $repo, ?string $source, ?string $composer): void
    {
        $list = UncheckedAcceptance::fromConfig([['check' => 'repository_activity', 'reason' => 'by hand'] + $scope]);

        self::assertNotNull($list->acceptedBy(new Check('repository_activity', 'host_not_supported', ['S3', 'S4']), 'acme/x', $host, $repo, $source, [$composer], new \DateTimeImmutable(self::NOW)));
    }

    public function testAMirrorEntryNeverAcceptsAPackageFromAnotherMirror(): void
    {
        $list = UncheckedAcceptance::fromConfig([['check' => 'advisories', 'repository' => 'https://mirror-a.example', 'reason' => 'no feed']]);

        self::assertNull($list->acceptedBy(new Check('advisories', 'no_feed', ['S9']), 'acme/x', null, null, null, ['https://mirror-b.example'], new \DateTimeImmutable(self::NOW)));
    }

    public function testAReasonThatARerunCanCloseStaysUnchecked(): void
    {
        $list = UncheckedAcceptance::fromConfig([['check' => 'repository_activity', 'package' => 'acme/*', 'reason' => 'by hand']]);

        foreach (['offline', 'rate_limit', 'undated_releases'] as $reason) {
            self::assertNull($list->acceptedBy(new Check('repository_activity', $reason, ['S3', 'S4']), 'acme/x', null, null, null, [], new \DateTimeImmutable(self::NOW)), $reason);
        }
    }

    public function testAnEntryAcceptsThroughTheLastSecondOfItsExpiryDayInUtc(): void
    {
        $list = UncheckedAcceptance::fromConfig([['check' => 'release_metadata', 'package' => 'acme/*', 'reason' => 'ours', 'expires' => '2026-09-14']]);
        $check = new Check('release_metadata', 'not_from_composer_repository', ['S2', 'S8']);

        self::assertNotNull($list->acceptedBy($check, 'acme/x', null, null, null, [], new \DateTimeImmutable('2026-09-14T23:59:59+00:00')));
        self::assertNull($list->acceptedBy($check, 'acme/x', null, null, null, [], new \DateTimeImmutable('2026-09-15T00:00:00+00:00')));
        self::assertNull($list->acceptedBy($check, 'acme/x', null, null, null, [], new \DateTimeImmutable('2026-09-14T22:00:00-05:00')), 'the UTC date decides');
    }

    public function testTheFirstMatchingEntryIsTheOneEchoed(): void
    {
        $list = UncheckedAcceptance::fromConfig([
            ['check' => 'advisories', 'package' => 'other/*', 'reason' => 'not this'],
            ['check' => 'advisories', 'package' => 'acme/*', 'reason' => 'the first match'],
            ['check' => 'advisories', 'package' => 'acme/x', 'reason' => 'the second match'],
        ]);
        $entry = $list->acceptedBy(new Check('advisories', 'no_feed', ['S9']), 'acme/x', null, null, null, [], new \DateTimeImmutable(self::NOW));

        self::assertSame(['scope' => 'package', 'value' => 'acme/*', 'reason' => 'the first match', 'expires' => null], JsonPath::decoded($entry));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refused(): iterable
    {
        yield 'a blank reason' => [['check' => 'advisories', 'package' => 'a/*', 'reason' => '  '], '%s needs a non-empty "reason"'];
        yield 'a reason that is not a string' => [['check' => 'advisories', 'package' => 'a/*', 'reason' => 5], '%s needs a non-empty "reason"'];
        yield 'a blank scope value' => [['check' => 'advisories', 'package' => '  ', 'reason' => 'x'], '%s "package" must not be blank'];
        yield 'a scope value that is not a string' => [['check' => 'advisories', 'package' => 5, 'reason' => 'x'], '%s "package" must not be blank'];
        yield 'two scopes' => [['check' => 'repository_activity', 'host' => 'codeberg.org', 'package' => 'a/*', 'reason' => 'x'], '%s needs exactly one of'];
        yield 'a host with a scheme' => [['check' => 'repository_activity', 'host' => 'https://codeberg.org', 'reason' => 'x'], '%s "host" must be a lower-case host name with an optional port, without a scheme or a path'];
        yield 'an upper-case host' => [['check' => 'repository_activity', 'host' => 'Codeberg.org', 'reason' => 'x'], '%s "host" must be a lower-case host name'];
        yield 'a port of 0' => [['check' => 'repository_activity', 'host' => 'git.example.com:0', 'reason' => 'x'], '%s "host" has a port outside 1 to 65535'];
        yield 'a port of zeros' => [['check' => 'repository_activity', 'host' => 'git.example.com:00', 'reason' => 'x'], '%s "host" has a port outside 1 to 65535'];
        yield 'a port of 65536' => [['check' => 'repository_activity', 'host' => 'git.example.com:65536', 'reason' => 'x'], '%s "host" has a port outside 1 to 65535'];
        yield 'a repository that is not a URL' => [['check' => 'release_metadata', 'repository' => 'github.com:acme/x', 'reason' => 'x'], '%s "repository" must be a URL with a scheme and no user name or password, or the scp form user@host:path'];
        yield 'a repository without a host' => [['check' => 'advisories', 'repository' => 'https://:8080/acme/x', 'reason' => 'x'], '%s "repository" must be a URL'];
        yield 'a repository with userinfo' => [['check' => 'advisories', 'repository' => 'https://deploy:secret@mirror.example', 'reason' => 'x'], '%s "repository" must be a URL'];
        yield 'advisories by host' => [['check' => 'advisories', 'host' => 'mirror.example', 'reason' => 'x'], '%s accepts "advisories" by host: scope it by repository or package, because the gap comes from the Composer repositories'];
        yield 'a date that does not exist' => [['check' => 'advisories', 'package' => 'a/*', 'reason' => 'x', 'expires' => '2026-02-30'], '%s "expires" must be a valid YYYY-MM-DD date'];
        yield 'a leap day of a common year' => [['check' => 'advisories', 'package' => 'a/*', 'reason' => 'x', 'expires' => '2027-02-29'], '%s "expires" must be a valid YYYY-MM-DD date'];
        yield 'an unknown check' => [['check' => 'releases', 'package' => 'a/*', 'reason' => 'x'], '%s "check" must be one of'];
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @dataProvider refused
     */
    #[DataProvider('refused')]
    public function testAnInvalidEntryIsAConfigErrorNamingIt(array $entry, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage(\sprintf($message, 'extra.lockrot.accept-unchecked[1]'));

        UncheckedAcceptance::fromConfig([['check' => 'advisories', 'package' => 'a/*', 'reason' => 'valid'], $entry]);
    }

    /** @return iterable<string, array{string}> */
    public static function edgeValues(): iterable
    {
        yield 'a host with port 1' => ['git.example.com:1'];
        yield 'a host with port 65535' => ['git.example.com:65535'];
        yield 'a host with port 8443' => ['git.example.com:8443'];
        yield 'a host with a leading zero in its port' => ['git.example.com:080'];
    }

    /** @dataProvider edgeValues */
    #[DataProvider('edgeValues')]
    public function testAPortFrom1To65535IsAccepted(string $host): void
    {
        self::assertFalse(UncheckedAcceptance::fromConfig([['check' => 'repository_activity', 'host' => $host, 'reason' => 'x']])->isEmpty());
    }

    public function testALeapDayOfALeapYearIsAnExpiryDate(): void
    {
        self::assertFalse(UncheckedAcceptance::fromConfig([['check' => 'advisories', 'package' => 'a/*', 'reason' => 'x', 'expires' => '2028-02-29']])->isEmpty());
        self::assertFalse(UncheckedAcceptance::fromConfig([['check' => 'advisories', 'package' => 'a/*', 'reason' => 'x', 'expires' => '2026-12-31']])->isEmpty());
    }

    /**
     * The `repository` scope compares the host in lower case, the port, the path without a trailing
     * `/` and `.git`, the query and the fragment.
     *
     * @return iterable<string, array{string, ?string, ?string, ?string, bool}> entry, activity host, activity repo, source URL, accepted
     */
    public static function repositories(): iterable
    {
        yield 'case, port, .git and a trailing slash' => ['https://Git.Example.com:8443/acme/x.git/', null, null, 'https://git.example.com:8443/acme/x', true];
        yield 'a slash before .git' => ['https://h.example/acme/x/.git', null, null, 'https://h.example/acme/x', true];
        yield 'another port' => ['https://git.example.com:8443/acme/x', null, null, 'https://git.example.com/acme/x', false];
        yield 'another host' => ['https://a.example/acme/x', null, null, 'https://b.example/acme/x', false];
        yield 'another path' => ['https://h.example/acme/x', null, null, 'https://h.example/acme/y', false];
        yield 'a path in another case' => ['https://h.example/Acme/X', null, null, 'https://h.example/acme/x', false];
        yield 'another query' => ['https://h.example/p?ref=a', null, null, 'https://h.example/p?ref=b', false];
        yield 'another fragment' => ['https://h.example/p#a', null, null, 'https://h.example/p#b', false];
        yield 'the same query and fragment' => ['https://h.example/p?ref=a#f', null, null, 'https://h.example/p?ref=a#f', true];
        yield 'an scp URL with an upper-case host' => ['git@GitHub.com:acme/x.git', null, null, 'https://github.com/acme/x', true];
        yield 'a source without a host' => ['https://h.example/acme/x', null, null, 'file:///acme/x', false];
        yield 'the activity host and repo' => ['https://codeberg.org/acme/x', 'codeberg.org', '/acme/x/', null, true];
        yield 'a placed GitLab prefix' => ['https://git.example.com/group/sub/x', 'git.example.com/group', 'sub/x', null, true];
        yield 'an activity host without a repo' => ['https://codeberg.org', 'codeberg.org', null, null, false];
        yield 'an activity repo without a host' => ['https://codeberg.org/acme/x', null, 'acme/x', null, false];
    }

    /** @dataProvider repositories */
    #[DataProvider('repositories')]
    public function testARepositoryEntryComparesTheUrlKey(string $entry, ?string $host, ?string $repo, ?string $source, bool $accepted): void
    {
        $list = UncheckedAcceptance::fromConfig([['check' => 'repository_activity', 'repository' => $entry, 'reason' => 'by hand']]);

        self::assertSame($accepted, $list->acceptedBy(new Check('repository_activity', 'host_not_supported', ['S3', 'S4']), 'acme/x', $host, $repo, $source, [], new \DateTimeImmutable(self::NOW)) !== null);
    }

    public function testAHostEntryNamesTheHostWithoutItsPathAndWithItsPort(): void
    {
        $check = new Check('repository_activity', 'host_not_supported', ['S3', 'S4']);
        $now = new \DateTimeImmutable(self::NOW);
        $accepts = static fn (string $entry, ?string $host): bool => UncheckedAcceptance::fromConfig([['check' => 'repository_activity', 'host' => $entry, 'reason' => 'x']])->acceptedBy($check, 'acme/x', $host, null, null, [], $now) !== null;

        self::assertSame([true, true, false, false, false], [
            $accepts('git.example.com', 'git.example.com'),
            $accepts('git.example.com', 'git.example.com/group'),
            $accepts('git.example.com', 'git.example.comx'),
            $accepts('git.example.com:8443', 'git.example.com'),
            $accepts('git.example.com', null),
        ]);
    }

    public function testARefusedRepositoryNeverEchoesItsUserinfo(): void
    {
        try {
            UncheckedAcceptance::fromConfig([['check' => 'advisories', 'repository' => 'https://deploy:s3cr3t@mirror.example', 'reason' => 'x']]);
            self::fail('a URL with userinfo is accepted');
        } catch (ConfigException $e) {
            self::assertStringNotContainsString('s3cr3t', $e->getMessage());
        }
    }

    public function testAValueWithATrailingNewlineIsRefused(): void
    {
        $this->expectException(ConfigException::class);

        UncheckedAcceptance::fromConfig([['check' => 'repository_activity', 'host' => "codeberg.org\n", 'reason' => 'x']]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function notALists(): iterable
    {
        yield 'an object' => [['check' => 'advisories']];
        yield 'a string' => ['advisories'];
    }

    /**
     * @param mixed $rows
     *
     * @dataProvider notALists
     */
    #[DataProvider('notALists')]
    public function testAListThatIsNotAnArrayIsRefused($rows): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('extra.lockrot.accept-unchecked must be an array');

        UncheckedAcceptance::fromConfig($rows);
    }

    public function testAnEmptyListAcceptsNothing(): void
    {
        self::assertTrue(UncheckedAcceptance::fromConfig([])->isEmpty());
        self::assertTrue(UncheckedAcceptance::fromConfig(null)->isEmpty());
    }
}
