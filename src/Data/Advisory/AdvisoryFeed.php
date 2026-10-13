<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

use Lockrot\Lock\PackageOrigin;

/**
 * What one advisory-capable repository did for one package name: report-2's `security.feeds[]`.
 *
 * @internal
 */
final class AdvisoryFeed implements \JsonSerializable
{
    private string $composerRepository;
    private string $answer;
    private ?int $records;
    private ?string $reason;
    private ?string $message;

    private function __construct(string $composerRepository, string $answer, ?int $records, ?string $reason, ?string $message)
    {
        $this->composerRepository = $composerRepository;
        $this->answer = $answer;
        $this->records = $records;
        $this->reason = $reason;
        $this->message = $message;
    }

    /** @param int $records the distinct advisory ids the feed holds for the name, every version, before the ignore lists */
    public static function answered(string $composerRepository, int $records): self
    {
        return new self($composerRepository, AdvisoryCoverage::ANSWERED, $records, null, null);
    }

    /** @param ?string $message the first line of what the repository threw */
    public static function failed(string $composerRepository, ?string $message): self
    {
        return new self($composerRepository, AdvisoryCoverage::FAILED, null, AdvisoryCoverage::LOOKUP_FAILED, $message);
    }

    public static function notAsked(string $composerRepository, string $reason): self
    {
        return new self($composerRepository, AdvisoryCoverage::NOT_ASKED, null, $reason, null);
    }

    public function composerRepository(): string
    {
        return $this->composerRepository;
    }

    public function registry(): ?string
    {
        return PackageOrigin::registryOfRepository($this->composerRepository);
    }

    /** `answered`, `failed` or `not_asked`. */
    public function answer(): string
    {
        return $this->answer;
    }

    /** Null unless `answered`. */
    public function records(): ?int
    {
        return $this->records;
    }

    /** Null exactly when `answered`. */
    public function reason(): ?string
    {
        return $this->reason;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    /** @return array{composer_repository: string, registry: ?string, answer: string, records: ?int, reason: ?string} */
    public function jsonSerialize(): array
    {
        return ['composer_repository' => $this->composerRepository, 'registry' => $this->registry(), 'answer' => $this->answer, 'records' => $this->records, 'reason' => $this->reason];
    }
}
