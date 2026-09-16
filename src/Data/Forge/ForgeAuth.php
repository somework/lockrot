<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

/**
 * Whether a request to a repository will be an authenticated one, and which of lockrot's own
 * tokens (if any) it carries.
 *
 * Two sources count. lockrot's own {@see Tokens}: the GitHub token goes to github.com, the GitLab
 * token to gitlab.com only — a token issued by one instance must never be sent to another, so a
 * self-hosted GitLab is authenticated by Composer's credentials or not at all. And Composer's own
 * credentials for the host (`auth.json`, `COMPOSER_AUTH`): Composer's HTTP layer adds those headers
 * itself, so here they only need to be known about — they lift the anonymous request caps and, on
 * GitLab, unlock the project call that carries the archived flag.
 *
 * Bitbucket is the odd one out: an `bitbucket-oauth` consumer only becomes a usable bearer token
 * once it has been exchanged for one, which Composer does on a 401 challenge lockrot never lets
 * through. The exchange is handed in as a callable and run at most once, the first time a
 * Bitbucket repository is asked about.
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
     * @param callable(string): bool  $composerHasCredentials whether Composer holds credentials for a host
     *                                                        (`IOInterface::hasAuthentication()`)
     * @param null|callable(): bool   $authorizeBitbucket     turns Composer's Bitbucket credentials into
     *                                                        ones its HTTP layer can send; true when they can be
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

    /** Only lockrot's own tokens, no Composer credentials: what the unit tests and the fixture recorder need. */
    public static function withTokens(Tokens $tokens): self
    {
        return new self($tokens, static fn (string $host): bool => false);
    }

    /** lockrot's own token for this repository's host, or null when the request goes without one. */
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

    /** Whether the request to $repo will carry credentials — lockrot's own or Composer's. */
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
            $this->bitbucketAuthorized = $this->authorizeBitbucket !== null
                ? ($this->authorizeBitbucket)()
                : ($this->composerHasCredentials)('bitbucket.org');
        }

        return $this->bitbucketAuthorized;
    }
}
