<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

/**
 * Whether a request to a repository is authenticated, and which of lockrot's own {@see Tokens} it
 * carries: docs/internals.md, "Which credentials".
 *
 * The GitLab token goes to gitlab.com only: a token that one instance issued must never reach
 * another. A `bitbucket-oauth` consumer is a usable bearer token only after Composer exchanges it
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
     * @param callable(string): bool  $composerHasCredentials `IOInterface::hasAuthentication()`
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

    public function tokenFor(RepoRef $repo): ?string
    {
        if ($repo->forge() === RepoRef::GITHUB) {
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
            // Composer looks credentials up under the request's host, then under the site host
            // (AuthHelper::findAuthOrigin()), so either host authenticates the API call.
            $this->bitbucketAuthorized = $this->authorizeBitbucket !== null
                ? ($this->authorizeBitbucket)()
                : ($this->composerHasCredentials)('api.bitbucket.org') || ($this->composerHasCredentials)('bitbucket.org');
        }

        return $this->bitbucketAuthorized;
    }
}
