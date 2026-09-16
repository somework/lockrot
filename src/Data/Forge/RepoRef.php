<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

/**
 * A source repository on one of the hosts lockrot can ask about activity: which forge, which host,
 * and the repository's path there (`owner/repo` on GitHub and Bitbucket, `group/sub/project` on
 * GitLab, where subgroups nest).
 */
final class RepoRef
{
    public const GITHUB = 'github';
    public const GITLAB = 'gitlab';
    public const BITBUCKET = 'bitbucket';

    /** Every forge, in the order the report's notes name them. */
    public const FORGES = [self::GITHUB, self::GITLAB, self::BITBUCKET];

    private const LABEL = [self::GITHUB => 'GitHub', self::GITLAB => 'GitLab', self::BITBUCKET => 'Bitbucket'];

    /**
     * What the S4 date measures on each forge. GitHub reports the last push to any branch; GitLab
     * and Bitbucket are asked for the newest commit across all branches, which is the closest
     * observable equivalent and is named for what it is.
     */
    private const ACTIVITY = [self::GITHUB => 'last push', self::GITLAB => 'last commit', self::BITBUCKET => 'last commit'];

    private string $forge;
    private string $host;
    private string $path;

    /**
     * @param string $host the host as Composer names it in its own configuration: `github.com`,
     *                     `bitbucket.org`, or an entry of `gitlab-domains` (which may carry a
     *                     port or a path prefix, `gitlab.example.com/gitlab`)
     */
    public function __construct(string $forge, string $host, string $path)
    {
        $this->forge = $forge;
        $this->host = $host;
        $this->path = $path;
    }

    public function forge(): string
    {
        return $this->forge;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** One string per repository, across forges: `github.com/owner/repo`. */
    public function key(): string
    {
        return $this->host.'/'.$this->path;
    }

    public function forgeLabel(): string
    {
        return self::label($this->forge);
    }

    public static function label(string $forge): string
    {
        return self::LABEL[$forge];
    }

    /** The wording of the S4 signal on this forge: `last push` or `last commit`. */
    public function activityWording(): string
    {
        return self::ACTIVITY[$this->forge];
    }
}
