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
    private int $notOnPackagist;
    private bool $hadNetworkFailures;

    /**
     * @param list<Finding> $findings
     * @param list<string> $notes
     */
    public function __construct(array $findings, array $notes, \DateTimeImmutable $generatedAt, int $packagesChecked, int $notOnPackagist, bool $hadNetworkFailures)
    {
        usort($findings, static function (Finding $a, Finding $b): int {
            return [Verdict::severity($b->verdict()), $a->package()] <=> [Verdict::severity($a->verdict()), $b->package()];
        });
        $this->findings = $findings;
        $this->notes = $notes;
        $this->generatedAt = $generatedAt;
        $this->packagesChecked = $packagesChecked;
        $this->notOnPackagist = $notOnPackagist;
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
    public function notOnPackagist(): int
    {
        return $this->notOnPackagist;
    }
    public function hadNetworkFailures(): bool
    {
        return $this->hadNetworkFailures;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt->format(\DATE_ATOM),
            'packages_checked' => $this->packagesChecked,
            'not_on_packagist' => $this->notOnPackagist,
            'network_failures' => $this->hadNetworkFailures,
            'counts' => $this->byVerdict(),
            'notes' => $this->notes,
            'findings' => array_map(static fn (Finding $f): array => $f->toArray(), $this->findings),
        ];
    }
}
