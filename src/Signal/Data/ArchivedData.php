<?php

declare(strict_types=1);

namespace Lockrot\Signal\Data;

use Lockrot\Signal\Signal;

/** @internal */
final class ArchivedData implements SignalData
{
    private string $repo;
    private string $host;
    private string $forge;

    public function __construct(string $repo, string $host, string $forge)
    {
        $this->repo = $repo;
        $this->host = $host;
        $this->forge = $forge;
    }

    public function signalId(): string
    {
        return Signal::S3;
    }

    public function forge(): string
    {
        return $this->forge;
    }

    /** @return array{repo: string, host: string, forge: string} */
    public function jsonSerialize(): array
    {
        return ['repo' => $this->repo, 'host' => $this->host, 'forge' => $this->forge];
    }
}
