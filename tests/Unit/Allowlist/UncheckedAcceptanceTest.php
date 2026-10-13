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

        self::assertNotNull($list->acceptedBy(new Check('repository_activity', 'host_not_supported', ['S3', 'S4']), 'acme/x', $host, $repo, $source, $composer, new \DateTimeImmutable(self::NOW)));
    }

    public function testAMirrorEntryNeverAcceptsAPackageFromAnotherMirror(): void
    {
        $list = UncheckedAcceptance::fromConfig([['check' => 'advisories', 'repository' => 'https://mirror-a.example', 'reason' => 'no feed']]);

        self::assertNull($list->acceptedBy(new Check('advisories', 'no_feed', ['S9']), 'acme/x', null, null, null, 'https://mirror-b.example', new \DateTimeImmutable(self::NOW)));
    }

    public function testAReasonThatARerunCanCloseStaysUnchecked(): void
    {
        $list = UncheckedAcceptance::fromConfig([['check' => 'repository_activity', 'package' => 'acme/*', 'reason' => 'by hand']]);

        foreach (['offline', 'rate_limit', 'undated_releases'] as $reason) {
            self::assertNull($list->acceptedBy(new Check('repository_activity', $reason, ['S3', 'S4']), 'acme/x', null, null, null, null, new \DateTimeImmutable(self::NOW)), $reason);
        }
    }

    public function testAnEntryAcceptsThroughTheLastSecondOfItsExpiryDayInUtc(): void
    {
        $list = UncheckedAcceptance::fromConfig([['check' => 'release_metadata', 'package' => 'acme/*', 'reason' => 'ours', 'expires' => '2026-09-14']]);
        $check = new Check('release_metadata', 'not_from_composer_repository', ['S2', 'S8']);

        self::assertNotNull($list->acceptedBy($check, 'acme/x', null, null, null, null, new \DateTimeImmutable('2026-09-14T23:59:59+00:00')));
        self::assertNull($list->acceptedBy($check, 'acme/x', null, null, null, null, new \DateTimeImmutable('2026-09-15T00:00:00+00:00')));
        self::assertNull($list->acceptedBy($check, 'acme/x', null, null, null, null, new \DateTimeImmutable('2026-09-14T22:00:00-05:00')), 'the UTC date decides');
    }

    public function testTheFirstMatchingEntryIsTheOneEchoed(): void
    {
        $list = UncheckedAcceptance::fromConfig([
            ['check' => 'advisories', 'package' => 'other/*', 'reason' => 'not this'],
            ['check' => 'advisories', 'package' => 'acme/*', 'reason' => 'the first match'],
            ['check' => 'advisories', 'package' => 'acme/x', 'reason' => 'the second match'],
        ]);
        $entry = $list->acceptedBy(new Check('advisories', 'no_feed', ['S9']), 'acme/x', null, null, null, null, new \DateTimeImmutable(self::NOW));

        self::assertSame(['scope' => 'package', 'value' => 'acme/*', 'reason' => 'the first match', 'expires' => null], JsonPath::decoded($entry));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refused(): iterable
    {
        yield 'a blank reason' => [['check' => 'advisories', 'package' => 'a/*', 'reason' => '  '], 'needs a non-empty "reason"'];
        yield 'two scopes' => [['check' => 'repository_activity', 'host' => 'codeberg.org', 'package' => 'a/*', 'reason' => 'x'], 'exactly one of'];
        yield 'a host with a scheme' => [['check' => 'repository_activity', 'host' => 'https://codeberg.org', 'reason' => 'x'], '"host" must be'];
        yield 'an upper-case host' => [['check' => 'repository_activity', 'host' => 'Codeberg.org', 'reason' => 'x'], '"host" must be'];
        yield 'a port of 0' => [['check' => 'repository_activity', 'host' => 'git.example.com:0', 'reason' => 'x'], 'port outside'];
        yield 'a port of 65536' => [['check' => 'repository_activity', 'host' => 'git.example.com:65536', 'reason' => 'x'], 'port outside'];
        yield 'a repository that is not a URL' => [['check' => 'release_metadata', 'repository' => 'github.com:acme/x', 'reason' => 'x'], '"repository" must be'];
        yield 'a repository with userinfo' => [['check' => 'advisories', 'repository' => 'https://deploy:secret@mirror.example', 'reason' => 'x'], '"repository" must be'];
        yield 'advisories by host' => [['check' => 'advisories', 'host' => 'mirror.example', 'reason' => 'x'], 'by host'];
        yield 'a date that does not exist' => [['check' => 'advisories', 'package' => 'a/*', 'reason' => 'x', 'expires' => '2026-02-30'], '"expires" must be'];
        yield 'an unknown check' => [['check' => 'releases', 'package' => 'a/*', 'reason' => 'x'], '"check" must be one of'];
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
        $this->expectExceptionMessage($message);

        UncheckedAcceptance::fromConfig([$entry]);
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

    public function testAListThatIsNotAnArrayIsRefused(): void
    {
        $this->expectException(ConfigException::class);

        UncheckedAcceptance::fromConfig(['check' => 'advisories']);
    }
}
