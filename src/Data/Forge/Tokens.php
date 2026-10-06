<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

/**
 * Only lockrot's own tokens: Composer's HTTP layer applies its own credentials ({@see ForgeAuth}).
 *
 * @internal
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
     * The order of the sources: docs/internals.md, "Repository hosts and credentials".
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
