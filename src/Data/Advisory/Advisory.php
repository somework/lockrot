<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;

/**
 * A security advisory that affects an installed version, as S9 carries it
 * (docs/verdicts.md#security-advisories). A partial Composer record sets every field to null
 * except the id and the affected range.
 *
 * @internal
 */
final class Advisory
{
    private string $id;
    private ?string $cve;
    private ?string $title;
    private ?string $link;
    private ?string $severity;
    private ?\DateTimeImmutable $reportedAt;
    private ?ConstraintInterface $affectedVersions;

    public function __construct(string $id, ?string $cve, ?string $title, ?string $link, ?string $severity, ?\DateTimeImmutable $reportedAt, ?ConstraintInterface $affectedVersions = null)
    {
        $this->id = $id;
        $this->cve = $cve;
        $this->title = $title;
        $this->link = $link;
        $this->severity = $severity;
        $this->reportedAt = $reportedAt;
        $this->affectedVersions = $affectedVersions;
    }

    public static function fromComposer(PartialSecurityAdvisory $advisory): self
    {
        if (!$advisory instanceof SecurityAdvisory) {
            return new self($advisory->advisoryId, null, null, null, null, null, $advisory->affectedVersions);
        }

        return new self($advisory->advisoryId, $advisory->cve, $advisory->title, $advisory->link, $advisory->severity, $advisory->reportedAt, $advisory->affectedVersions);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function cve(): ?string
    {
        return $this->cve;
    }

    public function label(): string
    {
        return $this->cve ?? $this->id;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function link(): ?string
    {
        return $this->link;
    }

    public function severity(): ?string
    {
        return $this->severity;
    }

    public function reportedAt(): ?\DateTimeImmutable
    {
        return $this->reportedAt;
    }

    /**
     * Null when the advisory gives no affected range.
     *
     * @param string $normalized such as `3.4.47.0`
     */
    public function affects(string $normalized): ?bool
    {
        if ($this->affectedVersions === null) {
            return null;
        }

        return $this->affectedVersions->matches(new Constraint('==', $normalized));
    }

    /** @return array{id: string, cve: ?string, title: ?string, link: ?string, severity: ?string, reported_at: ?string, affected_versions: ?string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'cve' => $this->cve,
            'title' => $this->title,
            'link' => $this->link,
            'severity' => $this->severity,
            'reported_at' => $this->reportedAt === null ? null : $this->reportedAt->format(\DATE_ATOM),
            'affected_versions' => $this->affectedVersions === null ? null : $this->affectedVersions->getPrettyString(),
        ];
    }
}
