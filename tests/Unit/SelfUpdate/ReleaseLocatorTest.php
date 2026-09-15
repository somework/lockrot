<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\SelfUpdate;

use Lockrot\Exception\ConfigException;
use Lockrot\SelfUpdate\ReleaseLocator;
use Lockrot\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class ReleaseLocatorTest extends TestCase
{
    private const URL = ReleaseLocator::DEFAULT_URL;
    private const FIXTURES = __DIR__.'/../../fixtures/http/github-releases';

    private static function fixture(string $name): string
    {
        $body = file_get_contents(self::FIXTURES.'/'.$name);
        self::assertIsString($body);

        return $body;
    }

    private function locator(FakeHttpClient $http, ?string $token = null): ReleaseLocator
    {
        return new ReleaseLocator($http, $token, self::URL);
    }

    public function testReadsTheTagAndBothAssetUrlsFromARecordedResponse(): void
    {
        $http = new FakeHttpClient([self::URL => FakeHttpClient::ok(self::URL, self::fixture('latest.json'))]);

        $release = $this->locator($http)->locate();

        self::assertSame('0.2.0', $release->version());
        self::assertSame('v0.2.0', $release->tag());
        self::assertSame('https://github.com/somework/lockrot/releases/download/v0.2.0/lockrot.phar', $release->pharUrl());
        self::assertSame('https://github.com/somework/lockrot/releases/download/v0.2.0/lockrot.phar.sha256', $release->checksumUrl());
        self::assertSame([self::URL], $http->requested());
    }

    public function testSendsTheSameGithubHeadersTheAnalyzerUses(): void
    {
        $http = new FakeHttpClient([self::URL => FakeHttpClient::ok(self::URL, self::fixture('latest.json'))]);

        $this->locator($http, 'ghp_x')->locate();

        $headers = $http->headers()[0];
        self::assertContains('Accept: application/vnd.github+json', $headers);
        self::assertContains('User-Agent: lockrot', $headers);
        self::assertContains('Authorization: token ghp_x', $headers);
    }

    public function testWithoutATokenNoAuthorizationHeaderIsSent(): void
    {
        $http = new FakeHttpClient([self::URL => FakeHttpClient::ok(self::URL, self::fixture('latest.json'))]);

        $this->locator($http)->locate();

        foreach ($http->headers()[0] as $header) {
            self::assertStringStartsNotWith('Authorization', $header);
        }
    }

    public function testATagWithoutALeadingVIsAcceptedAsIs(): void
    {
        $body = str_replace('"tag_name": "v0.2.0"', '"tag_name": "0.3.1"', self::fixture('latest.json'));
        $http = new FakeHttpClient([self::URL => FakeHttpClient::ok(self::URL, $body)]);

        $release = $this->locator($http)->locate();

        self::assertSame('0.3.1', $release->version());
        self::assertSame('0.3.1', $release->tag());
    }

    public function testAMissingReleaseIsReportedAsNoPublishedRelease(): void
    {
        $http = new FakeHttpClient([self::URL => FakeHttpClient::status(self::URL, 404, self::fixture('not-found.json'))]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('no published release found');
        $this->locator($http)->locate();
    }

    public function testAReleaseWithoutThePharAssetNamesTheTag(): void
    {
        $http = new FakeHttpClient([self::URL => FakeHttpClient::ok(self::URL, self::fixture('latest-without-phar-asset.json'))]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('release v0.2.0 has no lockrot.phar asset');
        $this->locator($http)->locate();
    }

    public function testAReleaseWithoutTheChecksumAssetNamesTheTag(): void
    {
        $body = str_replace('lockrot.phar.sha256', 'lockrot.phar.sha512', self::fixture('latest.json'));
        $http = new FakeHttpClient([self::URL => FakeHttpClient::ok(self::URL, $body)]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('release v0.2.0 has no lockrot.phar.sha256 asset');
        $this->locator($http)->locate();
    }

    public function testANonJsonBodyIsAnError(): void
    {
        $http = new FakeHttpClient([self::URL => FakeHttpClient::ok(self::URL, '<html>502 Bad Gateway</html>')]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('is not JSON');
        $this->locator($http)->locate();
    }

    public function testAResponseWithoutATagNameIsAnError(): void
    {
        $body = str_replace('"tag_name": "v0.2.0",', '', self::fixture('latest.json'));
        $http = new FakeHttpClient([self::URL => FakeHttpClient::ok(self::URL, $body)]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('has no tag_name');
        $this->locator($http)->locate();
    }

    public function testATagThatIsNotAVersionIsAnError(): void
    {
        $body = str_replace('"tag_name": "v0.2.0"', '"tag_name": "nightly"', self::fixture('latest.json'));
        $http = new FakeHttpClient([self::URL => FakeHttpClient::ok(self::URL, $body)]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('release tag "nightly" is not a version lockrot can compare');
        $this->locator($http)->locate();
    }

    public function testATransportFailureNamesTheUrlAndTheReason(): void
    {
        $http = new FakeHttpClient([self::URL => FakeHttpClient::transportFailure(self::URL, 'Could not resolve host: api.github.com')]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Could not resolve host: api.github.com');
        $this->locator($http)->locate();
    }

    public function testARateLimitedResponseIsAnErrorNamingTheStatus(): void
    {
        $http = new FakeHttpClient([self::URL => FakeHttpClient::status(self::URL, 403, '{"message":"API rate limit exceeded"}')]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('HTTP 403');
        $this->locator($http)->locate();
    }

    public function testTheDefaultUrlPointsAtTheProjectsOwnLatestRelease(): void
    {
        self::assertSame('https://api.github.com/repos/somework/lockrot/releases/latest', ReleaseLocator::DEFAULT_URL);
    }
}
