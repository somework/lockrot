<?php

declare(strict_types=1);

namespace Lockrot\Signal\Data;

use Lockrot\Signal\PhpCap;
use Lockrot\Signal\Signal;

/**
 * S11's data: the installed `require.php`, where it stops, and the release that admits R
 * (docs/verdicts.md#capped).
 *
 * @internal
 */
final class CappedData implements SignalData
{
    private string $newestPhp;
    private string $newestPhpSource;
    private string $requires;
    private string $stopsAt;
    private bool $excludesTarget;
    /** @var ?array{version: string, branch: string, blocking: ?string} */
    private ?array $admitsNewest;

    /**
     * @param PhpCap::KNOWN|PhpCap::REQUIRE_PHP|PhpCap::PLATFORM_PHP $newestPhpSource
     * @param ?array{version: string, branch: string, blocking: ?string} $admitsNewest `blocking` is a
     *        {@see \Lockrot\Signal\PhpFloor::blocking()} value; null when no release admits R or the metadata was not read
     */
    public function __construct(string $newestPhp, string $newestPhpSource, string $requires, string $stopsAt, bool $excludesTarget, ?array $admitsNewest)
    {
        $this->newestPhp = $newestPhp;
        $this->newestPhpSource = $newestPhpSource;
        $this->requires = $requires;
        $this->stopsAt = $stopsAt;
        $this->excludesTarget = $excludesTarget;
        $this->admitsNewest = $admitsNewest;
    }

    public function signalId(): string
    {
        return Signal::S11;
    }

    public function requires(): string
    {
        return $this->requires;
    }

    public function stopsAt(): string
    {
        return $this->stopsAt;
    }

    /** @return ?array{version: string, branch: string, blocking: ?string} */
    public function admitsNewest(): ?array
    {
        return $this->admitsNewest;
    }

    /** The R words, with no trailing comma: "the newest PHP, 8.5". */
    public function newestWords(): string
    {
        switch ($this->newestPhpSource) {
            case PhpCap::REQUIRE_PHP:
                return $this->newestPhp.', the newest PHP that require.php allows';
            case PhpCap::PLATFORM_PHP:
                return $this->newestPhp.", the newest PHP that the lock's platform allows";
            default:
                return 'the newest PHP, '.$this->newestPhp;
        }
    }

    /** @return array{newest_php: string, newest_php_source: string, requires: string, stops_at: string, excludes_target: bool, admits_newest: ?array{version: string, branch: string, blocking: ?string}} */
    public function jsonSerialize(): array
    {
        return [
            'newest_php' => $this->newestPhp,
            'newest_php_source' => $this->newestPhpSource,
            'requires' => $this->requires,
            'stops_at' => $this->stopsAt,
            'excludes_target' => $this->excludesTarget,
            'admits_newest' => $this->admitsNewest,
        ];
    }
}
