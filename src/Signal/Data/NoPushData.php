<?php

declare(strict_types=1);

namespace Lockrot\Signal\Data;

use Lockrot\Signal\Signal;

/** @internal */
final class NoPushData implements SignalData
{
    private string $lastPush;
    private string $repo;
    private string $host;
    private ?float $years;
    private string $forge;
    private string $event;

    /**
     * @param string $lastPush an ISO 8601 date and time
     * @param string $event the event that the repository host dates, `push` or `commit`
     */
    public function __construct(string $lastPush, string $repo, string $host, ?float $years, string $forge, string $event)
    {
        $this->lastPush = $lastPush;
        $this->repo = $repo;
        $this->host = $host;
        $this->years = $years;
        $this->forge = $forge;
        $this->event = $event;
    }

    public function signalId(): string
    {
        return Signal::S4;
    }

    public function lastPush(): string
    {
        return $this->lastPush;
    }

    public function years(): ?float
    {
        return $this->years;
    }

    public function event(): string
    {
        return $this->event;
    }

    /** @return array{last_push: string, repo: string, host: string, years: ?float, forge: string, event: string} */
    public function jsonSerialize(): array
    {
        return ['last_push' => $this->lastPush, 'repo' => $this->repo, 'host' => $this->host, 'years' => $this->years, 'forge' => $this->forge, 'event' => $this->event];
    }
}
