<?php

declare(strict_types=1);

namespace Lockrot\Data\GitHub;

final class TokenResolver
{
    public const ENV_LOCKROT = 'LOCKROT_GITHUB_TOKEN';
    public const ENV_GITHUB = 'GITHUB_TOKEN';

    /** @param array<string, mixed> $env */
    public static function resolve(array $env, ?string $composerGithubOauthToken): ?string
    {
        foreach ([self::ENV_LOCKROT, self::ENV_GITHUB] as $name) {
            $value = $env[$name] ?? null;
            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $composerGithubOauthToken !== null && $composerGithubOauthToken !== '' ? $composerGithubOauthToken : null;
    }
}
