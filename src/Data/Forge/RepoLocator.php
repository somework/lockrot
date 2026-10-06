<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

use Composer\Config;

/**
 * Turns a clone URL, or a `support.source` that {@see SupportSource} reduced, into a {@see RepoRef}.
 * It returns null for a host that lockrot does not ask: docs/internals.md, "Which host is asked".
 *
 * GitLab hosts come from Composer's `gitlab-domains`. A domain can carry a path prefix, matched as
 * Composer matches it. GitHub Enterprise is not recognised: its API base and authentication
 * differ from github.com's.
 *
 * @internal
 */
final class RepoLocator
{
    private const SEGMENT = '[A-Za-z0-9_.-]+';

    /** @var list<string> */
    private array $gitlabDomains;

    /** @param list<string> $gitlabDomains as Composer's `gitlab-domains` lists them */
    public function __construct(array $gitlabDomains = ['gitlab.com'])
    {
        $this->gitlabDomains = $gitlabDomains;
    }

    public static function fromConfig(Config $config): self
    {
        $domains = $config->get('gitlab-domains');
        $out = [];
        if (\is_array($domains)) {
            foreach ($domains as $domain) {
                if (\is_string($domain) && $domain !== '') {
                    $out[] = $domain;
                }
            }
        }

        return new self($out);
    }

    public function locate(?string $url): ?RepoRef
    {
        if ($url === null || $url === '') {
            return null;
        }
        $parts = self::hostAndPath($url);
        if ($parts === null) {
            return null;
        }
        [$host, $path] = $parts;
        $lowerHost = strtolower($host);
        if ($lowerHost === 'www.github.com') {
            // Composer's GitHubDriver reads the www. form as github.com, and so does lockrot.
            $lowerHost = 'github.com';
        }
        if ($lowerHost === 'github.com') {
            return self::twoSegments(RepoRef::GITHUB, 'github.com', $path);
        }
        if ($lowerHost === 'bitbucket.org') {
            return self::twoSegments(RepoRef::BITBUCKET, 'bitbucket.org', $path);
        }
        foreach ($this->gitlabDomains as $domain) {
            $ref = self::gitlab($domain, $lowerHost, $path);
            if ($ref !== null) {
                return $ref;
            }
        }

        return null;
    }

    /**
     * Host (with its port, when the URL names one) and path of a clone URL in any shape that a lock
     * records: `https://host/path.git`, `https://user@host/path`, `git://host/path`,
     * `ssh://git@host/path.git` and the scp-like `git@host:path.git`. The slash form
     * `git@host/path.git` is no URL that git accepts, but locks carry it, so it stays readable. A
     * bare `host/path` without the user part is not readable.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function hostAndPath(string $url): ?array
    {
        if (strpos($url, '://') !== false) {
            $host = parse_url($url, \PHP_URL_HOST);
            $path = parse_url($url, \PHP_URL_PATH);
            if (!\is_string($host) || $host === '' || !\is_string($path)) {
                return null;
            }
            $port = parse_url($url, \PHP_URL_PORT);

            return [\is_int($port) ? $host.':'.$port : $host, $path];
        }
        if (preg_match('{^(?:[A-Za-z0-9_.-]+@)?([A-Za-z0-9_.-]+):(.+)$}', $url, $m) === 1) {
            return [$m[1], $m[2]];
        }
        if (preg_match('{^[A-Za-z0-9_.-]+@([A-Za-z0-9_.-]+)/(.+)$}', $url, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return null;
    }

    private static function twoSegments(string $forge, string $host, string $path): ?RepoRef
    {
        if (preg_match('{^/?('.self::SEGMENT.')/('.self::SEGMENT.')$}', self::normalise($path), $m) !== 1) {
            return null;
        }

        return new RepoRef($forge, $host, $m[1].'/'.$m[2]);
    }

    /**
     * Matched as Composer's GitLabDriver matches a `gitlab-domains` entry against a clone URL
     * ({@see \Composer\Repository\Vcs\GitLabDriver::determineOrigin()}): the entry matches the URL's
     * host with its port, or its bare host when the entry names no port. The ref's host is
     * Composer's origin for the URL: the API base and the key of its credentials. So a
     * `gitlab.com:443` URL is not `gitlab.com` to {@see ForgeAuth}, and gets no `GITLAB_TOKEN`.
     *
     * @param string $domain    a `gitlab-domains` entry: `host`, `host:port` or `host[:port]/prefix`
     * @param string $lowerHost the URL's host, lowercased, with its port when it has one
     */
    private static function gitlab(string $domain, string $lowerHost, string $path): ?RepoRef
    {
        $parts = explode('/', $domain, 2);
        $domainHost = strtolower($parts[0]);
        $prefix = isset($parts[1]) ? rtrim('/'.$parts[1], '/') : '';
        if ($lowerHost !== $domainHost) {
            $bareHost = (string) preg_replace('{:\d+$}', '', $lowerHost);
            if ($bareHost === $lowerHost || $bareHost !== $domainHost) {
                return null;
            }
        }
        $path = self::normalise($path);
        if ($prefix !== '') {
            if (strpos($path, $prefix.'/') !== 0) {
                return null;
            }
            $path = substr($path, \strlen($prefix));
        }
        $path = ltrim($path, '/');
        // Two segments at least. A segment that is exactly `-` starts GitLab's web routes
        // (`/group/project/-/tree/main`), a page and no clone URL.
        if (preg_match('{^'.self::SEGMENT.'(?:/'.self::SEGMENT.')+$}', $path) !== 1 || \in_array('-', explode('/', $path), true)) {
            return null;
        }

        return new RepoRef(RepoRef::GITLAB, $lowerHost.$prefix, $path);
    }

    private static function normalise(string $path): string
    {
        $path = rtrim($path, '/');
        if (substr($path, -4) === '.git') {
            $path = substr($path, 0, -4);
        }

        return '/'.ltrim($path, '/');
    }
}
