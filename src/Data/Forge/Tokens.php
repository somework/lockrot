<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

/**
 * Only lockrot's own tokens: Composer's HTTP layer applies its own credentials ({@see ForgeAuth}).
 * A token goes only to the host that issued it. `GITHUB_TOKEN` and `GITLAB_TOKEN` count only when
 * each issuer variable of their platform is unset, empty, or names the public host. Otherwise the
 * token is withheld, never sent elsewhere. The issuer variables are those of the CI systems and of
 * the gh and glab tools, and each can hold a URL or a bare host. lockrot's own names stand for the
 * public host.
 *
 * @internal
 */
final class Tokens
{
    public const ENV_LOCKROT_GITHUB = 'LOCKROT_GITHUB_TOKEN';
    public const ENV_GITHUB = 'GITHUB_TOKEN';
    public const ENV_LOCKROT_GITLAB = 'LOCKROT_GITLAB_TOKEN';
    public const ENV_GITLAB = 'GITLAB_TOKEN';
    /** GitHub Actions, and Gitea and Forgejo Actions, name their instance here. */
    public const ENV_GITHUB_SERVER_URL = 'GITHUB_SERVER_URL';
    /** GitLab CI names its instance here. */
    public const ENV_CI_SERVER_URL = 'CI_SERVER_URL';
    /** glab reads `GITLAB_TOKEN` for this host, and `GL_HOST` and `GITLAB_URI` are its other names. */
    public const ENV_GITLAB_HOST = 'GITLAB_HOST';
    public const ENV_GL_HOST = 'GL_HOST';
    public const ENV_GITLAB_URI = 'GITLAB_URI';
    /** gh names its default host here: github.com, or a GHE.com or GitHub Enterprise Server host. */
    public const ENV_GH_HOST = 'GH_HOST';

    private ?string $github;
    private ?string $gitlab;
    /** @var array<string, string> */
    private array $withheld = [];

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
     * The order of the sources: docs/internals.md, "Repository hosts and credentials". A withheld
     * source falls through to the next one.
     *
     * @param array<string, mixed> $env
     */
    public static function fromEnvironment(array $env, ?string $composerGithubOauthToken): self
    {
        $withheld = [];
        $tokens = new self(
            self::value($env, self::ENV_LOCKROT_GITHUB)
                ?? self::issuedBy($env, self::ENV_GITHUB, [self::ENV_GITHUB_SERVER_URL, self::ENV_GH_HOST], 'github.com', $withheld)
                ?? $composerGithubOauthToken,
            self::value($env, self::ENV_LOCKROT_GITLAB)
                ?? self::issuedBy($env, self::ENV_GITLAB, [self::ENV_CI_SERVER_URL, self::ENV_GITLAB_HOST, self::ENV_GL_HOST, self::ENV_GITLAB_URI], 'gitlab.com', $withheld)
        );
        $tokens->withheld = $withheld;

        return $tokens;
    }

    /**
     * @param array<string, mixed>  $env
     * @param list<string>          $issuers
     * @param array<string, string> $withheld gains $name => the issuer host when the token is withheld
     */
    private static function issuedBy(array $env, string $name, array $issuers, string $publicHost, array &$withheld): ?string
    {
        $token = self::value($env, $name);
        if ($token === null) {
            return null;
        }
        foreach ($issuers as $issuer) {
            $url = self::value($env, $issuer);
            if ($url === null) {
                continue;
            }
            $host = self::host($url);
            if ($host !== $publicHost) {
                $withheld[$name] = $host ?? $url;

                return null;
            }
        }

        return $token;
    }

    /**
     * Normalised as {@see RepoRef::host()} is, so the port stays. A value without a scheme is a
     * bare host. Null when the value does not parse.
     */
    private static function host(string $url): ?string
    {
        $parts = RepoLocator::hostAndPath(strpos($url, '://') === false ? 'https://'.$url : $url);

        return $parts === null ? null : strtolower($parts[0]);
    }

    /** @param array<string, mixed> $env */
    private static function value(array $env, string $name): ?string
    {
        $value = $env[$name] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
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

    /**
     * The ambient tokens that {@see fromEnvironment()} withheld, by variable name: the issuer
     * host, or the raw issuer value when it does not parse.
     *
     * @return array<string, string>
     */
    public function withheld(): array
    {
        return $this->withheld;
    }
}
