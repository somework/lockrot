<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

/**
 * What the one advisory lookup of a run did: the scope, each advisory-capable repository in
 * configured order, the repositories that are not advisory-capable, and a record per package name
 * ({@see AdvisoryNameCoverage}). A repository that is not advisory-capable is counted, never named:
 * naming a vcs repository can start network I/O.
 *
 * @internal
 */
final class AdvisoryCoverage
{
    public const SCOPE_ALL = 'all';
    public const SCOPE_COMPOSER_REPOSITORIES = 'composer-repositories';
    public const SCOPES = [self::SCOPE_ALL, self::SCOPE_COMPOSER_REPOSITORIES];
    public const SOURCE_CONFIG = 'config';
    public const SOURCE_DEFAULT = 'default';

    /** A repository's outcome, and a feed's answer for a name (`no_feed` is a repository outcome only). */
    public const ANSWERED = 'answered';
    public const FAILED = 'failed';
    public const NOT_ASKED = 'not_asked';
    public const NO_FEED = 'no_feed';

    /** Why a repository failed: a `TransportException`, or anything else it threw. */
    public const TRANSPORT = 'transport';
    public const INVALID_RESPONSE = 'invalid_response';

    /** Why a repository or a name was not asked, or why a name's lookup is not complete. */
    public const OFFLINE = 'offline';
    public const COMPOSER_TOO_OLD = 'composer_too_old';
    public const INSTALL_TIME_BUDGET = 'install_time_budget';
    public const DISABLED_BY_POLICY = 'disabled_by_policy';
    public const NOT_FROM_COMPOSER_REPOSITORY = 'not_from_composer_repository';
    public const UNPARSEABLE_VERSION = 'unparseable_version';
    public const LOOKUP_FAILED = 'lookup_failed';

    private string $scope;
    private string $scopeSource;
    /** @var list<array{composer_repository: string, outcome: string, reason: ?string, message: ?string, records: ?int, packages_with_records: ?int}> */
    private array $repositories;
    private int $otherRepositories;
    /** @var array<string, AdvisoryNameCoverage> */
    private array $byName;

    /**
     * @param list<array{composer_repository: string, outcome: string, reason: ?string, message: ?string, records: ?int, packages_with_records: ?int}> $repositories
     * @param array<string, AdvisoryNameCoverage>                                                                                                     $byName
     */
    public function __construct(string $scope, string $scopeSource, array $repositories, int $otherRepositories, array $byName)
    {
        $this->scope = $scope;
        $this->scopeSource = $scopeSource;
        $this->repositories = $repositories;
        $this->otherRepositories = $otherRepositories;
        $this->byName = $byName;
    }

    public static function none(): self
    {
        return new self(self::SCOPE_ALL, self::SOURCE_DEFAULT, [], 0, []);
    }

    /** `all` or `composer-repositories`, as `extra.lockrot.advisory-lookup` spells it. */
    public function scope(): string
    {
        return $this->scope;
    }

    /** `config` or `default`. */
    public function scopeSource(): string
    {
        return $this->scopeSource;
    }

    /**
     * `composer_repository` is Composer's name for the repository, credentials stripped.
     * `records` counts the distinct advisory ids returned for every asked name and
     * `packages_with_records` the names with at least one; both null unless `answered`.
     *
     * @return list<array{composer_repository: string, outcome: string, reason: ?string, message: ?string, records: ?int, packages_with_records: ?int}>
     */
    public function repositories(): array
    {
        return $this->repositories;
    }

    public function otherRepositories(): int
    {
        return $this->otherRepositories;
    }

    /** Null for a name the lookup was never given. */
    public function for(string $name): ?AdvisoryNameCoverage
    {
        return $this->byName[$name] ?? null;
    }
}
