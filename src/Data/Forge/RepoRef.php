<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

/**
 * The path is `owner/repo` on GitHub and Bitbucket, and `group/sub/project` on GitLab, where
 * subgroups nest.
 *
 * @internal
 */
final class RepoRef
{
    public const GITHUB = 'github';
    public const GITLAB = 'gitlab';
    public const BITBUCKET = 'bitbucket';

    /** Every repository host, in the order that the report's notes name them. */
    public const FORGES = [self::GITHUB, self::GITLAB, self::BITBUCKET];

    private const LABEL = [self::GITHUB => 'GitHub', self::GITLAB => 'GitLab', self::BITBUCKET => 'Bitbucket'];

    /** GitLab and Bitbucket have no push date: the newest commit on any branch is the closest. */
    private const ACTIVITY = [self::GITHUB => 'last push', self::GITLAB => 'last commit', self::BITBUCKET => 'last commit'];
    /** What the activity date measures: GitHub reports the last push, GitLab and Bitbucket the last commit. */
    private const EVENT = [self::GITHUB => 'push', self::GITLAB => 'commit', self::BITBUCKET => 'commit'];

    private string $forge;
    private string $host;
    private string $path;

    /**
     * @param string $host `github.com`, `bitbucket.org` or an entry of `gitlab-domains`, which can
     *                     carry a port or a path prefix (`gitlab.example.com/gitlab`)
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

    /** Unique across repository hosts: `github.com/owner/repo`. */
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

    /** `push` or `commit`: S4's `activity`. */
    public function event(): string
    {
        return self::EVENT[$this->forge];
    }

    public function activityWording(): string
    {
        return self::ACTIVITY[$this->forge];
    }
}
