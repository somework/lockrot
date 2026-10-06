<?php

declare(strict_types=1);

namespace Lockrot\Baseline;

use Lockrot\Analyzer\Report;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;

/**
 * A run's findings compared with the committed baseline: docs/baseline.md, "Reading a baseline".
 *
 * @internal
 */
final class BaselineComparison
{
    public const KNOWN = 'known';
    /** Named with a suffix because `new` alone reads as the keyword at every call site. */
    public const NEW_FINDING = 'new';
    public const WORSENED = 'worsened';

    private const STALE_NAMES_IN_NOTE = 5;

    private string $path;
    /** @var array<string, string> package name => one of KNOWN / NEW_FINDING / WORSENED */
    private array $statuses;
    /** @var array<string, string> package name => the verdict the baseline accepted it at */
    private array $previousVerdicts;
    /** @var list<string> baselined package names absent from the analysed lock, sorted */
    private array $stale;

    /**
     * @param array<string, string> $statuses
     * @param array<string, string> $previousVerdicts
     * @param list<string>          $stale
     */
    private function __construct(string $path, array $statuses, array $previousVerdicts, array $stale)
    {
        $this->path = $path;
        $this->statuses = $statuses;
        $this->previousVerdicts = $previousVerdicts;
        $this->stale = $stale;
    }

    /**
     * @param list<string> $presentPackages every package name in the lock, `ok` ones included: a
     *                                      baselined `ok` package is present, not stale
     */
    public static function compare(Baseline $baseline, Report $report, string $displayPath, array $presentPackages): self
    {
        $statuses = [];
        $previousVerdicts = [];
        foreach ($report->flagged() as $finding) {
            $entry = $baseline->entryFor($finding->package());
            if ($entry === null) {
                $statuses[$finding->package()] = self::NEW_FINDING;

                continue;
            }
            $statuses[$finding->package()] = self::statusOfEntry($entry, $finding);
            $previousVerdicts[$finding->package()] = $entry->verdict();
        }

        $present = array_fill_keys($presentPackages, true);
        // No sort: Baseline::packages() returns name order and the filter keeps it.
        $stale = [];
        foreach ($baseline->packages() as $package) {
            if (!isset($present[$package])) {
                $stale[] = $package;
            }
        }

        return new self($displayPath, $statuses, $previousVerdicts, $stale);
    }

    private static function statusOfEntry(BaselineEntry $entry, Finding $finding): string
    {
        return Verdict::severity($finding->verdict()) > Verdict::severity($entry->verdict())
            ? self::WORSENED
            : self::KNOWN;
    }

    public function isKnown(string $package): bool
    {
        return ($this->statuses[$package] ?? null) === self::KNOWN;
    }

    /** Null when the package is not a flagged finding of this run. */
    public function statusOf(string $package): ?string
    {
        return $this->statuses[$package] ?? null;
    }

    public function previousVerdictOf(string $package): ?string
    {
        return $this->previousVerdicts[$package] ?? null;
    }

    public function knownCount(): int
    {
        return $this->countOf(self::KNOWN);
    }

    public function newCount(): int
    {
        return $this->countOf(self::NEW_FINDING);
    }

    public function worsenedCount(): int
    {
        return $this->countOf(self::WORSENED);
    }

    private function countOf(string $status): int
    {
        return \count(array_filter($this->statuses, static fn (string $value): bool => $value === $status));
    }

    /** @return list<string> */
    public function stale(): array
    {
        return $this->stale;
    }

    public function summaryLine(): string
    {
        return \sprintf(
            'baseline: %d known · %d new · %d worsened · %d stale (%s)',
            $this->knownCount(),
            $this->newCount(),
            $this->worsenedCount(),
            \count($this->stale),
            $this->path
        );
    }

    public function staleNote(): ?string
    {
        $total = \count($this->stale);
        if ($total === 0) {
            return null;
        }

        $names = \array_slice($this->stale, 0, self::STALE_NAMES_IN_NOTE);
        $listed = implode(', ', $names);
        if ($total > \count($names)) {
            $listed .= \sprintf(' … and %d more', $total - \count($names));
        }

        return \sprintf(
            'baseline lists %d %s no longer in composer.lock: %s',
            $total,
            $total === 1 ? 'package' : 'packages',
            $listed
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'known' => $this->knownCount(),
            'new' => $this->newCount(),
            'worsened' => $this->worsenedCount(),
            'stale' => $this->stale,
        ];
    }
}
