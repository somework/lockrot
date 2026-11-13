<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

use Lockrot\AsciiCase;

/**
 * Only lockrot's own tokens: Composer's HTTP layer applies its own credentials ({@see ForgeAuth}).
 * `GITHUB_TOKEN` and `GITLAB_TOKEN` count only when each issuer environment variable of their
 * platform is unset, empty, or names the public host. Otherwise the token is withheld, never sent
 * elsewhere. An issuer that only a gh or glab config file names is not read. The issuer variables
 * are those of the CI systems and of the gh and glab tools, and each can hold a URL or a bare
 * host. lockrot's own names stand for the public host.
 *
 * @internal
 */
final class Tokens
{
    /** Fits "<variable> is for %s": the issuer names the public host only for one URL parser. */
    public const READ_TWO_WAYS = 'an issuer that URL parsers read two ways';
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
    private bool $githubIssuerNamesAnotherHost = false;

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
        $githubIssuers = array_map(static fn (string $issuer): ?string => self::value($env, $issuer), [self::ENV_GITHUB_SERVER_URL, self::ENV_GH_HOST]);
        $tokens->githubIssuerNamesAnotherHost = self::issuerRefusal($githubIssuers, 'github.com') !== null;

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
        $refusal = self::issuerRefusal(array_map(static fn (string $issuer): ?string => self::value($env, $issuer), $issuers), $publicHost);
        if ($refusal !== null) {
            $withheld[$name] = $refusal;

            return null;
        }

        return $token;
    }

    /**
     * The issuer test: null when every set issuer names the public host alone, else the host or
     * authority to show, or {@see READ_TWO_WAYS}.
     *
     * @param list<?string> $issuers the value of each issuer variable, null when it is unset or empty
     */
    private static function issuerRefusal(array $issuers, string $publicHost): ?string
    {
        foreach ($issuers as $url) {
            if ($url === null) {
                continue;
            }
            // A WHATWG parser strips C0 controls and spaces around the value and every tab or newline
            // in it, and parse_url() does not: https://url.spec.whatwg.org/#concept-basic-url-parser
            $whatwg = str_replace(["\t", "\n", "\r"], '', trim($url, "\x00..\x20"));
            if ($whatwg !== $url) {
                return $whatwg === '' ? self::READ_TWO_WAYS : (self::issuerRefusal([$whatwg], $publicHost) ?? self::READ_TWO_WAYS);
            }
            $bare = self::withoutUserinfo($url);
            $host = self::host($bare);
            // $bare also cuts at an `@` in a path, a query or a fragment, so the host of $url
            // must be the public host too.
            // A backslash ends the authority for a WHATWG parser and not for parse_url(), so the two disagree on the host.
            if ($host !== $publicHost || self::host($url) !== $publicHost || self::hasBackslashInAuthority($url)) {
                // The text before the `@` or the backslash can be userinfo, so it is not shown.
                return $host === $publicHost ? self::READ_TWO_WAYS : ($host ?? self::authorityOf($bare));
            }
        }

        return null;
    }

    /** Userinfo can hold any character, `/` and `://` included, so everything up to the last `@` goes. */
    private static function withoutUserinfo(string $url): string
    {
        $scheme = self::schemeOf($url);
        $at = strrpos($url, '@');

        return $at === false || $at < \strlen($scheme) ? $url : $scheme.substr($url, $at + 1);
    }

    private static function hasBackslashInAuthority(string $url): bool
    {
        return strpos(self::authorityOf($url), '\\') !== false;
    }

    /** An issuer that does not parse is shown up to its path: a query or a fragment can hold a token. */
    private static function authorityOf(string $url): string
    {
        $scheme = self::schemeOf($url);
        $rest = substr($url, \strlen($scheme));
        // A scheme-relative value (`//host/path`) keeps its slashes and shows its host.
        $lead = $scheme === '' && strncmp($rest, '//', 2) === 0 ? '//' : '';
        $rest = substr($rest, \strlen($lead));

        return $scheme.$lead.substr($rest, 0, strcspn($rest, '/?#'));
    }

    private static function schemeOf(string $url): string
    {
        return preg_match('~^[a-z][a-z0-9+.\-]*://~i', $url, $match) === 1 ? $match[0] : '';
    }

    /**
     * Normalised as {@see RepoRef::host()} is, so the port stays. A value without a scheme is a
     * bare host. Null when the value does not parse.
     */
    private static function host(string $url): ?string
    {
        $parts = RepoLocator::hostAndPath(strpos($url, '://') === false ? 'https://'.$url : $url);

        return $parts === null ? null : AsciiCase::lower($parts[0]);
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

    /** Whether `GITHUB_SERVER_URL` or `GH_HOST` names a host other than github.com alone, whether or not a token is set. */
    public function githubIssuerNamesAnotherHost(): bool
    {
        return $this->githubIssuerNamesAnotherHost;
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
     * host, the scheme and authority of an issuer that does not parse, without userinfo, or
     * {@see READ_TWO_WAYS} for an issuer whose host the public host is in one reading only.
     *
     * @return array<string, string>
     */
    public function withheld(): array
    {
        return $this->withheld;
    }
}
