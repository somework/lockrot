<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

use Lockrot\Data\Http\CachingHttpClient;

/**
 * Whether a request to a repository is authenticated, and which of lockrot's own {@see Tokens} it
 * carries: docs/internals.md, "Which credentials".
 *
 * A lockrot token goes only to the public host of its forge: a token that one instance issued must
 * never reach another. An ambient env token counts only when its platform names that host as the
 * issuer ({@see Tokens::fromEnvironment()}). A `bitbucket-oauth` consumer is a usable bearer token only after Composer exchanges it
 * on a 401 challenge that lockrot never lets through, so the exchange comes in as a callable.
 *
 * @internal
 */
final class ForgeAuth
{
    private Tokens $tokens;
    /** @var callable(string): bool */
    private $composerHasCredentials;
    /** @var null|callable(): bool */
    private $authorizeBitbucket;
    private ?bool $bitbucketAuthorized = null;

    /**
     * @param callable(string): bool  $composerHasCredentials an `IOInterface` entry that {@see carriesCredential()} accepts
     * @param null|callable(): bool   $authorizeBitbucket     turns Composer's Bitbucket credentials into
     *                                                        ones its HTTP layer can send, true on success
     */
    public function __construct(Tokens $tokens, callable $composerHasCredentials, ?callable $authorizeBitbucket = null)
    {
        $this->tokens = $tokens;
        $this->composerHasCredentials = $composerHasCredentials;
        $this->authorizeBitbucket = $authorizeBitbucket;
    }

    public static function anonymous(): self
    {
        return new self(Tokens::none(), static fn (string $host): bool => false);
    }

    public static function withTokens(Tokens $tokens): self
    {
        return new self($tokens, static fn (string $host): bool => false);
    }

    /**
     * Whether a Composer authentication entry puts a credential header on the wire: not an SSL
     * client certificate, and not custom headers that hold no credential header.
     *
     * @param array{username: null|string, password: null|string} $entry `IOInterface::getAuthentication()`
     */
    public static function carriesCredential(array $entry): bool
    {
        if ($entry['username'] === 'client-certificate') {
            return false;
        }
        if ($entry['password'] !== 'custom-headers') {
            return true;
        }
        $headers = json_decode((string) $entry['username'], true);
        // AuthHelper::addCustomHeaders() ignores the whole list when one element is no string, and
        // sends each header as written, so no trimming here.
        if (!\is_array($headers) || array_filter($headers, 'is_string') !== $headers) {
            return false;
        }
        foreach ($headers as $header) {
            if (CachingHttpClient::isCredentialHeader($header)) {
                return true;
            }
        }

        return false;
    }

    public function tokenFor(RepoRef $repo): ?string
    {
        if ($repo->forge() === RepoRef::GITHUB && $repo->host() === 'github.com') {
            return $this->tokens->github();
        }
        if ($repo->forge() === RepoRef::GITLAB && $repo->host() === 'gitlab.com') {
            return $this->tokens->gitlab();
        }

        return null;
    }

    public function isAuthenticated(RepoRef $repo): bool
    {
        if ($this->tokenFor($repo) !== null) {
            return true;
        }
        if ($repo->forge() === RepoRef::BITBUCKET) {
            return $this->bitbucketAuthorized();
        }

        return ($this->composerHasCredentials)($repo->host());
    }

    private function bitbucketAuthorized(): bool
    {
        if ($this->bitbucketAuthorized === null) {
            // Without an authorizer the callable cannot tell "no entry" from "entry without a
            // credential", so it cannot apply the API-host-first rule of ServiceFactory::bitbucketCredentials().
            $this->bitbucketAuthorized = $this->authorizeBitbucket !== null
                ? ($this->authorizeBitbucket)()
                : ($this->composerHasCredentials)('api.bitbucket.org') || ($this->composerHasCredentials)('bitbucket.org');
        }

        return $this->bitbucketAuthorized;
    }
}
