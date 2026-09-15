<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Baseline\BaselineComparison;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Priority;
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
    /** Null when the project has no baseline file, which is every run until one is generated. */
    private ?BaselineComparison $baseline;

    /**
     * @param list<Finding> $findings
     * @param list<string> $notes
     */
    public function __construct(array $findings, array $notes, \DateTimeImmutable $generatedAt, int $packagesChecked, int $notFromComposerRepository, bool $hadNetworkFailures, ?BaselineComparison $baseline = null)
    {
        // Priority first, then the verdict's own severity, then direct dependencies ahead of
        // transitive ones, then the package name — a total order, so the report reads the same way on
        // every run. Descending keys take the other finding's value, ascending ones take their own.
        usort($findings, static function (Finding $a, Finding $b): int {
            return [Priority::rank($b->priority()), Verdict::severity($b->verdict()), self::directRank($b), $a->package()]
                <=> [Priority::rank($a->priority()), Verdict::severity($a->verdict()), self::directRank($a), $b->package()];
        });
        $this->findings = $findings;
        $this->notes = $notes;
        $this->generatedAt = $generatedAt;
        $this->packagesChecked = $packagesChecked;
        $this->notFromComposerRepository = $notFromComposerRepository;
        $this->hadNetworkFailures = $hadNetworkFailures;
        $this->baseline = $baseline;
    }

    /** Sort weight of the third ordering key: a direct dependency outranks a transitive one. */
    private static function directRank(Finding $finding): int
    {
        return $finding->isDirect() ? 1 : 0;
    }

    /**
     * The same report, seen next to the project's baseline. A new instance rather than a mutation,
     * so a caller that already handed the report somewhere else keeps the report it handed over.
     */
    public function withBaseline(BaselineComparison $baseline): self
    {
        return new self(
            $this->findings,
            $this->notes,
            $this->generatedAt,
            $this->packagesChecked,
            $this->notFromComposerRepository,
            $this->hadNetworkFailures,
            $baseline
        );
    }

    public function baseline(): ?BaselineComparison
    {
        return $this->baseline;
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

    /** @return array<string, int> */
    public function byPriority(): array
    {
        $counts = array_fill_keys(Priority::all(), 0);
        foreach ($this->findings as $finding) {
            ++$counts[$finding->priority()];
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

    /**
     * The four flagged levels, e.g. `priority: critical 2 · high 12 · medium 5 · low 3`. `none` is left
     * out: it counts the rows the report does not flag, which {@see summaryLine()} already totals.
     */
    public function prioritySummaryLine(): string
    {
        $counts = $this->byPriority();
        $parts = [];
        foreach ([Priority::CRITICAL, Priority::HIGH, Priority::MEDIUM, Priority::LOW] as $level) {
            $parts[] = $level.' '.$counts[$level];
        }

        return 'priority: '.implode(' · ', $parts);
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
            'priorities' => $this->byPriority(),
            'baseline' => $this->baseline === null ? null : $this->baseline->toArray(),
            'notes' => $this->notes,
            'findings' => array_map(static fn (Finding $f): array => $f->toArray(), $this->findings),
        ];
    }
}
