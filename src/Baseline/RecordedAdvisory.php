<?php

declare(strict_types=1);

namespace Lockrot\Baseline;

/**
 * One counted advisory that a baseline entry knows, as of the date it first became known.
 *
 * @internal
 *
 * @phpstan-type Shape array{known_since: string, severity: string, fix_kind: string}
 */
final class RecordedAdvisory implements \JsonSerializable
{
    private string $id;
    private string $knownSince;
    private string $severity;
    private string $fixKind;

    /**
     * @param string $severity the bucket, as the last regeneration read it
     * @param string $fixKind  the fix kind, as the last regeneration read it
     */
    public function __construct(string $id, string $knownSince, string $severity, string $fixKind)
    {
        $this->id = $id;
        $this->knownSince = $knownSince;
        $this->severity = $severity;
        $this->fixKind = $fixKind;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function knownSince(): string
    {
        return $this->knownSince;
    }

    public function severity(): string
    {
        return $this->severity;
    }

    public function fixKind(): string
    {
        return $this->fixKind;
    }

    /** @return Shape */
    public function jsonSerialize(): array
    {
        return ['known_since' => $this->knownSince, 'severity' => $this->severity, 'fix_kind' => $this->fixKind];
    }
}
