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
    private const WITHOUT_AUTHORIZATION = ['Accept: application/vnd.github+json', 'User-Agent: lockrot'];

    public function testHeadersPassThroughWhenComposerHasNoGithubCredentials(): void
    {
        self::assertSame(self::HEADERS, ComposerHttpClient::withoutRedundantAuthorization(new BufferIO(), self::GITHUB, self::HEADERS));
    }

    public function testAuthorizationIsDroppedWhenComposerAuthenticatesGithub(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('github.com', 'composer-token', 'x-oauth-basic');

        self::assertSame(self::WITHOUT_AUTHORIZATION, ComposerHttpClient::withoutRedundantAuthorization($io, self::GITHUB, self::HEADERS));
    }

    /**
     * Composer's AuthHelper sends Basic for http-basic credentials on github.com; that is still an
     * Authorization header, so lockrot's must still go.
     */
    public function testHttpBasicCredentialsForGithubCountToo(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('github.com', 'user', 'secret');

        self::assertSame(self::WITHOUT_AUTHORIZATION, ComposerHttpClient::withoutRedundantAuthorization($io, self::GITHUB, self::HEADERS));
    }

    /**
     * HttpDownloader folds every *.github.com host into the github.com origin before AuthHelper
     * looks credentials up, so credentials stored under api.github.com are never sent by Composer
     * — and lockrot's own header has to stay.
     */
    public function testCredentialsStoredUnderTheApiHostDoNotCount(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('api.github.com', 'composer-token', 'x-oauth-basic');

        self::assertSame(self::HEADERS, ComposerHttpClient::withoutRedundantAuthorization($io, self::GITHUB, self::HEADERS));
    }

    public function testTheHeaderNameIsMatchedCaseInsensitively(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('github.com', 'composer-token', 'x-oauth-basic');

        self::assertSame(['User-Agent: lockrot'], ComposerHttpClient::withoutRedundantAuthorization($io, self::GITHUB, ['User-Agent: lockrot', 'authorization: token x']));
        self::assertSame(['User-Agent: lockrot'], ComposerHttpClient::withoutRedundantAuthorization($io, 'https://API.GITHUB.COM/repos/x/y', ['User-Agent: lockrot', 'AUTHORIZATION: token x']));
    }

    public function testOtherHostsKeepTheirHeadersWhateverComposerKnows(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('github.com', 'composer-token', 'x-oauth-basic');

        foreach (['https://github.com/somework/lockrot/releases/download/v0.2.1/lockrot.phar', 'https://repo.packagist.org/p2/psr/log.json', 'http://127.0.0.1:8080/repos/x/y', 'not a url'] as $url) {
            self::assertSame(self::HEADERS, ComposerHttpClient::withoutRedundantAuthorization($io, $url, self::HEADERS), $url);
        }
    }

    public function testCredentialsForAnotherHostDoNotTouchGithubRequests(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('repo.packagist.com', 'token', 'secret');

        self::assertSame(self::HEADERS, ComposerHttpClient::withoutRedundantAuthorization($io, self::GITHUB, self::HEADERS));
    }
}
