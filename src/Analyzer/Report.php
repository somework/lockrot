<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;

final class Report
{
    /** @var list<Finding> */
    private array $findings;
    /** @var list<string> */
    private array $notes;
    private \DateTimeImmutable $generatedAt;
    private int $packagesChecked;
    private int $notFromComposerRepository;
    private bool $hadNetworkFailures;

    /**
     * @param list<Finding> $findings
     * @param list<string> $notes
     */
    public function __construct(array $findings, array $notes, \DateTimeImmutable $generatedAt, int $packagesChecked, int $notFromComposerRepository, bool $hadNetworkFailures)
    {
        usort($findings, static function (Finding $a, Finding $b): int {
            return [Verdict::severity($b->verdict()), $a->package()] <=> [Verdict::severity($a->verdict()), $b->package()];
        });
        $this->findings = $findings;
        $this->notes = $notes;
        $this->generatedAt = $generatedAt;
        $this->packagesChecked = $packagesChecked;
        $this->notFromComposerRepository = $notFromComposerRepository;
        $this->hadNetworkFailures = $hadNetworkFailures;
    }

    /** @return list<Finding> */
    public function findings(): array
    {
        return $this->findings;
    }

    /** @return list<Finding> */
    public function flagged(): array
    {
        return array_values(array_filter($this->findings, static fn (Finding $f): bool => Verdict::flagged($f->verdict())));
    }

    /** @return array<string, int> */
    public function byVerdict(): array
    {
        $counts = array_fill_keys(Verdict::all(), 0);
        foreach ($this->findings as $finding) {
            ++$counts[$finding->verdict()];
        }

        return $counts;
    }

    /** @return list<string> */
    public function notes(): array
    {
        return $this->notes;
    }
    public function generatedAt(): \DateTimeImmutable
    {
        return $this->generatedAt;
    }
    public function packagesChecked(): int
    {
        return $this->packagesChecked;
    }
    public function notFromComposerRepository(): int
    {
        return $this->notFromComposerRepository;
    }
    public function hadNetworkFailures(): bool
    {
        return $this->hadNetworkFailures;
    }

    /** One-line totals, e.g. `200 packages checked · abandoned 19 · silent 1 · …`, shared by the table and GitHub formats. */
    public function summaryLine(): string
    {
        $parts = [\sprintf('%d packages checked', $this->packagesChecked)];
        foreach ($this->byVerdict() as $verdict => $count) {
            $parts[] = $verdict.' '.$count;
        }

        return implode(' · ', $parts);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt->format(\DATE_ATOM),
            'packages_checked' => $this->packagesChecked,
            'not_from_composer_repository' => $this->notFromComposerRepository,
            'network_failures' => $this->hadNetworkFailures,
            'counts' => $this->byVerdict(),
            'notes' => $this->notes,
            'findings' => array_map(static fn (Finding $f): array => $f->toArray(), $this->findings),
        ];
    }
}
