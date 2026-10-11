<?php

declare(strict_types=1);

namespace Lockrot\Signal\Data;

use Lockrot\Security\Fix;

/**
 * One counted advisory of S9: `signals[].data.advisories[]` (docs/verdicts.md#security-advisories).
 *
 * @internal
 */
final class AdvisoryRow implements \JsonSerializable
{
    private string $id;
    private ?string $cve;
    private ?string $title;
    private ?string $link;
    private ?string $reportedAt;
    private string $severity;
    private ?string $severityPublished;
    private ?string $affectedVersions;
    private int $points;
    private Fix $fix;
    private bool $deciding = false;

    /**
     * @param ?string $reportedAt        an ISO 8601 date and time
     * @param string  $severity          the severity bucket
     * @param ?string $severityPublished the repository's own word
     */
    public function __construct(string $id, ?string $cve, ?string $title, ?string $link, ?string $reportedAt, string $severity, ?string $severityPublished, ?string $affectedVersions, int $points, Fix $fix)
    {
        $this->id = $id;
        $this->cve = $cve;
        $this->title = $title;
        $this->link = $link;
        $this->reportedAt = $reportedAt;
        $this->severity = $severity;
        $this->severityPublished = $severityPublished;
        $this->affectedVersions = $affectedVersions;
        $this->points = $points;
        $this->fix = $fix;
    }

    /** A copy that the score's security term counts, or does not. */
    public function withDeciding(bool $deciding): self
    {
        $clone = clone $this;
        $clone->deciding = $deciding;

        return $clone;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function cve(): ?string
    {
        return $this->cve;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function severity(): string
    {
        return $this->severity;
    }

    public function points(): int
    {
        return $this->points;
    }

    public function fix(): Fix
    {
        return $this->fix;
    }

    public function isDeciding(): bool
    {
        return $this->deciding;
    }

    /** @return array{id: string, cve: ?string, title: ?string, link: ?string, reported_at: ?string, severity: string, severity_published: ?string, affected_versions: ?string, counted: true, points: int, deciding: bool, fix: Fix, baseline: null} */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'cve' => $this->cve,
            'title' => $this->title,
            'link' => $this->link,
            'reported_at' => $this->reportedAt,
            'severity' => $this->severity,
            'severity_published' => $this->severityPublished,
            'affected_versions' => $this->affectedVersions,
            'counted' => true,
            'points' => $this->points,
            'deciding' => $this->deciding,
            'fix' => $this->fix,
            'baseline' => null,
        ];
    }
}
