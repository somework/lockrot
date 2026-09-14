<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Signal\Signal;

final class Finding
{
    private string $package;
    private string $version;
    private string $verdict;
    /** @var list<Signal> */
    private array $signals;
    /** @var list<string> */
    private array $chain;
    private ?string $allowlistReason;
    private ?\DateTimeImmutable $dataDate;
    private ?string $note;

    /**
     * @param list<Signal> $signals
     * @param list<string> $chain
     */
    public function __construct(string $package, string $version, string $verdict, array $signals, array $chain, ?string $allowlistReason, ?\DateTimeImmutable $dataDate, ?string $note = null)
    {
        $this->package = $package;
        $this->version = $version;
        $this->verdict = $verdict;
        $this->signals = $signals;
        $this->chain = $chain;
        $this->allowlistReason = $allowlistReason;
        $this->dataDate = $dataDate;
        $this->note = $note;
    }

    public function package(): string
    {
        return $this->package;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function verdict(): string
    {
        return $this->verdict;
    }

    /** @return list<Signal> */
    public function signals(): array
    {
        return $this->signals;
    }

    /** @return list<string> */
    public function chain(): array
    {
        return $this->chain;
    }

    public function allowlistReason(): ?string
    {
        return $this->allowlistReason;
    }

    public function dataDate(): ?\DateTimeImmutable
    {
        return $this->dataDate;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function isDirect(): bool
    {
        return \count($this->chain) === 1;
    }

    public function evidence(): string
    {
        if ($this->signals === []) {
            return $this->note ?? '';
        }
        $parts = [];
        foreach ($this->signals as $signal) {
            $parts[] = $signal->summary();
        }

        return implode('; ', $parts);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $signals = [];
        foreach ($this->signals as $signal) {
            $signals[] = ['id' => $signal->id(), 'level' => $signal->level(), 'summary' => $signal->summary(), 'data' => $signal->data()];
        }

        return [
            'package' => $this->package, 'version' => $this->version, 'verdict' => $this->verdict,
            'signals' => $signals, 'chain' => $this->chain, 'evidence' => $this->evidence(),
            'allowlist_reason' => $this->allowlistReason, 'note' => $this->note,
            'data_date' => $this->dataDate === null ? null : $this->dataDate->format(\DATE_ATOM),
        ];
    }
}
