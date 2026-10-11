<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

/**
 * Where {@see RepoLocator::classify()} placed a repository URL: a repository lockrot can ask, a
 * host it cannot ask, or no repository (docs/verdicts.md#what-was-not-checked).
 *
 * @internal
 */
final class LocatedRepository
{
    public const LOCATED = 'located';
    public const HOST_NOT_SUPPORTED = 'host_not_supported';
    public const NO_REPOSITORY = 'no_repository';

    /** The Composer setting that lists a host lockrot cannot ask. */
    public const GITHUB_DOMAINS = 'github-domains';

    private string $kind;
    private ?string $host;
    private ?RepoRef $ref;
    private ?string $listedIn;

    private function __construct(string $kind, ?string $host, ?RepoRef $ref, ?string $listedIn)
    {
        $this->kind = $kind;
        $this->host = $host;
        $this->ref = $ref;
        $this->listedIn = $listedIn;
    }

    public static function located(RepoRef $ref): self
    {
        return new self(self::LOCATED, $ref->host(), $ref, null);
    }

    /** @param ?string $listedIn {@see self::GITHUB_DOMAINS} when Composer's config names the host */
    public static function unsupported(string $host, ?string $listedIn): self
    {
        return new self(self::HOST_NOT_SUPPORTED, $host, null, $listedIn);
    }

    /** @param ?string $host null when no URL parses */
    public static function none(?string $host): self
    {
        return new self(self::NO_REPOSITORY, $host, null, null);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /** With its port and without userinfo, and with the GitLab prefix when located. */
    public function host(): ?string
    {
        return $this->host;
    }

    public function ref(): ?RepoRef
    {
        return $this->ref;
    }

    public function listedIn(): ?string
    {
        return $this->listedIn;
    }
}
