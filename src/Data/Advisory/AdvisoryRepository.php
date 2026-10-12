<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

use Lockrot\Data\Repository\RepositoryUrl;
use Lockrot\Lock\PackageOrigin;

/**
 * One advisory-capable repository of the run and what it did: report-2's
 * `run.advisory_lookup.repositories[]`.
 *
 * @internal
 */
final class AdvisoryRepository implements \JsonSerializable
{
    public const ANSWERED = AdvisoryCoverage::ANSWERED;
    public const FAILED = AdvisoryCoverage::FAILED;
    public const NOT_ASKED = AdvisoryCoverage::NOT_ASKED;
    public const NO_FEED = AdvisoryCoverage::NO_FEED;

    private string $composerRepository;
    private string $outcome;
    private ?string $reason;
    private ?string $message;
    private ?int $records = null;
    private ?int $packagesWithRecords = null;

    private function __construct(string $composerRepository, string $outcome, ?string $reason, ?string $message)
    {
        $this->composerRepository = $composerRepository;
        $this->outcome = $outcome;
        $this->reason = $reason;
        $this->message = $message;
    }

    /** @param string $name Composer's name for the repository, which can carry credentials: {@see RepositoryUrl::inText()} strips them */
    public static function of(string $name, string $outcome, ?string $reason = null, ?string $message = null): self
    {
        return new self(RepositoryUrl::inText($name), $outcome, $reason, $message);
    }

    /**
     * @param int $records             the distinct advisory ids returned for every asked name
     * @param int $packagesWithRecords the asked names with at least one
     */
    public function withRecords(int $records, int $packagesWithRecords): self
    {
        $clone = clone $this;
        $clone->records = $records;
        $clone->packagesWithRecords = $packagesWithRecords;

        return $clone;
    }

    public function composerRepository(): string
    {
        return $this->composerRepository;
    }

    public function registry(): ?string
    {
        return PackageOrigin::registryOfRepository($this->composerRepository);
    }

    /** `answered`, `failed`, `not_asked` or `no_feed`. */
    public function outcome(): string
    {
        return $this->outcome;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    /** Null unless `answered`. */
    public function records(): ?int
    {
        return $this->records;
    }

    /** Null unless `answered`. */
    public function packagesWithRecords(): ?int
    {
        return $this->packagesWithRecords;
    }

    /** @return array{composer_repository: string, registry: ?string, outcome: string, reason: ?string, message: ?string, packages_with_records: ?int, records: ?int} */
    public function jsonSerialize(): array
    {
        return [
            'composer_repository' => $this->composerRepository,
            'registry' => $this->registry(),
            'outcome' => $this->outcome,
            'reason' => $this->reason,
            'message' => $this->message,
            'packages_with_records' => $this->packagesWithRecords,
            'records' => $this->records,
        ];
    }
}
