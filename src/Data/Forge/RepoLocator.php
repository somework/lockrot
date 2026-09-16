<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

use Composer\Config;

/**
 * Turns a package's source URL into a {@see RepoRef}, or null when the URL points somewhere lockrot
 * does not ask about activity.
 *
 * `github.com` and `bitbucket.org` are fixed. GitLab hosts come from Composer's own
 * `gitlab-domains` setting (`gitlab.com` by default), so a self-hosted instance the project already
 * installs from is recognised without any lockrot configuration of its own. A domain may carry a
 * path prefix (`gitlab.example.com/gitlab`, a GitLab in a non-root context), matched the way
 * Composer matches it. GitHub Enterprise (`github-domains`) is not recognised: its API base and
 * authentication path differ from github.com's and nothing has asked for it yet.
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
        $parts = self::split($url);
        if ($parts === null) {
            return null;
        }
        [$host, $path] = $parts;
        $lowerHost = strtolower($host);
        if ($lowerHost === 'www.github.com') {
            // Composer's GitHubDriver reads the www. form as github.com; so does lockrot.
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
     * Host (with its port, when the URL names one) and path of a clone URL in any of the shapes a
     * lock file records: `https://host/path.git`, `https://user@host/path`, `git://host/path`,
     * `ssh://git@host/path.git` and the scp-like `git@host:path.git`. The slash-separated
     * `git@host/path.git` is not a URL git accepts, but locks have carried it and earlier lockrot
     * versions read it, so it stays readable; a bare `host/path` without the user part does not.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function split(string $url): ?array
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
     * Host and port are matched the way Composer's GitLabDriver matches a `gitlab-domains` entry
     * against a clone URL ({@see \Composer\Repository\Vcs\GitLabDriver::determineOrigin()}): the
     * entry matches the URL's host with its port, or its bare host when the entry names no port.
     * A URL that omits a port the entry spells out is not that GitLab for Composer, so not for
     * lockrot either. The ref's host is the URL's host, port included, plus the entry's path prefix
     * — Composer's origin for the URL, which is both the API base and the key Composer files the
     * credentials under. (So a `gitlab.com:443` URL is not `gitlab.com` to {@see ForgeAuth} and gets
     * no `GITLAB_TOKEN`; Composer's origin has the same quirk.)
     *
     * @param string $domain    a `gitlab-domains` entry: `host`, `host:port` or `host[:port]/prefix`
     * @param string $lowerHost the URL's host, lowercased, with its port when it has one
     */
    private static function gitlab(string $domain, string $lowerHost, string $path): ?RepoRef
    {
        $slash = strpos($domain, '/');
        $domainHost = strtolower($slash === false ? $domain : substr($domain, 0, $slash));
        $prefix = $slash === false ? '' : substr($domain, $slash);
        if ($lowerHost !== $domainHost) {
            $bareHost = (string) preg_replace('{:\d+$}', '', $lowerHost);
            if ($bareHost === $lowerHost || $bareHost !== $domainHost) {
                return null;
            }
        }
        $path = self::normalise($path);
        if ($prefix !== '') {
            if (strpos($path, rtrim($prefix, '/').'/') !== 0) {
                return null;
            }
            $path = substr($path, \strlen($prefix));
        }
        $path = ltrim($path, '/');
        // Two segments at least; a segment that is exactly `-` starts GitLab's web routes
        // (`/group/project/-/tree/main`), which is a page, not a clone URL.
        if (preg_match('{^'.self::SEGMENT.'(?:/'.self::SEGMENT.')+$}', $path) !== 1 || \in_array('-', explode('/', $path), true)) {
            return null;
        }

        return new RepoRef(RepoRef::GITLAB, $lowerHost.rtrim($prefix, '/'), $path);
    }

    /** Without a trailing slash or `.git`, with one leading slash. */
    private static function normalise(string $path): string
    {
        $path = rtrim($path, '/');
        if (substr($path, -4) === '.git') {
            $path = substr($path, 0, -4);
        }

        return '/'.ltrim($path, '/');
    }
}
