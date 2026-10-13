<?php

declare(strict_types=1);

namespace Lockrot\Data\Forge;

/**
 * What the repository activity lookup did for one package: report-2's `finding.activity`
 * (docs/schema.md). It holds one reason, chosen by the precedence of
 * docs/verdicts.md#what-was-not-checked.
 *
 * @internal
 */
final class ActivityLookup implements \JsonSerializable
{
    public const READ = 'read';
    public const NOT_FOUND = 'not_found';
    public const NOT_READ = 'not_read';

    public const ALLOWLISTED = 'allowlisted';
    public const NOT_FROM_COMPOSER_REPOSITORY = 'not_from_composer_repository';
    public const NO_REPOSITORY = LocatedRepository::NO_REPOSITORY;
    public const HOST_NOT_SUPPORTED = LocatedRepository::HOST_NOT_SUPPORTED;
    public const REPOSITORY_NOT_FOUND = 'repository_not_found';

    /** Why a read left the archived flag unread: no token asks for the project document, or its request failed. */
    public const NO_TOKEN = ActivityFetchPlan::NO_TOKEN;
    public const FETCH_FAILED = 'fetch_failed';

    private string $status;
    private ?string $reason;
    private LocatedRepository $where;
    private ?RepositoryActivity $activity;
    private ?string $archivedUnread;

    private function __construct(string $status, ?string $reason, LocatedRepository $where, ?RepositoryActivity $activity, ?string $archivedUnread)
    {
        $this->status = $status;
        $this->reason = $reason;
        $this->where = $where;
        $this->activity = $activity;
        $this->archivedUnread = $archivedUnread;
    }

    public static function read(RepositoryActivity $activity): self
    {
        $unread = $activity->archived() !== null ? null : ($activity->archivedRequestFailed() ? self::FETCH_FAILED : self::NO_TOKEN);

        return new self(self::READ, null, LocatedRepository::located($activity->ref()), $activity, $unread);
    }

    public static function notFound(RepoRef $ref): self
    {
        return new self(self::NOT_FOUND, self::REPOSITORY_NOT_FOUND, LocatedRepository::located($ref), null, null);
    }

    public static function notRead(string $reason, LocatedRepository $where): self
    {
        return new self(self::NOT_READ, $reason, $where, null, null);
    }

    public function status(): string
    {
        return $this->status;
    }

    /** Null exactly when the activity was read. */
    public function reason(): ?string
    {
        return $this->reason;
    }

    public function where(): LocatedRepository
    {
        return $this->where;
    }

    public function activity(): ?RepositoryActivity
    {
        return $this->activity;
    }

    /** The event that the host reports (`push` or `commit`), null when nothing was read. */
    public function event(): ?string
    {
        return $this->activity === null ? null : $this->activity->ref()->event();
    }

    /** Set exactly when a read answer did not carry the archived flag. */
    public function archivedUnread(): ?string
    {
        return $this->archivedUnread;
    }

    /**
     * @return array{status: string, reason: ?string, forge: ?string, host: ?string, repo: ?string, event: ?string, archived: ?bool, fetched_at: ?string, cached_at: ?string}
     */
    public function jsonSerialize(): array
    {
        $ref = $this->where->ref();
        $activity = $this->activity;

        return [
            'status' => $this->status,
            'reason' => $this->reason,
            'forge' => $ref === null ? null : $ref->forge(),
            'host' => $this->where->host(),
            'repo' => $ref === null ? null : $ref->path(),
            'event' => $this->event(),
            'archived' => $activity === null ? null : $activity->archived(),
            'fetched_at' => $activity === null ? null : $activity->fetchedAt()->format(\DATE_ATOM),
            'cached_at' => $activity === null || $activity->cachedAt() === null ? null : $activity->cachedAt()->format(\DATE_ATOM),
        ];
    }
}
