<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

/**
 * The access tokens lockrot itself resolved for one run — as opposed to the credentials Composer
 * holds in `auth.json`/`COMPOSER_AUTH`, which Composer's own HTTP layer applies without lockrot's
 * help ({@see ForgeAuth}).
 */
final class Tokens
{
    public const ENV_LOCKROT_GITHUB = 'LOCKROT_GITHUB_TOKEN';
    public const ENV_GITHUB = 'GITHUB_TOKEN';
    public const ENV_LOCKROT_GITLAB = 'LOCKROT_GITLAB_TOKEN';
    public const ENV_GITLAB = 'GITLAB_TOKEN';

    private ?string $github;
    private ?string $gitlab;

    public function __construct(?string $github, ?string $gitlab)
    {
        $this->github = self::nonEmpty($github);
        $this->gitlab = self::nonEmpty($gitlab);
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    /**
     * GitHub: `LOCKROT_GITHUB_TOKEN`, then `GITHUB_TOKEN`, then Composer's `github-oauth.github.com`.
     * GitLab: `LOCKROT_GITLAB_TOKEN`, then `GITLAB_TOKEN`; Composer's `gitlab-token`/`gitlab-oauth`
     * need no resolving here because Composer sends them itself.
     *
     * @param array<string, mixed> $env
     */
    public static function fromEnvironment(array $env, ?string $composerGithubOauthToken): self
    {
        return new self(
            self::firstOf($env, [self::ENV_LOCKROT_GITHUB, self::ENV_GITHUB]) ?? $composerGithubOauthToken,
            self::firstOf($env, [self::ENV_LOCKROT_GITLAB, self::ENV_GITLAB])
        );
    }

    /**
     * @param array<string, mixed> $env
     * @param list<string>         $names
     */
    private static function firstOf(array $env, array $names): ?string
    {
        foreach ($names as $name) {
            $value = $env[$name] ?? null;
            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function nonEmpty(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    public function github(): ?string
    {
        return $this->github;
    }

    public function gitlab(): ?string
    {
        return $this->gitlab;
    }
}
