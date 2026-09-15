<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Composer;

use Composer\IO\BufferIO;
use Lockrot\Composer\ComposerHttpClient;
use PHPUnit\Framework\TestCase;

final class ComposerHttpClientTest extends TestCase
{
    private const GITHUB = 'https://api.github.com/repos/vendor/name';
    private const HEADERS = ['Accept: application/vnd.github+json', 'User-Agent: lockrot', 'Authorization: token env-token'];

    public function testHeadersPassThroughWhenComposerHasNoGithubCredentials(): void
    {
        self::assertSame(self::HEADERS, ComposerHttpClient::headersFor(new BufferIO(), self::GITHUB, self::HEADERS));
    }

    public function testAuthorizationIsDroppedWhenComposerAuthenticatesGithub(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('github.com', 'composer-token', 'x-oauth-basic');

        self::assertSame(
            ['Accept: application/vnd.github+json', 'User-Agent: lockrot'],
            ComposerHttpClient::headersFor($io, self::GITHUB, self::HEADERS)
        );
    }

    public function testCredentialsStoredUnderTheApiHostCountToo(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('api.github.com', 'composer-token', 'x-oauth-basic');

        self::assertSame(['User-Agent: lockrot'], ComposerHttpClient::headersFor($io, self::GITHUB, ['User-Agent: lockrot', 'authorization: token x']));
    }

    public function testOtherHostsKeepTheirHeadersWhateverComposerKnows(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('github.com', 'composer-token', 'x-oauth-basic');

        foreach (['https://github.com/somework/lockrot/releases/download/v0.2.1/lockrot.phar', 'https://repo.packagist.org/p2/psr/log.json', 'http://127.0.0.1:8080/repos/x/y'] as $url) {
            self::assertSame(self::HEADERS, ComposerHttpClient::headersFor($io, $url, self::HEADERS), $url);
        }
    }

    public function testCredentialsForAnotherHostDoNotTouchGithubRequests(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('repo.packagist.com', 'token', 'secret');

        self::assertSame(self::HEADERS, ComposerHttpClient::headersFor($io, self::GITHUB, self::HEADERS));
    }
}
